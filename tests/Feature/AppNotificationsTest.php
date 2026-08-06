<?php

namespace Tests\Feature;

use App\Events\AppNotificationCreated;
use App\Models\ApiKey;
use App\Models\Device;
use App\Models\User;
use App\Services\AppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AppNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_are_opt_in_and_validate_known_categories(): void
    {
        [$user, $token] = $this->token(['device.connect']);
        $this->register($token, 'desktop-1');

        $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/notification-preferences?client_id=desktop-1')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'enabled' => false,
                    'categories' => [
                        'general' => true,
                        'agent' => true,
                        'workflow' => true,
                        'device' => true,
                        'security' => true,
                    ],
                    'effective_categories' => [
                        'general' => false,
                        'agent' => false,
                        'workflow' => false,
                        'device' => false,
                        'security' => false,
                    ],
                ],
            ]);

        $this->withHeader('X-Api-Key', $token)
            ->putJson('/api/v1/notification-preferences', [
                'client_id' => 'desktop-1',
                'enabled' => true,
                'categories' => ['device' => false],
            ])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.categories.device', false)
            ->assertJsonPath('data.effective_categories.device', false)
            ->assertJsonPath('data.effective_categories.agent', true);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'category' => '__all__',
            'push_enabled' => true,
        ]);

        $this->withHeader('X-Api-Key', $token)
            ->putJson('/api/v1/notification-preferences', [
                'client_id' => 'desktop-1',
                'categories' => ['marketing' => true],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('categories');
    }

    public function test_notification_is_persistent_idempotent_and_broadcast_only_when_enabled(): void
    {
        [$user, $token] = $this->token(['device.connect']);
        $this->register($token, 'desktop-1');
        $device = Device::where('device_id', 'desktop-1')->firstOrFail();
        $service = app(AppNotificationService::class);
        Event::fake([AppNotificationCreated::class]);

        $first = $service->send(
            user: $user,
            notificationId: 'agent-run:42',
            title: 'Agent fertig',
            body: 'Der Lauf wurde abgeschlossen.',
            category: 'agent',
            actionUrl: 'luczor://agent-runs/42',
            data: ['run_id' => 42],
            targetDevice: $device,
        );
        $duplicate = $service->send(
            user: $user,
            notificationId: 'agent-run:42',
            title: 'Darf nicht überschrieben werden',
            body: 'Duplicate',
            category: 'agent',
            targetDevice: $device,
        );

        $this->assertSame($first->id, $duplicate->id);
        $this->assertSame('Agent fertig', $duplicate->title);
        $this->assertDatabaseCount('app_notifications', 1);
        Event::assertNotDispatched(AppNotificationCreated::class);

        $service->updatePreferences($user, true, []);
        $pushed = $service->send(
            user: $user,
            notificationId: 'agent-run:43',
            title: 'Neues Ergebnis',
            body: 'Der zweite Lauf wurde abgeschlossen.',
            category: 'agent',
            data: ['run_id' => 43],
            priority: 'high',
            targetDevice: $device,
        );

        Event::assertDispatched(AppNotificationCreated::class, function (AppNotificationCreated $event) use ($pushed) {
            $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

            return $event->notification->is($pushed)
                && $event->broadcastAs() === 'notification.created'
                && $channels === ['private-device.desktop-1']
                && $event->broadcastWith() === $pushed->toPushPayload();
        });
    }

    public function test_catch_up_is_ordered_device_scoped_and_excludes_expired_notifications(): void
    {
        [$user, $firstToken] = $this->token(['device.connect']);
        [, $secondToken] = $this->token(['device.connect'], $user);
        $this->register($firstToken, 'desktop-1');
        $this->register($secondToken, 'desktop-2');
        $firstDevice = Device::where('device_id', 'desktop-1')->firstOrFail();
        $secondDevice = Device::where('device_id', 'desktop-2')->firstOrFail();
        $service = app(AppNotificationService::class);

        $global = $service->send($user, 'general:1', 'Global', 'Für alle Geräte');
        $firstOnly = $service->send(
            $user,
            'device:1',
            'Gerät 1',
            'Nur Gerät 1',
            'device',
            targetDevice: $firstDevice,
        );
        $service->send(
            $user,
            'device:2',
            'Gerät 2',
            'Nur Gerät 2',
            'device',
            targetDevice: $secondDevice,
        );
        $service->send(
            $user,
            'expired:1',
            'Abgelaufen',
            'Nicht mehr zustellen',
            expiresAt: now()->subSecond(),
        );

        $firstPage = $this->withHeader('X-Api-Key', $firstToken)
            ->getJson('/api/v1/notifications?client_id=desktop-1&after=0&limit=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $global->notification_id)
            ->assertJsonPath('data.0.sequence', $global->id)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.unread_count', 2);
        $next = $firstPage->json('meta.next_after');

        $this->withHeader('X-Api-Key', $firstToken)
            ->getJson('/api/v1/notifications?client_id=desktop-1&after='.$next.'&limit=50')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstOnly->notification_id)
            ->assertJsonPath('meta.has_more', false);

        $this->withHeader('X-Api-Key', $secondToken)
            ->getJson('/api/v1/notifications?client_id=desktop-2&after=0')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['id' => 'device:1']);
    }

    public function test_read_and_bounded_read_all_are_idempotent_and_user_isolated(): void
    {
        [$firstUser, $firstToken] = $this->token(['device.connect']);
        [, $secondToken] = $this->token(['device.connect']);
        $this->register($firstToken, 'desktop-1');
        $this->register($secondToken, 'desktop-2');
        $service = app(AppNotificationService::class);
        $first = $service->send($firstUser, 'message:1', 'Eins', 'Erste Meldung');
        $second = $service->send($firstUser, 'message:2', 'Zwei', 'Zweite Meldung');
        $service->send($firstUser, 'message:3', 'Drei', 'Dritte Meldung');

        $this->withHeader('X-Api-Key', $firstToken)
            ->postJson('/api/v1/notifications/'.$first->notification_id.'/read', ['client_id' => 'desktop-1'])
            ->assertOk()
            ->assertJsonPath('data.id', 'message:1')
            ->assertJsonPath('meta.unread_count', 2);
        $this->withHeader('X-Api-Key', $firstToken)
            ->postJson('/api/v1/notifications/'.$first->notification_id.'/read', ['client_id' => 'desktop-1'])
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 2);

        $this->withHeader('X-Api-Key', $firstToken)
            ->postJson('/api/v1/notifications/read-all', [
                'client_id' => 'desktop-1',
                'through' => $second->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('meta.unread_count', 1);

        $this->withHeader('X-Api-Key', $secondToken)
            ->postJson('/api/v1/notifications/message:1/read', ['client_id' => 'desktop-2'])
            ->assertNotFound();
    }

    public function test_reverb_auth_and_bootstrap_use_reverb_credentials(): void
    {
        [, $token] = $this->token(['device.connect']);
        Config::set('broadcasting.connections.reverb.key', 'reverb-public');
        Config::set('broadcasting.connections.reverb.secret', 'reverb-secret');
        Config::set('broadcasting.connections.pusher.key', null);
        Config::set('broadcasting.connections.pusher.secret', null);
        $registration = $this->register($token, 'desktop-1');

        $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/realtime/config')
            ->assertOk()
            ->assertJsonPath('data.key', 'reverb-public');

        $socketId = '123.456';
        $channel = 'private-device.desktop-1';
        $expected = 'reverb-public:'.hash_hmac('sha256', $socketId.':'.$channel, 'reverb-secret');
        $this->withHeaders([
            'X-Api-Key' => $token,
            'X-Device-Session' => $registration['session']['token'],
        ])->postJson('/api/v1/reverb/auth', [
            'socket_id' => $socketId,
            'channel_name' => $channel,
            'client_id' => 'desktop-1',
        ])->assertOk()->assertJsonPath('auth', $expected);
    }

    /** @return array{0: User, 1: string} */
    private function token(array $abilities, ?User $user = null): array
    {
        $user ??= User::factory()->create();
        $minted = ApiKey::mint([
            'user_id' => $user->id,
            'name' => 'Notification device',
            'abilities' => $abilities,
            'active' => true,
        ]);

        return [$user, $minted['plain']];
    }

    /** @return array<string, mixed> */
    private function register(string $token, string $clientId): array
    {
        return $this->withHeader('X-Api-Key', $token)
            ->postJson('/api/v1/devices/register', [
                'client_id' => $clientId,
                'name' => $clientId,
            ])
            ->assertCreated()
            ->json();
    }
}
