<?php

namespace App\Events;

use App\Models\DeviceJob;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceJobCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 10;

    public int $backoff = 2;

    public bool $afterCommit = true;

    public function broadcastQueue(): string
    {
        return (string) config('luczor.device_jobs.broadcast_queue', 'device-coordination');
    }

    public function __construct(public DeviceJob $job) {}

    public function broadcastOn(): array
    {
        $device = $this->job->device;
        if (! $device || $device->revoked_at || (int) $device->user_id !== (int) $this->job->user_id) {
            return [];
        }

        return [new PrivateChannel('device.'.$device->device_id)];
    }

    public function broadcastAs(): string
    {
        return 'device.job.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->job->public_id,
            'tool_profile' => $this->job->tool_profile,
            'status' => $this->job->status,
            'risk_level' => $this->job->risk_level,
            'requires_local_approval' => $this->job->requires_local_approval,
            'payload' => $this->job->payload,
            'payload_hash' => $this->job->payload_hash,
            'signature' => $this->job->signature,
            'expires_at' => $this->job->expires_at?->toIso8601String(),
        ];
    }
}
