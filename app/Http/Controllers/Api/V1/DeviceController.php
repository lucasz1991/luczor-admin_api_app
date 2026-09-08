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
use Illuminate\Support\Facades\DB;
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

        $device = DB::transaction(function () use ($deviceId, $userId, $apiKey, $data) {
            // firstOrCreate resolves a concurrent unique-key insert without changing its owner.
            $device = Device::firstOrCreate(['device_id' => $deviceId], [
                'user_id' => $userId,
                'name' => $data['name'],
                'status' => 'online',
            ]);
            $device = Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            // Device enrollment is personal, including administrators.
            abort_unless((int) $device->user_id === $userId, 404);
            $device->update([
                'api_key_id' => $apiKey?->id,
                'public_key' => $data['public_key'] ?? null,
                'status' => 'online',
                'last_seen_at' => now(),
                'meta' => $data['meta'] ?? null,
            ]);

            return $device;
        }, 3);
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
            ->where('user_id', $device->user_id)
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

        return DB::transaction(function () use ($device, $publicId, $data, $audit) {
            $job = DeviceJob::query()->where('public_id', $publicId)->where('device_id', $device->id)->where('user_id', $device->user_id)->lockForUpdate()->firstOrFail();
            abort_unless($job->status === 'approval_required', 409, 'This job is not awaiting approval.');
            abort_if($job->expires_at?->isPast(), 410, 'This job has expired.');

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
        });
    }

    public function startJob(Request $request, string $publicId, ApiActor $actor, AuditLogger $audit)
    {
        $data = $request->validate(['client_id' => ['required', 'string', 'max:120']]);
        $device = $this->currentDevice($request, $actor, $data['client_id']);

        return DB::transaction(function () use ($device, $publicId, $audit) {
            $job = DeviceJob::query()->where('public_id', $publicId)->where('device_id', $device->id)->where('user_id', $device->user_id)->lockForUpdate()->firstOrFail();
            abort_unless($job->status === 'queued', 409, 'This job is not executable.');
            abort_if($job->expires_at?->isPast(), 410, 'This job has expired.');
            abort_if($job->cancel_requested_at !== null, 409, 'This job was cancelled.');
            if ($job->workflow_execution_id) {
                $step = WorkflowStep::where('execution_id', $job->workflow_execution_id)->firstOrFail();
                abort_unless($step->run->status === 'running' && $step->status === 'running', 409, 'Workflow is no longer executable.');
                if ($step->run->context['_execution']['automatic'] ?? false) {
                    app(\App\Services\AutomationGrantService::class)->authorizeTask($step->run, $step->type, $step->resolved_payload ?? $step->payload ?? []);
                }
            }

            $job->update(['status' => 'running', 'started_at' => now()]);
            if (isset($step)) {
                $step->update(['started_at' => $job->started_at]);
            }
            $audit->record([
                'actor_user_id' => $device->user_id, 'device_id' => $device->id,
                'project_id' => $job->project_id, 'device_job_id' => $job->id,
                'event_type' => 'device_job.started', 'tool' => $job->tool_profile,
                'risk_level' => $job->risk_level, 'outcome' => 'started', 'payload' => ['job_id' => $job->public_id],
            ]);

            return response()->json(['data' => $job->fresh()]);
        });
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
        return DB::transaction(function () use ($device, $publicId, $data, $audit) {
        $job = DeviceJob::query()->where('public_id', $publicId)->where('device_id', $device->id)->where('user_id', $device->user_id)->lockForUpdate()->firstOrFail();
        $result = $data['result'] ?? null;
        $resultHash = $result === null ? null : $audit->hash($result);
        if (in_array($job->status, ['completed', 'failed'], true)) {
            abort_unless($job->status === ($data['ok'] ? 'completed' : 'failed') && $job->result_hash === $resultHash
                && $job->error === ($data['ok'] ? null : ($data['error'] ?? 'Device tool failed')), 409, 'Conflicting device result.');

            return response()->json(['data' => $job, 'meta' => ['replayed' => true]]);
        }
        abort_unless($job->status === 'running' && ! $job->cancel_requested_at, 409, 'This job is not running or was cancelled.');
        if ($job->workflow_execution_id) {
            $step = WorkflowStep::where('execution_id', $job->workflow_execution_id)->first();
            abort_unless($step && $step->run->status === 'running' && $step->status === 'running', 409, 'Workflow result is no longer current.');
        }
        $job->update([
            'status' => $data['ok'] ? 'completed' : 'failed',
            'result' => $result,
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
        });
    }

    public function jobStatus(Request $request, string $publicId, ApiActor $actor)
    {
        $device = $this->currentDevice($request, $actor, (string) $request->query('client_id'));
        $job = DeviceJob::where('public_id', $publicId)->where('device_id', $device->id)->where('user_id', $device->user_id)->firstOrFail();

        return response()->json(['data' => ['public_id' => $job->public_id, 'status' => $job->status, 'cancel_requested' => (bool) $job->cancel_requested_at]]);
    }

    public function acknowledgeCancellation(Request $request, string $publicId, ApiActor $actor)
    {
        $data = $request->validate(['client_id' => ['required', 'string', 'max:120']]);
        $device = $this->currentDevice($request, $actor, $data['client_id']);

        return DB::transaction(function () use ($device, $publicId) {
            $job = DeviceJob::where('public_id', $publicId)->where('device_id', $device->id)->where('user_id', $device->user_id)->lockForUpdate()->firstOrFail();
            abort_unless($job->cancel_requested_at || $job->status === 'cancelled', 409, 'No cancellation was requested.');
            $job->update(['status' => 'cancelled', 'finished_at' => $job->finished_at ?? now()]);
            $step = WorkflowStep::where('external_run_type', 'device_job')->where('external_run_id', $job->public_id)->first();
            if ($step) {
                app(WorkflowService::class)->settleCancellation($step->run);
            }

            return response()->json(['data' => ['public_id' => $job->public_id, 'status' => 'cancelled']]);
        });
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
