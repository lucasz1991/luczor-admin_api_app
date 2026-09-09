<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Project;
use App\Models\WorkflowAutomationGrant;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRepairRevision;
use App\Models\WorkflowRun;
use App\Models\WorkflowTestEvidence;
use Illuminate\Support\Facades\DB;

/** Standing consent is a bounded capability envelope, never permission supplied by event data. */
class AutomationGrantService
{
    public function current(WorkflowDefinition $definition): ?WorkflowAutomationGrant
    {
        return WorkflowAutomationGrant::where('workflow_definition_id', $definition->id)->where('status', '!=', 'testing')->latest('id')->first();
    }

    public function configure(WorkflowDefinition $definition, array $data, int $userId, string $deviceId): WorkflowAutomationGrant
    {
        abort_unless((int) $definition->user_id === $userId && hash_equals($deviceId, $data['device_id']), 403, 'Automation approval must originate on the selected owned device.');
        abort_unless(Device::where('user_id', $userId)->where('device_id', $deviceId)->whereNull('revoked_at')->exists(), 403, 'Automation device is unavailable.');
        abort_unless(($data['local_approved'] ?? false) === true, 422, 'Complete local approval is required.');
        $projectExternalId = $definition->project_id ? Project::find($definition->project_id)?->external_id : null;
        abort_unless(($data['project_external_id'] ?? null) === $projectExternalId, 422, 'Automation project does not match the workflow.');
        $config = array_intersect_key($data, array_flip([
            'device_id', 'project_external_id', 'root_path', 'allowed_tasks', 'allowed_input_sources',
            'allowed_output_keys', 'egress_hosts', 'max_steps', 'max_runs_per_hour', 'max_input_bytes',
            'max_output_bytes', 'script_hashes', 'export_results', 'approved_revision',
        ]));
        $config += ['max_steps' => 100, 'max_runs_per_hour' => 20, 'max_input_bytes' => 65536, 'max_output_bytes' => 65536, 'script_hashes' => [], 'export_results' => false];
        foreach (['allowed_tasks', 'allowed_input_sources', 'allowed_output_keys', 'egress_hosts'] as $field) {
            $config[$field] = array_values(array_unique($config[$field] ?? []));
            sort($config[$field]);
        }
        $config['root_path'] = self::canonicalRoot($config['root_path']);
        $hashConfig = $config;
        if (($hashConfig['script_hashes'] ?? null) === []) {
            $hashConfig['script_hashes'] = (object) [];
        }
        $scopeHash = hash('sha256', self::canonicalJson($hashConfig));

        return DB::transaction(function () use ($definition, $data, $userId, $deviceId, $config, $scopeHash) {
            WorkflowDefinition::whereKey($definition->id)->lockForUpdate()->firstOrFail();
            if ($existing = WorkflowAutomationGrant::where('user_id', $userId)->where('operation_id', $data['operation_id'])->first()) {
                abort_unless((int) $existing->workflow_definition_id === (int) $definition->id && $existing->scope_hash === $scopeHash && $existing->status === $data['status'], 409, 'Automation operation ID was already used for different data.');

                return $existing;
            }

            return WorkflowAutomationGrant::create([
                'user_id' => $userId, 'workflow_definition_id' => $definition->id, 'project_id' => $definition->project_id,
                'device_id' => $deviceId, 'approved_revision' => $data['approved_revision'], 'status' => $data['status'],
                'config' => $config, 'scope_hash' => $scopeHash, 'operation_id' => $data['operation_id'],
            ]);
        });
    }

