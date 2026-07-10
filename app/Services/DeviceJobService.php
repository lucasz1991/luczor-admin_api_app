<?php

namespace App\Services;

use App\Events\DeviceJobCreated;
use App\Models\AgentRun;
use App\Models\Device;
use App\Models\DeviceJob;
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
        $risk = $tools->risk($tool);
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
}
