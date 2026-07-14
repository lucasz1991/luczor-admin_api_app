<?php

namespace App\Services;

use App\Events\DeviceJobCreated;
use App\Models\AgentRun;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\WorkflowStep;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Creates the only permitted class of remote device work: signed, fixed profiles. */
class DeviceJobService
{
    /** @param array{device_id:string,project_id?:string|null,agent_run_id?:int|null,tool_profile:string,payload?:array<string,mixed>} $data */
    public function create(Request $request, array $data): DeviceJob
    {
        $actor = app(ApiActor::class);
        $tools = app(DeviceToolPolicy::class);
        $signer = app(DeviceJobSigner::class);
        $audit = app(AuditLogger::class);
        $actorUserId = $actor->userId($request);

        $device = Device::query()->where('device_id', $data['device_id'])->firstOrFail();
        if (! $request->user()?->isAdmin()) {
            abort_unless((int) $device->user_id === $actorUserId, 404);
        }
        abort_if($device->revoked_at, 409, 'The target device is revoked.');

        $project = $actor->project($request, $data['project_id'] ?? null);
        if (! empty($data['agent_run_id'])) {
            $run = AgentRun::findOrFail($data['agent_run_id']);
            $actor->assertOwned($request, $run);
        }

        $tool = $data['tool_profile'];
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
            'payload' => $payload,
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
        $tools = app(DeviceToolPolicy::class);
        $signer = app(DeviceJobSigner::class);
        $audit = app(AuditLogger::class);

        abort_unless((int) $device->user_id === (int) $step->user_id, 404, 'The target device belongs to another user.');
        abort_if($device->revoked_at, 409, 'The target device is revoked.');

        $run = $step->run;
        $payload = $tools->normalize('workflow.task', [
            'task_key' => $step->type,
            'params' => $params,
            'workflow' => ['run' => $run->public_id, 'step_id' => $step->id, 'step_key' => $step->step_key],
        ]);
        $risk = $tools->risk('workflow.task', $payload);
        $requiresApproval = $tools->requiresLocalApproval((int) $device->user_id, $run->project_id, $device, 'workflow.task');
        $job = DeviceJob::create([
            'public_id' => (string) Str::uuid(),
            'user_id' => (int) $device->user_id,
            'project_id' => $run->project_id,
            'device_id' => $device->id,
            'agent_run_id' => $run->agent_run_id,
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
        if (config('queue.default') !== 'sync') {
            DeviceJobCreated::dispatch($job->fresh(['device']));
        }

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
    }
}