    public function authorizeRun(WorkflowDefinition $definition, array $graph, array $executionContext): array
    {
        $steps = $this->collectSteps($graph);
        $deviceSteps = array_filter($steps, fn (array $step) => WorkflowTaskCatalog::isClientTask((string) ($step['type'] ?? '')) || in_array($step['type'] ?? '', ['device_job', 'llm.local'], true));
        if ($deviceSteps === []) {
            return [];
        }
        $grant = $this->current($definition);
        abort_unless($grant && $grant->status === 'active', 409, 'needs_automation_approval');
        $config = $grant->config;
        abort_unless(($executionContext['device_id'] ?? null) === $grant->device_id, 409, 'Automation device exceeds the standing approval.');
        abort_unless((int) $grant->project_id === (int) $definition->project_id, 409, 'Automation project exceeds the standing approval.');
        abort_unless(count($steps) <= $config['max_steps'], 409, 'Automation step budget requires new approval.');
        abort_unless(! isset($executionContext['root_path']) || self::canonicalRoot((string) $executionContext['root_path']) === $config['root_path'], 409, 'Automation root exceeds the standing approval.');
        foreach ($steps as $step) {
            $this->assertTask($grant, (string) ($step['type'] ?? ''), $step['payload'] ?? [], ($step['_definition_id'] ?? '').':'.($step['key'] ?? ''), false);
        }
        $activeHour = WorkflowRun::where('workflow_definition_id', $definition->id)->where('created_at', '>=', now()->subHour())->count();
        abort_unless($activeHour < $config['max_runs_per_hour'], 409, 'automation_hourly_budget');
        abort_unless(strlen(self::canonicalJson($executionContext['event'] ?? [])) <= $config['max_input_bytes'], 409, 'Automation event exceeds the approved input budget.');

        return $grant->toArray();
    }

    public function authorizeTask(WorkflowRun $run, string $taskType, array $resolvedPayload, ?\App\Models\WorkflowStep $step = null): array
    {
        $execution = $run->context['_execution'] ?? [];
        if (empty($execution['automatic'])) {
            return [];
        }
        $grantData = $execution['grant'] ?? [];
        $definition = WorkflowDefinition::findOrFail($grantData['workflow_definition_id'] ?? $run->workflow_definition_id);
        $grant = $this->current($definition);
        $requested = WorkflowAutomationGrant::find($grantData['id'] ?? 0);
        if ($requested?->status === 'testing') {
            $binding = $requested->config['test_binding'] ?? [];
            $evidence = WorkflowTestEvidence::find($binding['test_evidence_id'] ?? 0);
            $repair = WorkflowRepairRevision::find($binding['repair_revision_id'] ?? 0);
            abort_unless($run->test_mode === 'real' && ! $run->sandbox && $evidence?->status === 'running' && $repair?->status === 'proposed'
                && (int) $evidence->user_id === (int) $run->user_id
                && ($binding['run'] ?? null) === ($execution['_test_run_id'] ?? null)
                && (int) $evidence->workflow_run_id === (int) ($run->root_workflow_run_id ?: $run->id)
                && (int) $requested->predecessor_grant_id === (int) $grant?->id && $grant?->status === 'active'
                && ($definition->repair_policy ?? null) === ($repair->scope['policy'] ?? null)
                && hash_equals($repair->definition_hash, $binding['definition_hash'] ?? '')
                && hash_equals($evidence->environment_hash, $binding['environment_hash'] ?? ''), 409, 'workflow_testing_grant_binding_invalid');
            $grant = $requested;
        } else {
            // Only an explicitly authorized repair lineage preserves a frozen older run's grant.
            $cursor = $grant;
            for ($depth = 0; $cursor && $depth < 3 && (int) $cursor->id !== (int) ($grantData['id'] ?? 0); $depth++) {
                $cursor = $cursor->status === 'active' && $cursor->predecessor_grant_id && $cursor->test_evidence_id
                    ? WorkflowAutomationGrant::find($cursor->predecessor_grant_id) : null;
            }
            abort_unless($cursor && $cursor->status === 'active' && (int) $cursor->id === (int) ($grantData['id'] ?? 0), 409, 'Automation approval was revoked or replaced.');
            $grant = $cursor;
        }
        abort_unless(Device::where('user_id', $run->user_id)->where('device_id', $grant->device_id)->whereNull('revoked_at')->exists(), 409, 'Automation device was revoked.');
        abort_if($step !== null && (int) $step->workflow_run_id !== (int) $run->id, 409, 'workflow_grant_step_identity_mismatch');
        $stepKey = $step ? $run->workflow_definition_id.':'.$step->step_key : (string) ($resolvedPayload['_step_key'] ?? '');
        $this->assertTask($grant, $taskType, $resolvedPayload, $stepKey, true);

        return $grant->toArray();
    }

