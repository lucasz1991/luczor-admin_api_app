<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\DeviceSession;
use App\Models\WorkflowStep;
use App\Services\ApiActor;
use App\Services\AuditLogger;
use App\Services\DeviceJobSigner;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function register(Request $request, ApiActor $actor, AuditLogger $audit)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'public_key' => ['nullable', 'string', 'max:10000'],
            'meta' => ['nullable', 'array'],
        ]);
        $userId = $actor->userId($request);
        $deviceId = $actor->deviceId($request, $data['client_id'], true);
        $apiKey = $request->attributes->get('apiKey');

        $existing = Device::query()->where('device_id', $deviceId)->first();
        abort_if($existing && ! $request->user()?->isAdmin() && (int) $existing->user_id !== $userId, 404);

        $device = Device::updateOrCreate(
            ['device_id' => $deviceId],
            [
                'user_id' => $userId,
                'api_key_id' => $apiKey?->id,
                'name' => $data['name'],
                'public_key' => $data['public_key'] ?? null,
                'status' => 'online',
                'last_seen_at' => now(),
                'meta' => $data['meta'] ?? null,
            ]
        );
        $session = $this->newSession($device, $request);
        $audit->record([
            'actor_user_id' => $userId,
            'device_id' => $device->id,
            'event_type' => 'device.registered',
            'outcome' => 'accepted',
            'payload' => ['device_id' => $device->device_id, 'name' => $device->name],
        ]);

        return response()->json([
            'data' => $device,
            'session' => $session,
        ], 201);
    }

    public function heartbeat(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
            'metrics' => ['nullable', 'array'],
            'status' => ['nullable', 'in:online,busy,offline'],
        ]);
        $device = $this->currentDevice($request, $actor, $data['client_id']);
        $device->update([
            'status' => $data['status'] ?? 'online',
            'metrics' => $data['metrics'] ?? $device->metrics,
            'last_seen_at' => now(),
        ]);

        return response()->json(['data' => $device->fresh()]);
    }

    public function index(Request $request, ApiActor $actor)
    {
        $query = Device::query()->latest('last_seen_at');
        if (! $request->user()?->isAdmin()) {
            $query->where('user_id', $actor->userId($request));
        }

        return response()->json(['data' => $query->paginate(50)]);
    }

    public function signingKey(DeviceJobSigner $signer)
    {
        $key = $signer->publicKey();
        abort_unless($key, 503, 'Device job signing is not configured.');

        return response()->json(['algorithm' => 'RSA-SHA256', 'public_key' => $key]);
    }

    public function nextJob(Request $request, ApiActor $actor, DeviceJobSigner $signer)
    {
        $clientId = (string) $request->query('client_id');
        $device = $this->currentDevice($request, $actor, $clientId);
        $job = DeviceJob::query()
            ->where('device_id', $device->id)
            ->whereIn('status', ['approval_required', 'queued'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->oldest()
            ->first();

        if (! $job) {
            return response()->json(['data' => null]);
        }

        if (! $job->signature) {
            $job->update(['signature' => $signer->sign($job)]);
        }

        return response()->json(['data' => $this->jobPayload($job->fresh())]);
    }

    public function approveJob(Request $request, string $publicId, ApiActor $actor, AuditLogger $audit)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
            'approved' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $device = $this->currentDevice($request, $actor, $data['client_id']);
        $job = DeviceJob::query()->where('public_id', $publicId)->where('device_id', $device->id)->firstOrFail();
        abort_unless($job->status === 'approval_required', 409, 'This job is not awaiting approval.');

        $decision = $data['approved'] ? 'approved' : 'rejected';
        $job->approvals()->create([
            'device_id' => $device->id,
            'decision' => $decision,
            'reason' => $data['reason'] ?? null,
            'decided_at' => now(),
        ]);
        $job->update([
            'status' => $data['approved'] ? 'queued' : 'rejected',
            'approved_at' => $data['approved'] ? now() : null,
            'finished_at' => $data['approved'] ? null : now(),
            'error' => $data['approved'] ? null : ($data['reason'] ?? 'Rejected on device'),
        ]);
        $audit->record([
            'actor_user_id' => $device->user_id,
            'device_id' => $device->id,
            'project_id' => $job->project_id,
            'device_job_id' => $job->id,
            'event_type' => 'device_job.approval',
            'tool' => $job->tool_profile,
            'approval' => $decision,
            'risk_level' => $job->risk_level,
            'outcome' => $decision,
            'payload' => ['job_id' => $job->public_id, 'reason' => $data['reason'] ?? null],
        ]);
        if (! $data['approved']) {
            $this->settleWorkflowStep($job->fresh());   // P15b — a rejection fails the workflow step
        }

        return response()->json(['data' => $job->fresh()]);
    }

    public function startJob(Request $request, string $publicId, ApiActor $actor, AuditLogger $audit)
    {
        $data = $request->validate(['client_id' => ['required', 'string', 'max:120']]);
        $device = $this->currentDevice($request, $actor, $data['client_id']);
        $job = DeviceJob::query()->where('public_id', $publicId)->where('device_id', $device->id)->firstOrFail();
        abort_unless($job->status === 'queued', 409, 'This job is not executable.');
        abort_if($job->expires_at?->isPast(), 410, 'This job has expired.');

        $job->update(['status' => 'running', 'started_at' => now()]);
        $audit->record([
            'actor_user_id' => $device->user_id, 'device_id' => $device->id,
            'project_id' => $job->project_id, 'device_job_id' => $job->id,
            'event_type' => 'device_job.started', 'tool' => $job->tool_profile,
            'risk_level' => $job->risk_level, 'outcome' => 'started', 'payload' => ['job_id' => $job->public_id],
        ]);

        return response()->json(['data' => $job->fresh()]);
    }

    public function completeJob(Request $request, string $publicId, ApiActor $actor, AuditLogger $audit)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
            'ok' => ['required', 'boolean'],
            'result' => ['nullable', 'array'],
            'error' => ['nullable', 'string', 'max:8000'],
        ]);
        $device = $this->currentDevice($request, $actor, $data['client_id']);
        $job = DeviceJob::query()->where('public_id', $publicId)->where('device_id', $device->id)->firstOrFail();
        abort_unless($job->status === 'running', 409, 'This job is not running.');

        $resultHash = $data['result'] === null ? null : $audit->hash($data['result']);
        $job->update([
            'status' => $data['ok'] ? 'completed' : 'failed',
            'result' => $data['result'] ?? null,
            'result_hash' => $resultHash,
            'error' => $data['ok'] ? null : ($data['error'] ?? 'Device tool failed'),
            'finished_at' => now(),
        ]);
        $audit->record([
            'actor_user_id' => $device->user_id, 'device_id' => $device->id,
            'project_id' => $job->project_id, 'device_job_id' => $job->id,
            'event_type' => 'device_job.completed', 'tool' => $job->tool_profile,
            'risk_level' => $job->risk_level, 'outcome' => $data['ok'] ? 'completed' : 'failed',
            'payload' => ['job_id' => $job->public_id], 'result' => $data['result'] ?? ['error' => $data['error'] ?? null],
        ]);
        $this->settleWorkflowStep($job->fresh());   // P15b — feed the result back into the workflow

        return response()->json(['data' => $job->fresh()]);
    }

    /**
     * SOLL §14 P15b — when a workflow-task bundle reaches a terminal status,
     * poke the owning run's monitor so the step settles promptly (the minute
     * sweeper would catch it anyway; this removes the latency).
     */
    private function settleWorkflowStep(DeviceJob $job): void
    {
        if ($job->tool_profile !== 'workflow.task') {
            return;
        }
        $step = WorkflowStep::query()
            ->where('external_run_type', 'device_job')
            ->where('external_run_id', $job->public_id)
            ->first();
        if ($step && $step->run) {
            app(WorkflowService::class)->scheduleMonitor($step->run, 1);
        }
    }

    private function currentDevice(Request $request, ApiActor $actor, string $clientId): Device
    {
        $deviceId = $actor->deviceId($request, $clientId, true);

        return Device::query()
            ->where('device_id', $deviceId)
            ->where('user_id', $actor->userId($request))
            ->whereNull('revoked_at')
            ->firstOrFail();
    }

    private function newSession(Device $device, Request $request): array
    {
        $plain = Str::random(64);
        $session = DeviceSession::create([
            'device_id' => $device->id,
            'token_hash' => hash('sha256', $plain),
            'nonce' => Str::random(48),
            'expires_at' => now()->addMinutes(10),
            'last_seen_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        return ['token' => $plain, 'nonce' => $session->nonce, 'expires_at' => $session->expires_at->toIso8601String()];
    }

    /** @return array<string,mixed> */
    private function jobPayload(DeviceJob $job): array
    {
        return [
            'id' => $job->public_id,
            'tool_profile' => $job->tool_profile,
            'status' => $job->status,
            'risk_level' => $job->risk_level,
            'requires_local_approval' => $job->requires_local_approval,
            'payload' => $job->payload,
            'payload_hash' => $job->payload_hash,
            'signature' => $job->signature,
            'expires_at' => $job->expires_at?->toIso8601String(),
        ];
    }
}
