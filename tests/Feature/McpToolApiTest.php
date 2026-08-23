<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\DeviceJob;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class McpToolApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        Config::set('luczor.device_jobs.private_key', $privateKey);
    }

    public function test_mcp_readonly_query_is_scoped_and_audited(): void
    {
        [$user, $token] = $this->token(['brain.read']);
        $response = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/mcp/call', [
            'server' => 'database', 'tool' => 'query_readonly', 'input' => ['query' => 'project_overview'],
        ])->assertOk()->json('data');

        $this->assertSame('completed', $response['status']);
        $this->assertSame(0, $response['output']['project_overview']['projects']);
        $this->assertDatabaseHas('tool_calls', ['id' => $response['id'], 'user_id' => $user->id, 'status' => 'completed']);
        $this->assertDatabaseHas('audit_events', ['tool_call_id' => $response['id'], 'event_type' => 'mcp.tool_called', 'outcome' => 'completed']);
    }

    public function test_mcp_browser_call_creates_only_an_owned_signed_device_job(): void
    {
        [$owner, $ownerToken] = $this->token(['device.connect', 'device.jobs.write']);
        [, $otherToken] = $this->token(['device.connect', 'device.jobs.write']);
        $this->withHeader('X-Api-Key', $ownerToken)->postJson('/api/v1/devices/register', [
            'client_id' => 'mcp-owned-device', 'name' => 'MCP Device',
        ])->assertCreated();

        $result = $this->withHeader('X-Api-Key', $ownerToken)->postJson('/api/v1/mcp/call', [
            'server' => 'browser', 'tool' => 'navigate',
            'input' => ['device_id' => 'mcp-owned-device', 'url' => 'https://example.test/docs'],
        ])->assertOk()->json('data');

        $job = DeviceJob::firstOrFail();
        $this->assertSame($owner->id, $job->user_id);
        $this->assertSame('desktop.open_url', $job->tool_profile);
        $this->assertSame('queued', $result['status']);
        $this->assertSame($job->id, $result['device_job_id']);
        $this->assertNotEmpty($job->signature);

        $this->withHeader('X-Api-Key', $otherToken)->postJson('/api/v1/mcp/call', [
            'server' => 'browser', 'tool' => 'navigate',
            'input' => ['device_id' => 'mcp-owned-device', 'url' => 'https://example.test/forbidden'],
        ])->assertNotFound();
        $this->assertSame(1, DeviceJob::count());
        $this->assertSame(2, ToolCall::count());
        $this->assertDatabaseHas('tool_calls', ['user_id' => $owner->id, 'status' => 'queued']);
        $this->assertDatabaseHas('tool_calls', ['status' => 'failed']);
    }

    /** @return array{0: User, 1: string} */
    private function token(array $abilities): array
    {
        $user = User::factory()->create();
        $minted = ApiKey::mint(['user_id' => $user->id, 'name' => 'MCP client', 'abilities' => $abilities, 'active' => true]);

        return [$user, $minted['plain']];
    }
}
