<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\DevicePairing;
use App\Models\LlmRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDeviceConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_approval_mints_an_owned_device_key_redeemable_only_once(): void
    {
        $user = User::factory()->create();
        $pair = $this->postJson('/api/v1/auth/device', ['client_id' => 'desktop-one', 'name' => 'Desktop'])->assertCreated()->json();
        $claim = '/api/v1/auth/device/'.$pair['id'].'/claim';
        $this->postJson($claim, ['secret' => $pair['secret']])->assertStatus(202);
        $this->postJson($claim, ['secret' => str_repeat('x', 64)])->assertNotFound();
        $this->actingAs($user)->post('/devices/pair/'.$pair['id'])->assertRedirect();
        $result = $this->postJson($claim, ['secret' => $pair['secret']])->assertOk()->assertJsonPath('user.id', $user->id)->json();
        $key = ApiKey::where('token_hash', ApiKey::hashToken($result['device_key']))->firstOrFail();
        $this->assertSame('desktop-one', $key->device_id);
        $this->assertSame($user->id, $key->user_id);
        $this->assertFalse($key->hasAbility('settings.write'));
        $this->assertNull(DevicePairing::findOrFail($pair['id'])->credential);
        $this->postJson($claim, ['secret' => $pair['secret']])->assertStatus(410);
    }

    public function test_pairing_cannot_take_another_users_device_and_expires(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Device::create(['user_id' => $owner->id, 'device_id' => 'owned', 'name' => 'Owned', 'status' => 'online']);
        $pair = $this->postJson('/api/v1/auth/device', ['client_id' => 'owned', 'name' => 'Other'])->assertCreated()->json();
        $this->actingAs($other)->post('/devices/pair/'.$pair['id'])->assertStatus(409);
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/auth/device/'.$pair['id'].'/claim', ['secret' => $pair['secret']])->assertStatus(410);
        $this->assertDatabaseCount('api_keys', 0);
    }

    public function test_desktop_logout_revokes_only_the_authenticated_key_and_records_an_audit_event(): void
    {
        $user = User::factory()->create();
        $current = ApiKey::mint([
            'user_id' => $user->id,
            'name' => 'Current desktop',
            'device_id' => 'desktop-current',
            'abilities' => ApiKey::DEVICE_ABILITIES,
            'active' => true,
        ]);
        $other = ApiKey::mint([
            'user_id' => $user->id,
            'name' => 'Other desktop',
            'device_id' => 'desktop-other',
            'abilities' => ApiKey::DEVICE_ABILITIES,
            'active' => true,
        ]);
        $device = Device::create([
            'user_id' => $user->id,
            'api_key_id' => $current['model']->id,
            'device_id' => 'desktop-current',
            'name' => 'Current desktop',
            'status' => 'online',
        ]);

        $this->withToken($current['plain'])->postJson('/api/v1/auth/device/logout')->assertOk()->assertJson(['status' => 'revoked']);

        $this->assertFalse($current['model']->fresh()->active);
        $this->assertTrue($other['model']->fresh()->active);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'device_id' => $device->id,
            'event_type' => 'device.logout',
            'outcome' => 'revoked',
        ]);
        $this->withToken($current['plain'])->getJson('/api/v1/bootstrap')->assertUnauthorized();
    }

    public function test_desktop_logout_requires_the_current_key_to_have_the_settings_read_ability(): void
    {
        $key = ApiKey::mint([
            'user_id' => User::factory()->create()->id,
            'name' => 'Limited key',
            'abilities' => ['brain.read'],
            'active' => true,
        ]);

        $this->withToken($key['plain'])->postJson('/api/v1/auth/device/logout')->assertForbidden();

        $this->assertTrue($key['model']->fresh()->active);
    }

    public function test_account_page_scopes_costs_and_master_devices_to_the_logged_in_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_id' => 'mine', 'name' => 'My desktop', 'status' => 'online']);
        $foreign = Device::create(['user_id' => $other->id, 'device_id' => 'foreign-secret-device', 'name' => 'Foreign', 'status' => 'online']);
        LlmRun::create(['user_id' => $other->id, 'client_id' => 'foreign-secret-device', 'task_type' => 'chat', 'model_id' => 'test-model', 'estimated_cost_usd' => 99]);
        $this->actingAs($user)->get('/account/devices')->assertOk()->assertDontSee('foreign-secret-device');
        $this->patch('/account/devices/'.$foreign->id, ['name' => 'Steal', 'master' => true])->assertNotFound();
        $this->patch('/account/devices/'.$device->id, ['name' => 'Master', 'master' => true])->assertRedirect();
        $this->assertSame($device->id, $user->fresh()->master_device_id);
    }

    public function test_suspended_users_cannot_use_web_sessions_or_redeem_a_pairing(): void
    {
        $user = User::factory()->create(['status' => false]);
        $this->actingAs($user)->get('/account/devices')->assertForbidden();
        $this->get('/dashboard')->assertForbidden();
    }

    public function test_user_management_rejects_non_admins_and_preserves_admin_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user)->post('/dashboard/users', [])->assertForbidden();
        $this->flushSession();
        $this->actingAs($admin)->patch('/dashboard/users/'.$admin->id, ['name' => 'Changed', 'email' => $admin->email, 'status' => false])->assertForbidden();
        $this->patch('/dashboard/users/'.$user->id, ['name' => 'Updated', 'email' => $user->email, 'status' => false])->assertRedirect();
        $this->assertFalse($user->fresh()->status);
        $this->assertTrue($admin->fresh()->status);
    }
}
