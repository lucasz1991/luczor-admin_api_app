<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowOperation;
use Illuminate\Support\Facades\DB;

/** Canonical write boundary shared by the desktop API, tools and board editor. */
class WorkflowAuthoringService
{
    public function __construct(private WorkflowDefinitionValidator $validator) {}

    public function projectId(int $userId, ?string $externalId): ?int
    {
        if ($externalId === null || trim($externalId) === '') {
            return null;
        }
        $project = Project::where('user_id', $userId)->where('external_id', $externalId)->first();
        abort_unless($project !== null, 422, 'The selected project_id is invalid.');

        return (int) $project->id;
    }

    public function assertOwned(int $userId, WorkflowDefinition $definition): void
    {
        abort_unless((int) $definition->user_id === $userId, 404);
    }

    public function validate(int $userId, array $definition, ?int $projectId = null, ?int $definitionId = null): array
    {
        $steps = $this->validator->validate($definition);
        foreach ($steps as $step) {
            if ($step['type'] !== 'workflow') {
                continue;
            }
            $child = WorkflowDefinition::findOrFail($step['payload']['workflow_definition_id']);
            $this->snapshot($child, $userId, $projectId, $definitionId ? [$definitionId] : [], false);
        }
        $count = 0;
        $this->controlSnapshots(['definition_id' => $definitionId, 'revision_id' => null, 'version' => 1, 'definition' => $definition], $userId, $projectId, $definitionId ? [$definitionId] : [], false, $count);

        return $steps;
    }

    /**
     * Resolve all nested definitions now; execution never consults mutable child JSON.
     *
     * @param-out int $nodeCount
     */
    public function snapshot(WorkflowDefinition $definition, int $userId, ?int $projectId, array $ancestors = [], bool $requireActive = true, ?int &$nodeCount = null): array
    {
        $nodeCount ??= 0;
        abort_if(++$nodeCount > 100, 422, 'Expanded workflow exceeds the definition limit.');
        $this->assertOwned($userId, $definition);
        abort_if(count($ancestors) >= 8 || in_array($definition->id, $ancestors, true), 422, 'Nested workflow cycle or depth limit exceeded.');
        abort_if($definition->project_id !== null && (int) $definition->project_id !== $projectId, 422, 'Nested workflow belongs to another project.');
        abort_if($requireActive && $definition->status !== 'active', 409, 'The workflow is disabled.');
        $steps = $this->validator->validate($definition->definition ?? []);
        $children = [];
        foreach ($steps as $step) {
            if ($step['type'] === 'workflow') {
                $child = WorkflowDefinition::query()->lockForUpdate()->findOrFail($step['payload']['workflow_definition_id']);
                $children[$step['key']] = $this->snapshot($child, $userId, $projectId, [...$ancestors, $definition->id], $requireActive, $nodeCount);
            }
        }

        $snapshot = [
            'definition_id' => $definition->id, 'revision_id' => $definition->current_revision_id,
            'version' => (int) $definition->version, 'name' => $definition->name,
            'project_id' => $definition->project_id, 'definition' => $definition->definition, 'children' => $children,
        ];
        $snapshot['controls'] = $this->controlSnapshots($snapshot, $userId, $projectId, [...$ancestors, $definition->id], $requireActive, $nodeCount);

        return WorkflowSnapshotIdentity::annotate($snapshot);
    }

