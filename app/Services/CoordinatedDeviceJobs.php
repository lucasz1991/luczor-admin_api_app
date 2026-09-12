<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CoordinatedDeviceJobs
{
    public function __construct(private DeviceLeadership $leadership, private DeviceJobSigner $signer) {}

    public function create(Device $source, array $data): DeviceJob
    {
        return DB::transaction(function () use ($source, $data) {
            $this->leadership->fence($source, $data['master_epoch']);
            $hash = self::hash($data);
            $previous = DeviceJob::where('user_id', $source->user_id)->where('operation_id', $data['operation_id'])->first();
            if ($previous) {
                abort_unless(hash_equals($previous->request_hash, $hash), 409, 'coordination_operation_conflict');

                return $previous;
            }
            $target = Device::where('user_id', $source->user_id)->where('device_id', $data['target_device_id'])->whereNull('revoked_at')->firstOrFail();
            $project = empty($data['project_id']) ? null : Project::where('user_id', $source->user_id)->where('external_id', $data['project_id'])->firstOrFail();
            if (! empty($data['conversation_id'])) {
                Conversation::where('user_id', $source->user_id)->where('external_id', $data['conversation_id'])
                    ->when($project, fn ($query) => $query->where('project_id', $project->id))->firstOrFail();
            }
            abort_if(in_array($data['tool_profile'], ['workflow.task', 'workspace.chat'], true), 422, 'coordination_profile_requires_scheduler');
            $payload = app(DeviceToolPolicy::class)->normalize($data['tool_profile'], $data['payload'] ?? []);
            if (str_starts_with($data['tool_profile'], 'desktop.input.')) {
                $observation = validator($data['payload'] ?? [], ['observation_id' => 'required|string|max:120'])->validate();
                $payload += $observation;
            }
            $job = new DeviceJob;
            $job->protocol_version = 2; // Encryption cast must see v2 before payload assignment.
            $job->fill(['public_id' => (string) Str::uuid(), 'user_id' => $source->user_id, 'source_device_id' => $source->id,
                'device_id' => $target->id, 'project_id' => $project?->id, 'operation_id' => $data['operation_id'],
                'request_hash' => $hash, 'master_epoch' => $data['master_epoch'], 'conversation_external_id' => $data['conversation_id'] ?? null,
                'tool_profile' => $data['tool_profile'], 'payload' => $payload, 'payload_hash' => app(AuditLogger::class)->hash($payload),
                'risk_level' => app(DeviceToolPolicy::class)->risk($data['tool_profile'], $payload),
                'requires_local_approval' => false, 'approved_at' => now(), 'status' => 'queued',
                'expires_at' => now()->addMinutes(15)]);
            $job->save();
            $job->update(['signature' => $this->signer->sign($job)]);

            return $job;
        }, 3);
    }

    public function envelope(DeviceJob $job): array
    {
        return $this->signer->versionedEnvelope($job) + [
            'signature' => $this->signer->sign($job), 'payload' => $job->payload,
            'status' => $job->status, 'lease_expires_at' => $job->lease_expires_at?->toIso8601String(),
            'requires_local_approval' => (bool) $job->requires_local_approval,
            'cancel_requested' => (bool) $job->cancel_requested_at,
            'result' => $job->result, 'error' => $job->error,
            'progress_sequence' => $job->progress_sequence, 'progress' => $job->progress,
            'conversation_id' => $job->conversation_external_id,
        ];
    }

    public function mutate(Device $device, string $id, string $action, array $data): DeviceJob
    {
        return DB::transaction(function () use ($device, $id, $action, $data) {
            User::whereKey($device->user_id)->lockForUpdate()->firstOrFail();
            $job = DeviceJob::where('user_id', $device->user_id)->where('public_id', $id)->where('protocol_version', 2)->lockForUpdate()->firstOrFail();
            if ($action === 'cancel') {
                $this->leadership->fence($device, $data['master_epoch']);
                if (! in_array($job->status, ['completed', 'failed', 'cancelled'], true)) {
                    $job->update(['cancel_requested_at' => now(), 'status' => $job->attempt_id ? 'cancelling' : 'cancelled',
                        'finished_at' => $job->attempt_id ? null : now()]);
                }

                return $job;
            }
            abort_unless((int) $job->device_id === (int) $device->id, 404);
            if ($action === 'cancel-ack') {
                // An old owner may acknowledge a stop after failover; this grants no new effects.
                abort_unless($job->attempt_id === $data['attempt_id'] && $job->master_epoch === $data['master_epoch'], 409, 'coordination_attempt_conflict');
                abort_unless($job->cancel_requested_at || $job->status === 'cancelled', 409, 'coordination_cancel_not_requested');
                $job->update(['status' => 'cancelled', 'finished_at' => $job->finished_at ?? now()]);

                return $job;
            }
            // Identical completion is recoverable even after a later leadership transition.
            if ($action === 'complete' && in_array($job->status, ['completed', 'failed'], true)) {
                $this->sameAttempt($job, $data);
                abort_unless($job->status === ($data['ok'] ? 'completed' : 'failed')
                    && $job->result_hash === self::hash($data['result'] ?? [])
                    && $job->error === ($data['ok'] ? null : ($data['error'] ?? 'device_execution_failed')), 409, 'coordination_result_conflict');

                return $job;
            }
            $this->leadership->fence($device, $data['master_epoch'], false);
            abort_unless($job->master_epoch === $data['master_epoch'], 409, 'coordination_job_epoch_stale');
            abort_if($job->cancel_requested_at, 409, 'coordination_job_cancelled');
            if ($action === 'claim') {
                if ($job->attempt_id !== null) {
                    $this->sameAttempt($job, $data);
                    abort_unless($job->status === 'running', 409, 'coordination_job_not_running');
                    // Re-delivery identifies the original attempt; the native ledger decides recovery, never a new execution.
                    return $job;
                }
                abort_unless($job->status === 'queued' && ! $job->expires_at?->isPast(), 409, 'coordination_job_not_claimable');
                $job->update(['attempt_id' => $data['attempt_id'], 'status' => 'running', 'started_at' => now(),
                    'lease_expires_at' => now()->addSeconds(DeviceLeadership::LEASE_SECONDS)]);
            } elseif ($action === 'progress') {
                $this->sameAttempt($job, $data);
                abort_unless($job->status === 'running', 409, 'coordination_job_not_running');
                $payload = ['status' => $data['status'] ?? 'running', 'summary' => $data['summary'] ?? ''];
                if ($data['sequence'] <= $job->progress_sequence) {
                    $event = DB::table('device_job_events')->where('device_job_id', $job->id)->where('sequence', $data['sequence'])->first();
                    abort_unless($event && self::hash(json_decode(Crypt::decryptString($event->data), true)) === self::hash($payload), 409, 'coordination_progress_conflict');
                } else {
                    abort_unless($data['sequence'] === $job->progress_sequence + 1, 409, 'coordination_progress_gap');
                    DB::table('device_job_events')->insert(['device_job_id' => $job->id, 'sequence' => $data['sequence'],
                        'type' => 'progress', 'data' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'created_at' => now()]);
                    $job->progress_sequence = $data['sequence'];
                    $job->progress = $payload;
                }
                $job->lease_expires_at = now()->addSeconds(DeviceLeadership::LEASE_SECONDS);
                $job->save();
            } elseif ($action === 'complete') {
                $this->sameAttempt($job, $data);
                abort_unless($job->status === 'running', 409, 'coordination_job_not_running');
                $job->update(['status' => $data['ok'] ? 'completed' : 'failed', 'result' => $data['result'] ?? [],
                    'result_hash' => self::hash($data['result'] ?? []), 'error' => $data['ok'] ? null : ($data['error'] ?? 'device_execution_failed'), 'finished_at' => now()]);
            }
            $job->signature = $this->signer->sign($job);
            $job->save();

            return $job;
        }, 3);
    }

    private function sameAttempt(DeviceJob $job, array $data): void
    {
        abort_unless($job->attempt_id === $data['attempt_id'] && $job->master_epoch === $data['master_epoch'], 409, 'coordination_attempt_conflict');
    }

    public static function hash(array $data): string
    {
        return hash('sha256', AutomationGrantService::canonicalJson($data));
    }
}
