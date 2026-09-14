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

        $this->actingAs($admin)->post(route('dashboard.devices.debug.request', $device))->assertRedirect(route('admin.page', 'devices'));
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

    public function test_detailed_report_retry_redaction_downloads_and_access_boundaries(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $user = User::factory()->create(['role' => 'user', 'email_verified_at' => now()]);
        $key = ApiKey::mint(['user_id' => $user->id, 'name' => 'Debug', 'abilities' => ['device.connect'], 'active' => true]);
        $device = Device::create(['user_id' => $user->id, 'api_key_id' => $key['model']->id, 'device_id' => 'test-chat-debug', 'name' => 'Debug Test', 'status' => 'online']);
        $this->actingAs($admin)->post(route('dashboard.devices.debug.request', $device));
        $this->post(route('dashboard.devices.debug.request', $device));
        $this->assertSame(1, DeviceDebugRequest::count());
        $debug = DeviceDebugRequest::firstOrFail();
        $debug->update(['status' => 'collecting', 'claimed_at' => now()->subMinutes(3)]);
        $this->withHeader('X-Api-Key', $key['plain'])->getJson('/api/v1/devices/debug/poll?client_id=test-chat-debug')
            ->assertOk()->assertJsonPath('data.id', $debug->public_id);
        $this->getJson('/api/v1/devices/debug/poll?client_id=test-chat-debug')->assertJsonPath('data', null);
        $payload = ['client_id' => 'test-chat-debug', 'report' => ['version' => 'luczor-debug-v3',
            'consent' => ['diagnostics_enabled' => true], 'chat_trace' => ['enabled' => true, 'events' => [
                ['kind' => 'tool.response', 'data' => ['content' => 'Public response', 'password' => 'hidden-password', 'arguments' => '{"token":"hidden-token"}']],
            ]]]];
        $url = '/api/v1/devices/debug/'.$debug->public_id.'/complete';
        $this->postJson($url, $payload)->assertOk();
        $payload['report']['chat_trace']['events'] = [];
        $this->postJson($url, $payload)->assertOk();
        $this->assertCount(1, $debug->fresh()->payload['chat_trace']['events']);
        $this->assertStringNotContainsString('Public response', (string) $debug->fresh()->getRawOriginal('payload'));
        $download = $this->actingAs($admin)->get(route('dashboard.devices.debug.download', $debug));
        $download->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('Public response', $download->streamedContent());
        $this->assertStringNotContainsString('hidden-password', $download->streamedContent());
        $this->assertStringNotContainsString('hidden-token', $download->streamedContent());
        $this->get(route('admin.page', 'devices'))->assertOk()->assertSee('Bericht herunterladen');
        $bundle = $this->get(route('dashboard.devices.debug.export'));
        $bundle->assertOk();
        $this->assertSame($debug->public_id, json_decode(trim($bundle->streamedContent()), true)['id']);
        $this->flushSession();
        $this->actingAs($user)->get(route('dashboard.devices.debug.download', $debug))->assertForbidden();
        $this->get(route('dashboard.devices.debug.export'))->assertForbidden();
        $foreign = DeviceDebugRequest::create(['device_id' => Device::create(['user_id' => $admin->id, 'device_id' => 'foreign-debug', 'name' => 'Foreign Debug'])->id, 'user_id' => $admin->id, 'requested_by' => $admin->id, 'status' => 'collecting']);
        $this->postJson('/api/v1/devices/debug/'.$foreign->public_id.'/complete', $payload)->assertNotFound();
    }
}
