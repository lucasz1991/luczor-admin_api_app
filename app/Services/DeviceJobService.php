<?php

namespace App\Services;

use App\Events\DeviceJobCreated;
use App\Models\AgentRun;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\User;
use App\Models\WebWorkspaceChat;
use App\Models\WorkflowStep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Creates the only permitted class of remote device work: signed, fixed profiles. */
class DeviceJobService
{
    /** Browser control-plane requests remain strictly inside the account, including administrators. */
    public function createForAccount(User $user, Device $device, array $payload): DeviceJob
    {
        abort_unless($user->isActive(), 403);
        abort_unless((int) $device->user_id === (int) $user->id, 404);
        $request = Request::create('/account/workspace', 'POST');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('account_workspace_dispatch', true);

        return $this->create($request, [
            'device_id' => $device->device_id,
            'tool_profile' => 'workspace.chat',
            'payload' => array_merge($payload, ['user_id' => (int) $user->id, 'device_id' => $device->device_id]),
        ]);
    }

    /** @param array{device_id:string,project_id?:string|null,agent_run_id?:int|null,tool_profile:string,payload?:array<string,mixed>} $data */
    public function create(Request $request, array $data): DeviceJob
    {
        abort_if($data['tool_profile'] === 'workflow.task', 422, 'Workflow bundles can only be issued by the persistent workflow scheduler.');
        $actor = app(ApiActor::class);
        $tools = app(DeviceToolPolicy::class);
        $signer = app(DeviceJobSigner::class);
        $audit = app(AuditLogger::class);
        $actorUserId = $actor->userId($request);

        // Once selected, only this user's master may delegate to other devices.
        // A device can always submit work for itself; web workflows keep their own authorization.
        $masterId = $request->user()->master_device_id;
        if ($masterId && ! $request->attributes->get('account_workspace_dispatch', false)) {
            $sourceId = $actor->deviceId($request, null, true);
            $source = Device::where('device_id', $sourceId)->where('user_id', $actorUserId)->whereNull('revoked_at')->firstOrFail();
            abort_unless($data['device_id'] === $sourceId || (int) $source->id === (int) $masterId, 403, 'Nur das Master-Gerät darf Aufträge an andere Geräte delegieren.');
        }

        $device = Device::query()->where('device_id', $data['device_id'])->firstOrFail();
        $tool = $data['tool_profile'];
        if ($tool === 'workspace.chat' || ! $request->user()?->isAdmin()) {
            abort_unless((int) $device->user_id === $actorUserId, 404);
        }
        abort_if($device->revoked_at, 409, 'The target device is revoked.');

        if ($tool === 'workspace.chat') {
            abort_if(! empty($data['project_id']) || ! empty($data['agent_run_id']), 422, 'Web chats cannot inherit a project or agent-run context.');
            $chatReference = validator($data['payload'] ?? [], ['chat_id' => ['required', 'integer', 'min:1']])->validate();
            $chat = WebWorkspaceChat::where('user_id', $actorUserId)->findOrFail($chatReference['chat_id']);
            abort_unless(($data['payload']['scope'] ?? null) === $chat->scope, 422, 'The chat scope cannot be changed by a device job.');
            // API/MCP callers cannot bind a signed chat to somebody else's account or device.
            $data['payload']['user_id'] = $actorUserId;
            $data['payload']['device_id'] = $device->device_id;
        }

        $project = $actor->project($request, $data['project_id'] ?? null);
        if (! empty($data['agent_run_id'])) {
            $run = AgentRun::findOrFail($data['agent_run_id']);
            $actor->assertOwned($request, $run);
        }

        $payload = $tools->normalize($tool, $data['payload'] ?? []);
        $risk = $tools->risk($tool, $payload);
        $ownerUserId = (int) $device->user_id;
        $requiresApproval = $tools->requiresLocalApproval($ownerUserId, $project?->id, $device, $tool);
        $payloadHash = $audit->hash($payload);
        $job = DeviceJob::create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $ownerUserId,
            'project_id' => $project?->id,
            'device_id' => $device->id,
            'agent_run_id' => $data['agent_run_id'] ?? null,
            'tool_profile' => $tool,
            'status' => $requiresApproval ? 'approval_required' : 'queued',
            'risk_level' => $risk,
            'requires_local_approval' => $requiresApproval,
            'approved_at' => $requiresApproval ? null : now(),
            'expires_at' => now()->addMinutes(config('luczor.device_jobs.ttl_minutes')),
            'payload' => $payload,
            'payload_hash' => $payloadHash,
        ]);
        $job->update(['signature' => $signer->sign($job)]);
        if (config('queue.default') !== 'sync') {
            DeviceJobCreated::dispatch($job->fresh(['device']));
        }
        $this->notifyDeviceJob($job, $device, $requiresApproval);

        $audit->record([
            'actor_user_id' => $actorUserId,
            'device_id' => $device->id,
            'project_id' => $project?->id,
            'device_job_id' => $job->id,
            'event_type' => 'device_job.created',
            'tool' => $tool,
            'approval' => $requiresApproval ? 'required' : 'policy_preapproved',
            'risk_level' => $risk,
            'outcome' => 'queued',
            'payload' => $tool === 'workspace.chat' ? ['chat_id' => $payload['chat_id'], 'scope' => $payload['scope'], 'content_hash' => $payloadHash] : $payload,
        ]);

        return $job->fresh(['device']);
    }

    /**
     * SOLL §14 P15b — compile a workflow client task into a signed device_job
     * bundle. No request actor: the workflow step's owner is the actor, and the
     * target device must belong to them.
     */
    public function createForWorkflow(WorkflowStep $step, Device $device, array $params): DeviceJob
    {
        return DB::transaction(function () use ($step, $device, $params) {
            $root = app(WorkflowBudgetService::class)->root($step->run);
            abort_if(WorkflowBoundaryStop::requested($root), 409, 'workflow_boundary_stop_pending');
            $step = WorkflowStep::query()->lockForUpdate()->findOrFail($step->id);
            abort_unless($step->run->status === 'running' && $step->status === 'running', 409, 'Workflow step is no longer running.');
            if ($step->execution_id && ($existing = DeviceJob::where('workflow_execution_id', $step->execution_id)->first())) {
                return $existing;
            }
            $tools = app(DeviceToolPolicy::class);
            $signer = app(DeviceJobSigner::class);
            $audit = app(AuditLogger::class);

            abort_unless((int) $device->user_id === (int) $step->user_id, 404, 'The target device belongs to another user.');
            abort_if($device->revoked_at, 409, 'The target device is revoked.');

            $run = $step->run;
            $context = $run->context['_execution'] ?? [];
            $payload = $tools->normalize('workflow.task', [
                'task_key' => $step->type,
                'task_version' => $step->type_version,
                'params' => $params,
                'workflow' => [
                    'run' => $run->public_id, 'step_id' => $step->id, 'step_key' => $step->step_key,
                    'resource_run' => $root->public_id,
                    'execution_id' => $step->execution_id, 'definition_id' => $context['root_workflow_definition_id'] ?? $run->workflow_definition_id,
                    'revision' => $context['root_workflow_revision'] ?? $run->definition_snapshot['version'] ?? 1,
                    'child_definition_id' => $run->workflow_definition_id, 'child_revision' => $run->definition_snapshot['version'] ?? 1,
                    'project_id' => $context['project_id'] ?? null,
                    'device_id' => $device->device_id,
                    'file_scope' => $params['file_scope'] ?? 'legacy', 'workspace_root_id' => $params['workspace_root_id'] ?? null,
                    'automatic' => (bool) ($context['automatic'] ?? false), 'grant' => $context['grant'] ?? null,
                    'test_mode' => $run->test_mode, 'test_binding' => $context['grant']['config']['test_binding'] ?? null,
                    'test_run' => $context['_test_run_id'] ?? null,
                    'thinking_tier' => $params['thinking_tier'] ?? $run->definition_snapshot['definition']['thinking_tier'] ?? 'balanced',
                    'workspace_root_path' => $params['workspace_root_id'] ?? null,
                    'input_sources' => $this->workflowInputSources($step->payload ?? []),
                    'output_keys' => [$step->step_key],
                ],
            ]);
            $risk = $tools->risk('workflow.task', $payload);
            $requiresApproval = ! empty($context['automatic']) && ! empty($context['grant'])
                ? false : $tools->requiresLocalApproval((int) $device->user_id, $run->project_id, $device, 'workflow.task');
            $job = DeviceJob::create([
                'public_id' => (string) Str::uuid(),
                'user_id' => (int) $device->user_id,
                'project_id' => $run->project_id,
                'device_id' => $device->id,
                'agent_run_id' => $run->agent_run_id,
                'workflow_execution_id' => $step->execution_id,
                'tool_profile' => 'workflow.task',
                'status' => $requiresApproval ? 'approval_required' : 'queued',
                'risk_level' => $risk,
                'requires_local_approval' => $requiresApproval,
                'approved_at' => $requiresApproval ? null : now(),
                'expires_at' => now()->addMinutes(config('luczor.device_jobs.ttl_minutes')),
                'payload' => $payload,
                'payload_hash' => app(AuditLogger::class)->hash($payload),
            ]);
            $job->update(['signature' => $signer->sign($job)]);
            $step->update(['external_run_type' => 'device_job', 'external_run_id' => $job->public_id]);
            DB::afterCommit(function () use ($job, $device, $requiresApproval) {
                if (config('queue.default') !== 'sync') {
                    DeviceJobCreated::dispatch($job->fresh(['device']));
                }
                $this->notifyDeviceJob($job, $device, $requiresApproval);
            });

            $audit->record([
                'actor_user_id' => $step->user_id,
                'device_id' => $device->id,
                'project_id' => $run->project_id,
                'device_job_id' => $job->id,
                'event_type' => 'device_job.created',
                'tool' => 'workflow.task',
                'approval' => $requiresApproval ? 'required' : 'policy_preapproved',
                'risk_level' => $risk,
                'outcome' => 'queued',
                'payload' => $payload,
            ]);

            return $job->fresh(['device']);
        });
    }

    private function notifyDeviceJob(DeviceJob $job, Device $device, bool $requiresApproval): void
    {
        try {
            app(AppNotificationService::class)->send(
                user: (int) $device->user_id,
                notificationId: 'device-job:'.$job->public_id,
                title: $requiresApproval ? 'Freigabe erforderlich' : 'Neue Geräteaktion',
                body: $requiresApproval
                    ? 'Eine Aktion wartet auf deine Freigabe.'
                    : 'Eine neue Aktion ist für dieses Gerät verfügbar.',
                category: 'device',
                data: [
                    'device_job_id' => $job->public_id,
                    'status' => $job->status,
                    'tool_profile' => $job->tool_profile,
                ],
                priority: $requiresApproval ? 'high' : 'normal',
                expiresAt: $job->expires_at,
                targetDevice: $device,
            );
        } catch (Throwable $exception) {
            // A notification outage must never prevent the signed job itself.
            Log::notice('Luczor device notification could not be persisted or queued.', [
                'notification_id' => 'device-job:'.$job->public_id,
                'user_id' => $device->user_id,
                'error_class' => $exception::class,
            ]);
        }
    }

    private function workflowInputSources(array $payload): array
    {
        $sources = [];
        $walk = function (mixed $value) use (&$walk, &$sources): void {
            if (! is_array($value)) {
                return;
            }
            if (isset($value['$ref']) && is_string($value['$ref'])) {
                $sources[] = explode('.', $value['$ref'])[0];
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        $walk($payload);
        foreach ($payload['input_bindings'] ?? [] as $reference) {
            $sources[] = explode('.', (string) $reference)[0];
        }

        return array_values(array_unique($sources));
    }
}
