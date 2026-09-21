<?php

namespace App\Events;

use App\Models\Device;
use App\Models\Project;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** A wake-up hint that a shared project folder has a new revision. The manifest itself stays in REST. */
class ProjectMirrorChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 10;

    public int $backoff = 2;

    public bool $afterCommit = true;

    public function __construct(public Project $project, public int $revision, public int $publisherDeviceId) {}

    public function broadcastQueue(): string
    {
        return (string) config('luczor.device_jobs.broadcast_queue', 'device-coordination');
    }

    public function broadcastOn(): array
    {
        return Device::query()->where('user_id', $this->project->user_id)->whereKeyNot($this->publisherDeviceId)
            ->whereNull('revoked_at')->pluck('device_id')
            ->map(fn (string $id) => new PrivateChannel('device.'.$id))->all();
    }

    public function broadcastAs(): string
    {
        return 'project.mirror.changed';
    }

    public function broadcastWith(): array
    {
        // Never carry file names or content; the device fetches the manifest with its own credentials.
        return ['project_id' => $this->project->id, 'external_id' => $this->project->external_id, 'revision' => $this->revision];
    }

    public static function notify(Project $project, int $revision, int $publisherDeviceId): void
    {
        if (config('queue.default') === 'sync' || config('broadcasting.default') !== 'reverb') {
            return;
        }
        DB::afterCommit(function () use ($project, $revision, $publisherDeviceId) {
            try {
                self::dispatch($project, $revision, $publisherDeviceId);
            } catch (Throwable) {
                // The revision is already committed; peers still discover it through polling.
                Log::warning('Project mirror wake-up unavailable; REST polling remains active.');
            }
        });
    }
}
