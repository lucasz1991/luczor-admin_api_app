<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderKeysAbilityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_proxy_capable_device_keys_gain_the_provider_keys_ability(): void
    {
        $user = User::factory()->create();
        $key = fn (array $abilities) => ApiKey::mint(['user_id' => $user->id, 'name' => 'k', 'abilities' => $abilities])['model'];
        $device = $key(['sync.read', 'settings.read', 'proxy.use']);
        $readOnly = $key(['settings.read']);
        $already = $key(['proxy.use', 'provider.keys']);
        $admin = $key(['all']);

        $migration = require database_path('migrations/2026_11_23_110000_grant_provider_keys_to_existing_device_keys.php');
        $migration->up();

        $this->assertSame(['sync.read', 'settings.read', 'proxy.use', 'provider.keys'], $device->fresh()->abilities);
        // A key that may not use the proxy gets nothing; unrelated keys stay untouched.
        $this->assertSame(['settings.read'], $readOnly->fresh()->abilities);
        $this->assertSame(['proxy.use', 'provider.keys'], $already->fresh()->abilities);
        $this->assertSame(['all'], $admin->fresh()->abilities);

        $migration->down();
        $this->assertSame(['sync.read', 'settings.read', 'proxy.use'], $device->fresh()->abilities);
        $this->assertSame(['proxy.use'], $already->fresh()->abilities);
    }
}
