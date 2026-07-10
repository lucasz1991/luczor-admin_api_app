<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\LuczorAgentEventArchive;
use App\Models\LuczorProjectArchive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_push_is_idempotent(): void
    {
        $token = $this->token(['sync.write']);

        $payload = [
            'client_id' => 'desktop-a',
            'projects' => [
                ['id' => 'p1', 'name' => 'Projekt', 'updatedAt' => now()->toISOString()],
            ],
        ];

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/sync/push', $payload)->assertOk();
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/sync/push', $payload)->assertOk();

        $this->assertSame(1, LuczorProjectArchive::count());
    }

    public function test_agent_events_are_append_only(): void
    {
        $token = $this->token(['brain.write']);

        $payload = [
            'client_id' => 'desktop-a',
            'external_id' => 'event-1',
            'event_type' => 'tool',
            'payload' => ['name' => 'os_screen_capture'],
        ];

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/agent-events', $payload)->assertCreated();
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/agent-events', $payload)->assertCreated();

        $this->assertSame(2, LuczorAgentEventArchive::count());
    }

    public function test_sync_and_agent_runs_are_isolated_by_user_and_bound_device(): void
    {
        [$first, $firstToken] = $this->tokenFor(['sync.read', 'sync.write', 'brain.read', 'brain.write']);
        [$second, $secondToken] = $this->tokenFor(['sync.read', 'brain.read']);

        $this->withHeader('X-Api-Key', $firstToken)->postJson('/api/v1/sync/push', [
            'client_id' => 'first-device',
            'projects' => [['id' => 'first-project', 'name' => 'First']],
        ])->assertOk();

        $this->withHeader('X-Api-Key', $secondToken)->getJson('/api/v1/sync/pull')
            ->assertOk()
            ->assertJsonCount(0, 'data.projects');

        $this->withHeader('X-Api-Key', $firstToken)->postJson('/api/v1/sync/push', [
            'client_id' => 'other-device',
        ])->assertForbidden();

        $runId = $this->withHeader('X-Api-Key', $firstToken)->postJson('/api/v1/agent-runs', [
            'client_id' => 'first-device',
            'project_id' => 'first-project',
            'goal' => 'private work',
        ])->assertCreated()->json('data.id');

        $this->withHeader('X-Api-Key', $secondToken)->getJson('/api/v1/agent-runs/'.$runId)->assertNotFound();
    }

    private function token(array $abilities): string
    {
        $user = User::factory()->create();
        $minted = ApiKey::mint([
            'user_id' => $user->id,
            'name' => 'Test Device',
            'abilities' => $abilities,
            'active' => true,
        ]);

        return $minted['plain'];
    }

    /** @return array{0: User, 1: string} */
    private function tokenFor(array $abilities): array
    {
        $user = User::factory()->create();
        $minted = ApiKey::mint([
            'user_id' => $user->id,
            'name' => 'Test Device',
            'abilities' => $abilities,
            'active' => true,
        ]);

        return [$user, $minted['plain']];
    }
}
