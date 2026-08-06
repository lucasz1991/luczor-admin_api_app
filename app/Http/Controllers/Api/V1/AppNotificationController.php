<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Device;
use App\Models\NotificationPreference;
use App\Services\ApiActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppNotificationController extends Controller
{
    public function index(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
            'after' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'unread_only' => ['nullable', 'boolean'],
            'category' => ['nullable', 'string', Rule::in(NotificationPreference::CATEGORIES)],
        ]);
        $device = $this->currentDevice($request, $actor, $data['client_id']);
        $after = (int) ($data['after'] ?? 0);
        $limit = (int) ($data['limit'] ?? 50);

        $query = $this->visibleQuery($device)
            ->where('id', '>', $after)
            ->when(
                $request->boolean('unread_only'),
                fn (Builder $query) => $query->whereNull('read_at'),
            )
            ->when(
                isset($data['category']),
                fn (Builder $query) => $query->where('category', $data['category']),
            )
            ->orderBy('id');

        $notifications = $query->limit($limit + 1)->get();
        $hasMore = $notifications->count() > $limit;
        $notifications = $notifications->take($limit)->values();
        $nextAfter = (int) ($notifications->last()?->getKey() ?? $after);

        return response()->json([
            'data' => $notifications->map->toPushPayload()->all(),
            'meta' => [
                'next_after' => $nextAfter,
                'has_more' => $hasMore,
                'unread_count' => $this->unreadCount($device),
            ],
        ]);
    }

    public function read(Request $request, string $notificationId, ApiActor $actor)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
        ]);
        $device = $this->currentDevice($request, $actor, $data['client_id']);
        $notification = AppNotification::query()
            ->visibleToDevice($device)
            ->where('notification_id', $notificationId)
            ->firstOrFail();

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json([
            'data' => $notification->fresh()->toPushPayload(),
            'meta' => ['unread_count' => $this->unreadCount($device)],
        ]);
    }

    public function readAll(Request $request, ApiActor $actor)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:120'],
            'through' => ['nullable', 'integer', 'min:1'],
        ]);
        $device = $this->currentDevice($request, $actor, $data['client_id']);
        $readAt = now();
        $updated = $this->visibleQuery($device)
            ->whereNull('read_at')
            ->when(
                isset($data['through']),
                fn (Builder $query) => $query->where('id', '<=', (int) $data['through']),
            )
            ->update(['read_at' => $readAt, 'updated_at' => $readAt]);

        return response()->json([
            'data' => [
                'updated' => $updated,
                'read_at' => $readAt->toIso8601String(),
            ],
            'meta' => ['unread_count' => $this->unreadCount($device)],
        ]);
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

    private function visibleQuery(Device $device): Builder
    {
        return AppNotification::query()->visibleToDevice($device)->current();
    }

    private function unreadCount(Device $device): int
    {
        return $this->visibleQuery($device)->whereNull('read_at')->count();
    }
}
