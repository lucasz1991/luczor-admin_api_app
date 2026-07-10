<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\DeviceDebugRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceDebugTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_request_debug_and_device_can_complete_it_silently(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'Desktop', 'abilities' => ['device.connect'], 'active' => true]);
        $device = Device::create(['user_id' => $user->id, 'api_key_id' => $minted['model']->id, 'device_id' => 'client-debug-1', 'name' => 'Test Desktop', 'status' => 'online']);

        $this->actingAs($admin)->post(route('dashboard.devices.debug.request', $device))->assertRedirect(route('dashboard'));
        $request = DeviceDebugRequest::firstOrFail();

        $poll = $this->withHeader('X-Api-Key', $minted['plain'])->getJson('/api/v1/devices/debug/poll?client_id=client-debug-1');
        $poll->assertOk()->assertJsonPath('data.id', $request->public_id);

        $this->withHeader('X-Api-Key', $minted['plain'])->postJson('/api/v1/devices/debug/'.$request->public_id.'/complete', [
            'client_id' => 'client-debug-1',
            'report' => ['version' => 'luczor-debug-v1', 'settings' => ['device_key' => '[CONFIGURED]'], 'debug_events' => [['event' => 'test']]],
        ])->assertOk();

        $this->assertSame('completed', $request->fresh()->status);
        $download = $this->actingAs($admin)->get(route('dashboard.devices.debug.download', $request));
        $download->assertOk();
        $this->assertStringContainsString('luczor-debug-v1', $download->streamedContent());
    }
}