    private function assertTask(WorkflowAutomationGrant $grant, string $type, array $payload, string $stepKey, bool $resolved): void
    {
        $config = $grant->config;
        abort_unless(in_array($type, $config['allowed_tasks'], true), 409, "Automation action {$type} requires new approval.");
        abort_unless(strlen(self::canonicalJson($payload)) <= $config['max_input_bytes'], 409, 'Automation input budget exceeded.');
        if (! $resolved) {
            foreach ($payload['input_bindings'] ?? [] as $reference) {
                $source = explode('.', (string) $reference)[0];
                abort_unless(in_array($source, $config['allowed_input_sources'], true), 409, 'Automation input source requires new approval.');
            }
        }
        $walk = function (mixed $value) use (&$walk, $config): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['source']) || isset($value['$ref'])) {
                $source = explode('.', (string) ($value['source'] ?? $value['$ref']))[0];
                if (in_array($source, ['input', 'event', 'steps'], true)) {
                    abort_unless(in_array($source, $config['allowed_input_sources'], true), 409, 'Automation input source requires new approval.');
                }
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        if (! $resolved) {
            $walk($payload);
        }
        foreach (['root_path', 'project_dir', 'workspace_root_id', 'workspace_root_path'] as $rootField) {
            if (isset($payload[$rootField]) && is_string($payload[$rootField])) {
                abort_unless(self::canonicalRoot($payload[$rootField]) === $config['root_path'], 409, 'Automation root requires new approval.');
            }
        }
        if (isset($payload['device_id'])) {
            abort_unless($payload['device_id'] === $grant->device_id, 409, 'Automation device requires new approval.');
        }
        if (isset($payload['url']) && is_string($payload['url'])) {
            $host = strtolower((string) parse_url($payload['url'], PHP_URL_HOST));
            $port = parse_url($payload['url'], PHP_URL_PORT);
            $scheme = strtolower((string) parse_url($payload['url'], PHP_URL_SCHEME));
            if ($port && ! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
                $host .= ':'.$port;
            }
            abort_unless($host !== '' && in_array($host, $config['egress_hosts'], true), 409, 'Automation network target requires new approval.');
        }
        if (in_array($type, ['python.run', 'node.run', 'agent.dispatch'], true)) {
            $text = $payload[$type === 'agent.dispatch' ? 'prompt' : 'code'] ?? null;
            // Dynamic code/prompt bindings must be approved for their actual bytes at dispatch.
            if ($resolved || is_string($text)) {
                $hash = is_string($text) ? hash('sha256', $text) : '';
                abort_unless($hash !== '' && in_array($hash, array_values($config['script_hashes'] ?? []), true), 409, 'Changed script or agent prompt requires new approval.');
                $legacyKey = str_contains($stepKey, ':') ? explode(':', $stepKey, 2)[1] : $stepKey;
                $pinned = $config['script_hashes'][$stepKey] ?? $config['script_hashes'][$legacyKey] ?? null;
                if ($stepKey !== '' && $pinned !== null) {
                    abort_unless(hash_equals($pinned, $hash), 409, 'Changed script or agent prompt requires new approval.');
                }
            }
        }
    }

    private function collectSteps(array $graph): array
    {
        return WorkflowSnapshotIdentity::steps($graph);
    }

    public function assertGraph(WorkflowAutomationGrant $grant, array $graph): void
    {
        $steps = $this->collectSteps($graph);
        abort_unless(count($steps) <= ($grant->config['max_steps'] ?? 100), 409, 'workflow_grant_step_budget');
        foreach ($steps as $step) {
            $this->assertTask($grant, $step['type'], $step['payload'] ?? [], ($step['_definition_id'] ?? '').':'.$step['key'], false);
        }
    }

    public static function canonicalRoot(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', trim($path)), '/');

        return preg_match('/^[A-Za-z]:\//', $path) || str_starts_with($path, '//') ? strtolower($path) : $path;
    }

    public static function canonicalJson(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function configHash(array $config): string
    {
        if (($config['script_hashes'] ?? null) === []) {
            $config['script_hashes'] = (object) [];
        }

        return hash('sha256', self::canonicalJson($config));
    }
}
