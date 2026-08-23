<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\LuczorAgentEventArchive;
use App\Models\LuczorMessageArchive;
use App\Models\LuczorProjectArchive;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
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

    public function test_sync_push_validates_every_item_before_writing(): void
    {
        $token = $this->token(['sync.write']);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/sync/push', [
            'client_id' => 'desktop-a',
            'projects' => [
                ['id' => 'p1', 'name' => 'Projekt'],
            ],
            'messages' => [
                [
                    'id' => 'm1',
                    'projectId' => str_repeat('p', 121),
                    'ts' => 'not-a-timestamp',
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'messages.0.projectId',
                'messages.0.ts',
            ]);

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('luczor_project_archives', 0);
        $this->assertDatabaseCount('luczor_message_archives', 0);
    }

    public function test_sync_push_enforces_bucket_and_item_size_limits(): void
    {
        $token = $this->token(['sync.write']);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/sync/push', [
            'client_id' => 'desktop-a',
            'projects' => array_fill(0, 501, ['id' => 'p1']),
            'messages' => [
                ['id' => 'm1', 'content' => str_repeat('x', 262_144)],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'projects',
                'messages.0',
            ]);

        $this->assertDatabaseCount('luczor_project_archives', 0);
        $this->assertDatabaseCount('luczor_message_archives', 0);
    }

    public function test_sync_push_rolls_back_the_complete_batch_when_a_write_fails(): void
    {
        $token = $this->token(['sync.write']);
        LuczorMessageArchive::creating(function () {
            throw new RuntimeException('Simulated archive failure.');
        });
        $this->withoutExceptionHandling();

        try {
            $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/sync/push', [
                'client_id' => 'desktop-a',
                'projects' => [
                    ['id' => 'p1', 'name' => 'Projekt'],
                ],
                'messages' => [
                    ['id' => 'm1', 'projectId' => 'p1', 'content' => 'Test'],
                ],
            ]);

            $this->fail('The simulated archive failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated archive failure.', $exception->getMessage());
        }

        $this->assertSame(0, Project::count());
        $this->assertSame(0, LuczorProjectArchive::count());
        $this->assertSame(0, LuczorMessageArchive::count());
    }

    public function test_sync_pull_uses_a_stable_per_bucket_keyset_snapshot(): void
    {
        Carbon::setTestNow('2026-08-22T12:00:00Z');
        [$user, $token] = $this->tokenFor(['sync.read']);

        foreach (['p1', 'p2', 'p3'] as $externalId) {
            LuczorProjectArchive::create([
                'user_id' => $user->id,
                'client_id' => 'desktop-a',
                'entity_type' => 'project',
                'external_id' => $externalId,
                'payload' => ['id' => $externalId],
            ]);
        }

        $firstPage = $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/sync/pull?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.projects')
            ->assertJsonPath('data.projects.0.external_id', 'p1')
            ->assertJsonPath('data.projects.1.external_id', 'p2')
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('continuation.projects.has_more', true)
            ->assertJsonPath('continuation.messages.has_more', false);

        $cursors = [];
        foreach (['projects', 'messages', 'memories', 'summaries'] as $bucket) {
            $cursors[$bucket] = $firstPage->json('continuation.'.$bucket.'.cursor');
            $this->assertIsString($cursors[$bucket]);
            $this->assertNotSame('', $cursors[$bucket]);
        }

        // This row has the same timestamp, but was inserted after the first
        // page captured its (updated_at, id) upper boundary.
        LuczorProjectArchive::create([
            'user_id' => $user->id,
            'client_id' => 'desktop-a',
            'entity_type' => 'project',
            'external_id' => 'p4',
            'payload' => ['id' => 'p4'],
        ]);

        $secondPage = $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/sync/pull?'.http_build_query([
                'limit' => 2,
                'cursors' => $cursors,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.projects')
            ->assertJsonPath('data.projects.0.external_id', 'p3')
            ->assertJsonPath('continuation.projects.has_more', false)
            ->assertJsonPath('has_more', false);

        $this->assertNotSame('p4', $secondPage->json('data.projects.0.external_id'));
    }

    public function test_sync_pull_rejects_incomplete_continuation_state(): void
    {
        [$user, $token] = $this->tokenFor(['sync.read']);
        LuczorProjectArchive::create([
            'user_id' => $user->id,
            'client_id' => 'desktop-a',
            'entity_type' => 'project',
            'external_id' => 'p1',
            'payload' => ['id' => 'p1'],
        ]);
        $cursor = $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/sync/pull?limit=1')
            ->assertOk()
            ->json('continuation.projects.cursor');

        $this->withHeader('X-Api-Key', $token)
            ->getJson('/api/v1/sync/pull?'.http_build_query([
                'cursors' => ['projects' => $cursor],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursors');
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
