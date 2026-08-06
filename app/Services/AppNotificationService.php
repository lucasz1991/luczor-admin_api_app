<?php

namespace App\Services;

use App\Events\AppNotificationCreated;
use App\Models\AppNotification;
use App\Models\Device;
use App\Models\NotificationPreference;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AppNotificationService
{
    /**
     * Persist a notification exactly once and broadcast it to eligible devices.
     *
     * Persistence is intentionally independent of push preferences: REST
     * catch-up remains reliable when Reverb is unavailable or push is disabled.
     *
     * @param  array<string, mixed>  $data
     */
    public function send(
        User|int $user,
        string $notificationId,
        string $title,
        string $body,
        string $category = 'general',
        ?string $actionUrl = null,
        array $data = [],
        string $priority = 'normal',
        ?CarbonInterface $expiresAt = null,
        ?Device $targetDevice = null,
        bool $bypassPreferences = false,
    ): AppNotification {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;
        $notificationId = trim($notificationId);

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,159}$/', $notificationId)) {
            throw new InvalidArgumentException('The notification ID must be a stable, URL-safe identifier.');
        }
        if (! in_array($category, NotificationPreference::CATEGORIES, true)) {
            throw new InvalidArgumentException('Unsupported notification category: '.$category);
        }
        if (! in_array($priority, ['low', 'normal', 'high'], true)) {
            throw new InvalidArgumentException('Unsupported notification priority: '.$priority);
        }
        if ($targetDevice && (int) $targetDevice->user_id !== $userId) {
            throw new InvalidArgumentException('The target device belongs to another user.');
        }

        [$notification, $created] = DB::transaction(function () use (
            $userId,
            $targetDevice,
            $notificationId,
            $category,
            $title,
            $body,
            $actionUrl,
            $data,
            $priority,
            $expiresAt,
        ) {
            $notification = AppNotification::firstOrCreate(
                [
                    'user_id' => $userId,
                    'notification_id' => $notificationId,
                ],
                [
                    'target_device_id' => $targetDevice?->id,
                    'category' => $category,
                    'title' => $title,
                    'body' => $body,
                    'action_url' => $actionUrl,
                    'data' => $data,
                    'priority' => $priority,
                    'expires_at' => $expiresAt,
                ],
            );

            return [$notification, $notification->wasRecentlyCreated];
        });

        if ($created && ($bypassPreferences || $this->deliveryEnabled($userId, $category))) {
            $deviceIds = Device::query()
                ->where('user_id', $userId)
                ->whereNull('revoked_at')
                ->when($targetDevice, fn ($query) => $query->whereKey($targetDevice->id))
                ->pluck('device_id')
                ->all();

            if ($deviceIds !== []) {
                AppNotificationCreated::dispatch($notification, $deviceIds);
            }
        }

        return $notification;
    }

    /** @return array{enabled: bool, categories: array<string, bool>, effective_categories: array<string, bool>} */
    public function preferencesFor(User|int $user): array
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;
        $stored = NotificationPreference::query()
            ->where('user_id', $userId)
            ->pluck('push_enabled', 'category');
        $enabled = (bool) ($stored[NotificationPreference::GLOBAL_CATEGORY] ?? false);
        $categories = [];
        $effective = [];

        foreach (NotificationPreference::CATEGORIES as $category) {
            // A newly enabled installation receives all categories unless the
            // user explicitly disabled individual categories.
            $categories[$category] = (bool) ($stored[$category] ?? true);
            $effective[$category] = $enabled && $categories[$category];
        }

        return [
            'enabled' => $enabled,
            'categories' => $categories,
            'effective_categories' => $effective,
        ];
    }

    /** @param array<string, bool> $categories */
    public function updatePreferences(User|int $user, ?bool $enabled, array $categories): array
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        DB::transaction(function () use ($userId, $enabled, $categories) {
            if ($enabled !== null) {
                NotificationPreference::updateOrCreate(
                    ['user_id' => $userId, 'category' => NotificationPreference::GLOBAL_CATEGORY],
                    ['push_enabled' => $enabled],
                );
            }

            foreach ($categories as $category => $pushEnabled) {
                if (! in_array($category, NotificationPreference::CATEGORIES, true)) {
                    throw new InvalidArgumentException('Unsupported notification category: '.$category);
                }
                NotificationPreference::updateOrCreate(
                    ['user_id' => $userId, 'category' => $category],
                    ['push_enabled' => $pushEnabled],
                );
            }
        });

        return $this->preferencesFor($userId);
    }

    public function deliveryEnabled(User|int $user, string $category): bool
    {
        $settings = $this->preferencesFor($user);

        return (bool) ($settings['effective_categories'][$category] ?? false);
    }
}
