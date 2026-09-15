<?php

namespace App\Events;

use App\Models\Device;
use App\Models\DeviceJob;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** A wake-up hint only. Ownership, leases and signed effects remain in SQL/REST. */
class DeviceCoordinationChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 10;

    public int $backoff = 2;

    public bool $afterCommit = true;

    public function __construct(public DeviceJob $job) {}

    public function broadcastQueue(): string
    {
        return (string) config('luczor.device_jobs.broadcast_queue', 'device-coordination');
    }

    public function broadcastOn(): array
    {
        return Device::query()->where('user_id', $this->job->user_id)
            ->whereIn('id', array_filter([$this->job->device_id, $this->job->source_device_id]))
            ->whereNull('revoked_at')->pluck('device_id')
            ->map(fn (string $id) => new PrivateChannel('device.'.$id))->all();
    }

    public function broadcastAs(): string
    {
        return 'device.coordination.changed';
    }

    public function broadcastWith(): array
    {
        // Never carry chat text, command payloads, results or authority tokens.
        return ['id' => $this->job->public_id, 'status' => $this->job->status];
    }

    public static function notify(DeviceJob $job): void
    {
        if (config('queue.default') === 'sync' || config('broadcasting.default') !== 'reverb') {
            return;
        }
        DB::afterCommit(function () use ($job) {
            try {
                self::dispatch($job);
            } catch (Throwable) {
                // The durable operation already committed. Realtime failure must
                // not turn it into an apparent failure and induce a second effect.
                Log::warning('Device coordination wake-up unavailable; REST polling remains active.');
            }
        });
    }
}