    public function controlSnapshots(array $base, int $userId, ?int $projectId, array $ancestors, bool $active, int &$count, int $depth = 0): array
    {
        abort_if($depth > 8, 422, 'Workflow control depth limit exceeded.');
        $controls = [];
        foreach ($base['definition']['steps'] ?? [] as $step) {
            $bodies = match ($step['type'] ?? '') {
                'control.foreach', 'control.until' => [$step['payload']['body']],
                'control.parallel' => $step['payload']['branches'],
                default => [],
            };
            foreach ($bodies as $index => $body) {
                abort_if(++$count > 100, 422, 'Expanded workflow exceeds the definition limit.');
                $snapshot = array_merge($base, ['definition' => array_merge(['thinking_tier' => $base['definition']['thinking_tier'] ?? 'balanced'], $body, ['schema_version' => 2]), 'children' => [], 'controls' => []]);
                foreach ($this->validator->validate($snapshot['definition']) as $nested) {
                    if ($nested['type'] === 'workflow') {
                        $definition = WorkflowDefinition::query()->lockForUpdate()->findOrFail($nested['payload']['workflow_definition_id']);
                        $snapshot['children'][$nested['key']] = $this->snapshot($definition, $userId, $projectId, $ancestors, $active, $count);
                    }
                }
                $snapshot['controls'] = $this->controlSnapshots($snapshot, $userId, $projectId, $ancestors, $active, $count, $depth + 1);
                $controls[$step['key']][$index] = $snapshot;
            }
        }

        return $controls;
    }

    public function save(int $userId, array $data, ?int $definitionId = null): array
    {
        return $this->operate($userId, $data['operation_id'] ?? null, $definitionId ? 'update' : 'create', $data + ['definition_id' => $definitionId], function () use ($userId, $data, $definitionId) {
            $definition = $definitionId ? WorkflowDefinition::query()->lockForUpdate()->findOrFail($definitionId) : null;
            if ($definition) {
                $this->assertOwned($userId, $definition);
                abort_if($definition->is_edit_locked, 409, 'The workflow is locked or embedded.');
                abort_unless((int) ($data['expected_version'] ?? 0) === (int) $definition->version, 409, 'workflow_version_conflict');
            }
            $projectId = array_key_exists('project_id', $data)
                ? $this->projectId($userId, $data['project_id']) : $definition?->project_id;
            $this->validate($userId, $data['definition'], $projectId, $definitionId);
            $attributes = [
                'user_id' => $userId, 'project_id' => $projectId, 'name' => $data['name'],
                'definition' => $data['definition'], 'status' => $data['status'] ?? $definition->status ?? 'active',
                'version' => $definition ? $definition->version + 1 : 1,
                'change_summary' => $data['change_summary'] ?? null,
            ];
            if ($definition) {
                $definition->update($attributes);
            } else {
                $definition = WorkflowDefinition::create($attributes);
            }

            return $this->serialize($definition->fresh(), true);
        });
    }

    /** The user lock serializes duplicate operation keys before any external work. */
    public function operate(int $userId, ?string $operationId, string $action, array $request, callable $callback): array
    {
        return DB::transaction(function () use ($userId, $operationId, $action, $request, $callback) {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode($this->canonical($request), JSON_THROW_ON_ERROR));
            if ($operationId && ($existing = WorkflowOperation::where('user_id', $userId)->where('operation_id', $operationId)->first())) {
                abort_unless($existing->action === $action && hash_equals($existing->request_hash, $hash), 409, 'workflow_operation_conflict');

                return $existing->response;
            }
            $response = $callback();
            if ($operationId) {
                WorkflowOperation::create(['user_id' => $userId, 'operation_id' => $operationId, 'action' => $action, 'request_hash' => $hash, 'response' => $response]);
            }

            return $response;
        });
    }

    public function serialize(WorkflowDefinition $definition, bool $withRevisions = false): array
    {
        $data = $definition->only(['id', 'name', 'version', 'current_revision_id', 'status', 'is_locked', 'project_id', 'definition', 'change_summary', 'created_at', 'updated_at']);
        $data['project_external_id'] = $definition->project?->external_id;
        $data['is_edit_locked'] = $definition->is_edit_locked;
        $data['repair_policy'] = $definition->repair_policy;
        if ($withRevisions) {
            $data['revisions'] = $definition->revisions()->orderByDesc('version')->limit(100)->get(['id', 'version', 'name', 'definition_hash', 'change_summary', 'created_at'])->toArray();
        }

        return $data;
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
