<?php

namespace App\Events;

use App\Models\AppNotification;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppNotificationCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public int $tries = 4;

    public int $backoff = 30;

    public int $timeout = 30;

    public int $maxExceptions = 4;

    public bool $afterCommit = true;

    /** @param array<int, string> $deviceIds */
    public function __construct(
        public AppNotification $notification,
        public array $deviceIds,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(
            fn (string $deviceId) => new PrivateChannel('device.'.$deviceId),
            $this->deviceIds,
        );
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->notification->toPushPayload();
    }

    public function broadcastQueue(): string
    {
        return (string) config('luczor.notifications.queue', 'default');
    }
}
