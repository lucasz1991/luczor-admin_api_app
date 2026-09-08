<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Task;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowTrigger;
use App\Models\WorkflowTriggerDelivery;
use App\Services\ApiActor;
use App\Services\AutomationGrantService;
use App\Services\WorkflowAuthoringService;
use App\Services\WorkflowEventService;
use App\Services\WorkflowTriggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkflowTriggerController extends Controller
{
    public function sources(Request $request, ApiActor $actor)
    {
        $data = $request->validate(['project_id' => 'nullable|string|max:190']);
        $project = $actor->project($request, $data['project_id'] ?? null);
        abort_unless(empty($data['project_id']) || ($project && (int) $project->user_id === (int) $request->user()->id), 404);
        $userId = (int) $request->user()->id;

        return response()->json(['data' => [
            'repositories' => Repository::where('user_id', $userId)->where('project_id', $project?->id)->orderBy('full_name')->limit(500)->get(['id', 'full_name']),
            'tasks' => Task::where('user_id', $userId)->where('project_ref_id', $project?->id)->orderBy('title')->limit(500)->get(['id', 'title']),
            'workflows' => WorkflowDefinition::where('user_id', $userId)->where('project_id', $project?->id)->orderBy('name')->limit(500)->get(['id', 'name']),
        ]]);
    }

    public function index(Request $request, WorkflowDefinition $workflowDefinition)
    {
        $this->owned($request, $workflowDefinition);

        return response()->json(['data' => WorkflowTrigger::where('workflow_definition_id', $workflowDefinition->id)->orderBy('id')->get()]);
    }

    public function store(Request $request, WorkflowDefinition $workflowDefinition, WorkflowTriggerService $service, WorkflowAuthoringService $operations)
    {
        $this->owned($request, $workflowDefinition);
        $data = $this->triggerData($request);
        $result = $operations->operate((int) $request->user()->id, $data['operation_id'] ?? null, 'trigger.create', ['workflow_id' => $workflowDefinition->id] + $data, fn () => $service->save($workflowDefinition, $data));

        return response()->json(['data' => $result], 201);
    }

    public function update(Request $request, WorkflowTrigger $workflowTrigger, WorkflowTriggerService $service, WorkflowAuthoringService $operations)
    {
        $this->owned($request, $workflowTrigger);
        $data = $this->triggerData($request, true);
        $merged = $data + $workflowTrigger->only(['name', 'kind', 'enabled', 'config', 'input']);
        $result = $operations->operate((int) $request->user()->id, $data['operation_id'] ?? null, 'trigger.update', ['trigger_id' => $workflowTrigger->id] + $data, fn () => $service->save($workflowTrigger->definition, $merged, $workflowTrigger));

        return response()->json(['data' => $result]);
    }

    public function destroy(Request $request, WorkflowTrigger $workflowTrigger)
    {
        $this->owned($request, $workflowTrigger);
        DB::transaction(function () use ($workflowTrigger) {
            $workflowTrigger->update(['enabled' => false]);
            WorkflowTriggerDelivery::where('workflow_trigger_id', $workflowTrigger->id)->whereIn('status', ['pending', 'waiting', 'overflow'])->update(['status' => 'cancelled', 'finished_at' => now(), 'last_error' => 'Trigger removed.']);
            $workflowTrigger->delete();
        });

        return response()->json(['data' => ['id' => $workflowTrigger->id, 'deleted' => true]]);
    }

    public function deliveries(Request $request, WorkflowTrigger $workflowTrigger)
    {
        $this->owned($request, $workflowTrigger);

        return response()->json(['data' => WorkflowTriggerDelivery::where('workflow_trigger_id', $workflowTrigger->id)->latest('id')->limit(100)->get()]);
    }

    public function retryDelivery(Request $request, WorkflowTriggerDelivery $workflowTriggerDelivery)
    {
        $this->owned($request, $workflowTriggerDelivery->trigger);
        abort_unless(in_array($workflowTriggerDelivery->status, ['overflow', 'failed'], true), 409, 'Only retained overflow or failed deliveries can be replayed.');
        // A failed run may already have performed effects. Replay is explicit and obtains a new run snapshot.
        $workflowTriggerDelivery->update(['workflow_run_id' => null, 'status' => 'pending', 'available_at' => now(), 'finished_at' => null, 'last_error' => null]);

        return response()->json(['data' => $workflowTriggerDelivery->fresh()]);
    }

    public function automation(Request $request, WorkflowDefinition $workflowDefinition, AutomationGrantService $grants)
    {
        $this->owned($request, $workflowDefinition);

        return response()->json(['data' => ['grant' => $grants->current($workflowDefinition)]]);
    }

    public function configureAutomation(Request $request, WorkflowDefinition $workflowDefinition, ApiActor $actor, AutomationGrantService $grants, WorkflowAuthoringService $operations)
    {
        $this->owned($request, $workflowDefinition);
        $data = $request->validate([
            'status' => 'required|in:active,revoked', 'operation_id' => 'required|uuid', 'local_approved' => 'required|accepted',
            'device_id' => 'required|string|max:120', 'project_external_id' => 'nullable|string|max:190',
            'root_path' => 'required|string|max:2000', 'approved_revision' => 'required|integer|min:1',
            'allowed_tasks' => 'required|array|min:1|max:100', 'allowed_tasks.*' => 'required|string|max:100',
            'allowed_input_sources' => 'present|array|max:3', 'allowed_input_sources.*' => 'in:input,event,steps',
            'allowed_output_keys' => 'present|array|max:100', 'allowed_output_keys.*' => 'string|max:120',
            'egress_hosts' => 'present|array|max:100', 'egress_hosts.*' => ['string', 'max:260', 'regex:/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?$/'],
            'export_results' => 'required|boolean', 'max_steps' => 'sometimes|integer|min:1|max:800',
            'max_runs_per_hour' => 'sometimes|integer|min:1|max:1000', 'max_input_bytes' => 'sometimes|integer|min:1|max:1048576',
            'max_output_bytes' => 'sometimes|integer|min:1|max:1048576', 'script_hashes' => 'present|array|max:100',
            'script_hashes.*' => 'string|regex:/^[a-f0-9]{64}$/',
        ]);
        $data['local_approved'] = $request->boolean('local_approved');
        $deviceId = $actor->deviceId($request, $data['device_id'], true);
        $result = $operations->operate((int) $request->user()->id, $data['operation_id'], 'grant.configure', ['workflow_id' => $workflowDefinition->id] + $data,
            fn () => ['grant' => $grants->configure($workflowDefinition, $data, (int) $request->user()->id, $deviceId)->toArray()]);
        if (($result['grant']['config']['script_hashes'] ?? null) === []) {
            $result['grant']['config']['script_hashes'] = (object) [];
        }

        return response()->json(['data' => $result]);
    }

    public function deviceTriggers(Request $request, ApiActor $actor)
    {
        $data = $request->validate(['device_id' => 'required|string|max:120']);
        $deviceId = $actor->deviceId($request, $data['device_id'], true);
        $triggers = WorkflowTrigger::where('user_id', $request->user()->id)->where('enabled', true)->where('kind', 'workspace.file_changed')->get()
            ->filter(fn (WorkflowTrigger $trigger) => ($trigger->config['device_id'] ?? null) === $deviceId)
            ->map(function (WorkflowTrigger $trigger) {
                return $trigger->toArray() + ['project_external_id' => Project::find($trigger->project_id)?->external_id];
            })->values();

        return response()->json(['data' => $triggers]);
    }

    public function fileEvent(Request $request, ApiActor $actor, WorkflowTriggerService $triggers, WorkflowEventService $events)
    {
        $data = $request->validate([
            'trigger_id' => 'required|integer|min:1', 'event_id' => 'required|uuid', 'device_id' => 'required|string|max:120',
            'root_path' => 'required|string|max:2000', 'occurred_at' => 'required|date', 'changes' => 'required|array|min:1|max:128',
            'origin_run_id' => 'nullable|uuid',
            'changes.*' => 'array:path,kind', 'changes.*.path' => 'present|nullable|string|max:500',
            'changes.*.kind' => 'required|in:created,modified,deleted,rescan',
        ]);
        abort_unless(array_diff(array_keys($request->all()), array_keys($data)) === [], 422, 'File events accept metadata only.');
        $deviceId = $actor->deviceId($request, $data['device_id'], true);
        abort_unless(Device::where('user_id', $request->user()->id)->where('device_id', $deviceId)->whereNull('revoked_at')->exists(), 403, 'Device is unavailable.');
        $trigger = WorkflowTrigger::whereKey($data['trigger_id'])->where('user_id', $request->user()->id)->where('kind', 'workspace.file_changed')->where('enabled', true)->firstOrFail();
        abort_unless(($trigger->config['device_id'] ?? null) === $deviceId && AutomationGrantService::canonicalRoot($data['root_path']) === $trigger->config['root_path'], 403, 'File event scope does not match its subscription.');
        foreach ($data['changes'] as &$change) {
            if ($change['kind'] === 'rescan' && ($change['path'] === '' || $change['path'] === null)) {
                $change['path'] = '.';
            }
            abort_unless(is_string($change['path']) && $triggers->safeRelativePath($change['path']), 422, 'File event path escapes its root.');
            $matches = $change['kind'] === 'rescan' && $change['path'] === '.';
            $windows = preg_match('/^[a-z]:\//', $trigger->config['root_path']) === 1 || str_starts_with($trigger->config['root_path'], '//');
            foreach ($trigger->config['paths'] as $glob) {
                $matches = $matches || $triggers->globMatches($glob, $change['path'], $windows);
            }
            foreach ($trigger->config['excludes'] ?? [] as $glob) {
                if ($triggers->globMatches($glob, $change['path'], $windows)) {
                    $matches = false;
                }
            }
            abort_unless($matches, 422, 'File event is outside the watched paths.');
        }
        unset($change);
        $causation = [];
        if (! empty($data['origin_run_id'])) {
            $origin = WorkflowRun::where('public_id', $data['origin_run_id'])->where('user_id', $trigger->user_id)->where('project_id', $trigger->project_id)->firstOrFail();
            $causation = $origin->context['_execution'] ?? [];
            abort_unless(($causation['device_id'] ?? null) === $deviceId
                && AutomationGrantService::canonicalRoot((string) ($causation['root_path'] ?? $causation['grant']['config']['root_path'] ?? '')) === $trigger->config['root_path'], 403, 'File event origin does not match its device and root.');
        }
        $event = $events->record((int) $trigger->user_id, $trigger->project_id, 'workspace.file_changed', 'device:'.$deviceId, $data['event_id'], [
            '_trigger_id' => $trigger->id, 'device_id' => $deviceId, 'changes' => $data['changes'],
        ], $causation, $data['occurred_at']);

        return response()->json(['data' => ['event_id' => $event->public_id, 'status' => $event->wasRecentlyCreated ? 'accepted' : 'duplicate']], 202);
    }

    public function hook(Request $request, string $publicId, WorkflowEventService $events)
    {
        abort_unless(strlen($request->getContent()) <= 65500, 413, 'Webhook payload exceeds 64 KiB.');
        $trigger = WorkflowTrigger::where('public_id', $publicId)->where('kind', 'webhook')->where('enabled', true)->firstOrFail();
        $secret = (string) $request->header('X-Workflow-Secret');
        abort_unless($secret !== '' && hash_equals((string) $trigger->secret_hash, hash('sha256', $secret)), 401, 'Invalid workflow hook secret.');
        $eventId = trim((string) $request->header('X-Workflow-Event-ID'));
        abort_unless(preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $eventId), 422, 'A stable workflow event ID is required.');
        abort_unless($request->isJson(), 415, 'Webhook requires a JSON object.');
        $payload = $request->json()->all();
        $event = $events->record((int) $trigger->user_id, $trigger->project_id, 'webhook', 'webhook:'.$trigger->id, $eventId, [
            '_trigger_id' => $trigger->id, 'data' => $payload,
        ]);

        return response()->json(['data' => ['event_id' => $event->public_id, 'status' => $event->wasRecentlyCreated ? 'accepted' : 'duplicate']], 202);
    }

    private function triggerData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => $required.'|string|max:160', 'kind' => $required.'|in:'.implode(',', WorkflowTriggerService::KINDS),
            'enabled' => 'sometimes|boolean', 'config' => ($partial ? 'sometimes' : 'present').'|array', 'input' => 'sometimes|array',
            'operation_id' => 'nullable|uuid', 'rotate_secret' => 'sometimes|boolean',
        ]);
    }

    private function owned(Request $request, mixed $resource): void
    {
        abort_unless($resource && (int) $resource->user_id === (int) $request->user()?->id, 404);
    }
}
