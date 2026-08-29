<?php

namespace Tests\Feature;

use App\Jobs\ProcessMemoryProjection;
use App\Models\ApiKey;
use App\Models\MemoryLink;
use App\Models\MemoryProjectionOutbox;
use App\Models\MemoryWriteEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Cognee\CogneeClient;
use App\Services\Cognee\CogneeRequestException;
use App\Services\LuczorMemoryService;
use App\Services\MemoryOrchestrator;
use App\Services\MemoryProjectionReconciler;
use App\Services\MemoryProjectionService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MemoryOrchestratorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_memory_policy_is_enforced_inside_the_orchestrator(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        try {
            app(MemoryOrchestrator::class)->remember([
                'user_id' => $user->id,
                'scope' => 'global',
                'content' => 'Nicht kuratierter globaler Eintrag',
                'write_intent' => 'explicit',
            ]);
            $this->fail('A non-admin must not manage global memory through the orchestrator.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_global_memory_is_one_curated_version_family_across_administrators(): void
    {
        $firstAdmin = User::factory()->create(['role' => 'admin']);
        $secondAdmin = User::factory()->create(['role' => 'admin']);
        $memory = app(MemoryOrchestrator::class);

        $first = $memory->remember([
            'user_id' => $firstAdmin->id,
            'scope' => 'global',
            'external_id' => 'curated-rule-a',
            'memory_key' => 'curated.answer.rule',
            'write_id' => 'curated-write-a',
            'content' => 'Globale Regel A',
            'write_intent' => 'explicit',
        ])->link;
        $second = $memory->remember([
            'user_id' => $secondAdmin->id,
            'scope' => 'global',
            'external_id' => 'curated-rule-b',
            'memory_key' => 'curated.answer.rule',
            'write_id' => 'curated-write-b',
            'content' => 'Globale Regel B',
            'write_intent' => 'explicit',
        ])->link;

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertDatabaseHas('memory_links', ['id' => $first->id, 'status' => 'superseded']);
        $this->assertDatabaseHas('memory_links', ['id' => $second->id, 'status' => 'active']);
        $this->assertSame(1, MemoryLink::query()
            ->where('dataset', 'global:curated')
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->where('feature_key', 'curated.answer.rule')
                ->orWhere('meta->memory_key', 'curated.answer.rule'))
            ->count());

        $this->assertTrue($memory->forget('global', $second->external_id, [
            'user_id' => $secondAdmin->id,
        ]));
        $this->assertDatabaseMissing('memory_links', ['dataset' => 'global:curated']);
        $this->assertSame(2, MemoryWriteEvent::query()->where('state', 'forgotten')->count());
    }

    public function test_projection_control_calls_fit_below_job_and_redis_timeouts(): void
    {
        $job = new ProcessMemoryProjection(1);
        $requestBudget = (int) config('luczor.cognee.timeout')
            + (3 * (int) config('luczor.cognee.control_timeout'))
            + (3 * (int) config('luczor.cognee.ack_timeout'))
            + 5;

        $this->assertLessThan($job->timeout, $requestBudget);
        $this->assertGreaterThan($job->timeout, (int) config('luczor.cognee.content_lock_seconds'));
        $this->assertLessThan((int) config('queue.connections.redis.retry_after'), $job->timeout);
    }

    public function test_memory_migration_backfills_legacy_hashes_and_quarantines_projection(): void
    {
        $migration = require database_path('migrations/2026_08_23_000001_add_memory_orchestration_fields.php');
        $migration->down();

        $user = User::factory()->create();
        $summary = "  Eine   ältere\nErinnerung.  ";
        $id = DB::table('memory_links')->insertGetId([
            'user_id' => $user->id,
            'client_id' => 'legacy-device',
            'external_id' => 'legacy-memory',
            'scope' => 'project',
            'dataset' => 'legacy-project-dataset',
            'project_id' => 'p1',
            'type' => 'note',
            'visibility' => 'syncable',
            'staleness' => 'fresh',
            'importance' => 0.5,
            'summary' => $summary,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertDatabaseHas('memory_links', [
            'id' => $id,
            'content_hash' => hash('sha256', 'Eine ältere Erinnerung.'),
            'projection_status' => 'legacy_review_required',
        ]);

        DB::table('memory_links')->insert([
            'user_id' => $user->id,
            'client_id' => 'legacy-device',
            'external_id' => 'legacy-memory',
            'scope' => 'project',
            'dataset' => 'another-project-dataset',
            'project_id' => 'p2',
            'type' => 'note',
            'visibility' => 'syncable',
            'staleness' => 'fresh',
            'importance' => 0.5,
            'summary' => 'Projekt zwei.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(2, DB::table('memory_links')->count());
    }

    public function test_private_secret_and_repository_memories_are_kept_local_only(): void
    {
        [, $token] = $this->token(['brain.write']);

        foreach ([
            ['scope' => 'private', 'content' => 'Persönliche Notiz'],
            ['scope' => 'project', 'content' => 'API key ist geheim'],
            ['scope' => 'project', 'content' => 'ghp_abcdefghijklmnopqrstuvwxyz123456'],
            ['scope' => 'project', 'content' => 'Klasse Foo', 'source_type' => 'repository_graph'],
            [
                'scope' => 'project',
                'content' => 'Klasse Foo',
                'source_type' => 'user',
                'meta' => ['origin' => ['source_type' => 'repository_graph']],
            ],
            [
                'scope' => 'project',
                'content' => 'Unauffälliger Inhalt',
                'source_ref' => 'https://build-user:super-password@example.test/result/42',
            ],
            [
                'scope' => 'project',
                'content' => 'Unauffälliger Inhalt',
                'meta' => ['api_key' => 'github_pat_abcdefghijklmnopqrstuvwxyz123456'],
            ],
            [
                'scope' => 'project',
                'content' => 'Unauffälliger Inhalt',
                'provenance' => [
                    'Authorization' => 'Bearer eyJabcdefghijk.eyJabcdefghijkl.abcdefghijklmnop',
                ],
            ],
            [
                'scope' => 'project',
                'content' => 'Unauffälliger Inhalt',
                'tags' => ['release', 'xoxb-12345678901234567890'],
            ],
        ] as $index => $payload) {
            $this->withHeader('X-Api-Key', $token)
                ->withHeader('Idempotency-Key', 'local-only-policy-'.$index)
                ->postJson('/api/v1/memory/remember', $payload + ['client_id' => 'desktop-a'])
                ->assertStatus(202)
                ->assertJsonPath('decision', 'local_only')
                ->assertJsonPath('persisted', false);
        }

        $this->assertSame(0, MemoryLink::count());
        $this->assertSame(0, MemoryProjectionOutbox::count());
    }

    public function test_direct_identifiers_are_kept_in_sql_but_never_projected_to_cognee(): void
    {
        Queue::fake();
        $this->app->instance(CogneeClient::class, new CogneeClient('http://cognee:8000', 'internal-key'));
        [, $token] = $this->token(['brain.write']);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', [
            'client_id' => 'desktop-a',
            'external_id' => 'contact-preference',
            'scope' => 'project',
            'project_id' => 'p1',
            'content' => 'Kontakt max.mustermann@example.com, Telefon +49 171 1234567.',
            'write_intent' => 'explicit',
        ])->assertCreated()
            ->assertJsonPath('decision', 'accepted')
            ->assertJsonPath('targets.0', 'sql')
            ->assertJsonCount(1, 'targets')
            ->assertJsonPath('projection_status', 'not_required');

        $this->assertSame(1, MemoryLink::count());
        $this->assertSame(0, MemoryProjectionOutbox::count());
        Queue::assertNothingPushed();
    }

    public function test_store_rejects_a_sensitive_metadata_payload_when_the_orchestrator_is_bypassed(): void
    {
        $store = new LuczorMemoryService(new CogneeClient);

        try {
            $store->remember([
                'user_id' => User::factory()->create()->id,
                'scope' => 'project',
                'content' => 'Unauffälliger Inhalt',
                'meta' => ['api_key' => 'github_pat_abcdefghijklmnopqrstuvwxyz123456'],
            ]);
            $this->fail('The canonical store accepted a DLP-sensitive payload.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('memory', $error->errors());
        }

        $this->assertSame(0, MemoryLink::count());
    }

    public function test_store_rejects_nested_repository_origin_when_the_orchestrator_is_bypassed(): void
    {
        $store = new LuczorMemoryService(new CogneeClient);

        try {
            $store->remember([
                'user_id' => User::factory()->create()->id,
                'scope' => 'project',
                'content' => 'Unauffälliger Inhalt',
                'source_type' => 'user',
                'meta' => ['origin' => ['source_type' => 'repository_graph']],
            ]);
            $this->fail('The canonical store accepted a nested repository origin.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('memory', $error->errors());
        }

        $this->assertSame(0, MemoryLink::count());
    }

    public function test_recall_omits_legacy_rows_with_sensitive_metadata(): void
    {
        $user = User::factory()->create();
        $dataset = (new LuczorMemoryService(new CogneeClient))->datasetFor('project', [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]);
        foreach ([
            ['external_id' => 'safe-row', 'meta' => ['language' => 'de']],
            ['external_id' => 'unsafe-row', 'meta' => ['api_key' => 'github_pat_abcdefghijklmnopqrstuvwxyz123456']],
        ] as $row) {
            MemoryLink::create([
                'user_id' => $user->id,
                'external_id' => $row['external_id'],
                'scope' => 'project',
                'dataset' => $dataset,
                'type' => 'note',
                'visibility' => 'syncable',
                'status' => 'active',
                'retention' => 'durable',
                'staleness' => 'fresh',
                'importance' => 0.8,
                'confidence' => 0.9,
                'summary' => 'Eine normale bestätigte Präferenz.',
                'content_hash' => hash('sha256', $row['external_id']),
                'recorded_at' => now(),
                'meta' => $row['meta'],
            ]);
        }

        $hits = (new LuczorMemoryService(new CogneeClient))->recall('Präferenz', 'project', [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ], 10);

        $this->assertSame(['safe-row'], array_column($hits, 'id'));
    }

    public function test_sensitive_recall_query_never_reaches_cognee_but_keeps_sql_recall(): void
    {
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'safe-architecture-memory',
            'scope' => 'project',
            'dataset' => $dataset,
            'project_id' => 'p1',
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Die Architekturentscheidung bleibt kanonisch in SQL.',
            'content_hash' => hash('sha256', 'safe-architecture-memory'),
            'valid_from' => now(),
        ]);
        $cognee = new class extends CogneeClient
        {
            public int $searches = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function search(string $dataset, string $query, int $topK = 6): array
            {
                $this->searches++;

                return [];
            }
        };

        $hits = (new LuczorMemoryService($cognee))->recall(
            'Architektur sk-proj-abcdefghijklmnopqrstuvwxyz',
            'project',
            ['user_id' => $user->id, 'project_id' => 'p1'],
        );

        $this->assertSame(0, $cognee->searches);
        $this->assertSame('safe-architecture-memory', $hits[0]['id']);
        $this->assertSame('sql', $hits[0]['source']);

        $piiHits = (new LuczorMemoryService($cognee))->recall(
            'Welche Erinnerungen gibt es für max.mustermann@example.com und +49 171 1234567?',
            'project',
            ['user_id' => $user->id, 'project_id' => 'p1'],
        );
        $this->assertSame(0, $cognee->searches);
        $this->assertSame('safe-architecture-memory', $piiHits[0]['id']);
    }

    public function test_dlp_blocked_recall_finds_an_older_lexical_match_beyond_the_recency_window(): void
    {
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $targetSummary = 'Die ältere Entscheidung verwendet den seltenen-marker für den lokalen Ablauf.';
        MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'older-exact-lexical-memory',
            'scope' => 'project',
            'dataset' => $dataset,
            'project_id' => 'p1',
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.1,
            'confidence' => 0.9,
            'summary' => $targetSummary,
            'content_hash' => hash('sha256', $targetSummary),
            'valid_from' => now()->subYear(),
            'recorded_at' => now()->subYear(),
        ]);
        for ($index = 0; $index < 101; $index++) {
            $summary = "Neue irrelevante Notiz {$index}";
            MemoryLink::create([
                'user_id' => $user->id,
                'external_id' => "newer-noise-{$index}",
                'scope' => 'project',
                'dataset' => $dataset,
                'project_id' => 'p1',
                'type' => 'fact',
                'visibility' => 'syncable',
                'status' => 'active',
                'retention' => 'durable',
                'staleness' => 'fresh',
                'importance' => 1.0,
                'confidence' => 0.9,
                'summary' => $summary,
                'content_hash' => hash('sha256', $summary),
                'valid_from' => now(),
                'recorded_at' => now(),
            ]);
        }
        $cognee = new class extends CogneeClient
        {
            public int $searches = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function search(string $dataset, string $query, int $topK = 6): array
            {
                $this->searches++;

                return [];
            }
        };

        $hits = (new LuczorMemoryService($cognee))->recall(
            'seltenen-marker max.mustermann@example.com',
            'project',
            ['user_id' => $user->id, 'project_id' => 'p1'],
        );

        $this->assertSame(0, $cognee->searches);
        $this->assertSame('older-exact-lexical-memory', $hits[0]['id']);
        $this->assertSame('sql', $hits[0]['source']);
    }

    public function test_sql_lexical_fallback_bounds_terms_and_treats_underscore_as_a_literal(): void
    {
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $literal = 'literal_underscore_token_that_is_longer_than_fillers';
        $targetSummary = "Die ältere Entscheidung enthält {$literal}.";
        MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'literal-underscore-target',
            'scope' => 'project',
            'dataset' => $dataset,
            'project_id' => 'p1',
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.1,
            'confidence' => 0.9,
            'summary' => $targetSummary,
            'content_hash' => hash('sha256', $targetSummary),
            'valid_from' => now()->subYear(),
            'recorded_at' => now()->subYear(),
        ]);
        for ($index = 0; $index < 101; $index++) {
            $summary = 'Neue False-Positive-Notiz '.str_replace('_', 'X', $literal)." {$index}";
            MemoryLink::create([
                'user_id' => $user->id,
                'external_id' => "underscore-noise-{$index}",
                'scope' => 'project',
                'dataset' => $dataset,
                'project_id' => 'p1',
                'type' => 'fact',
                'visibility' => 'syncable',
                'status' => 'active',
                'retention' => 'durable',
                'staleness' => 'fresh',
                'importance' => 1.0,
                'confidence' => 0.9,
                'summary' => $summary,
                'content_hash' => hash('sha256', $summary),
                'valid_from' => now(),
                'recorded_at' => now(),
            ]);
        }
        $cognee = new class extends CogneeClient
        {
            public int $searches = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function search(string $dataset, string $query, int $topK = 6): array
            {
                $this->searches++;

                return [];
            }
        };
        $fillerTerms = collect(range(1, 80))
            ->map(fn (int $index): string => 't'.str_pad((string) $index, 3, '0', STR_PAD_LEFT));
        $query = collect([$literal])
            ->concat($fillerTerms)
            ->push('max.mustermann@example.com')
            ->implode(' ');

        $hits = (new LuczorMemoryService($cognee))->recall(
            $query,
            'project',
            ['user_id' => $user->id, 'project_id' => 'p1'],
        );

        $this->assertSame(0, $cognee->searches);
        $this->assertSame('literal-underscore-target', $hits[0]['id']);
    }

    public function test_sql_lexical_fallback_keeps_a_short_identifier_when_many_longer_terms_follow(): void
    {
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $targetSummary = 'Die ältere Entscheidung verwendet api als lokale Schnittstelle.';
        MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'short-api-target',
            'scope' => 'project',
            'dataset' => $dataset,
            'project_id' => 'p1',
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.1,
            'confidence' => 0.9,
            'summary' => $targetSummary,
            'content_hash' => hash('sha256', $targetSummary),
            'valid_from' => now()->subYear(),
            'recorded_at' => now()->subYear(),
        ]);
        for ($index = 0; $index < 101; $index++) {
            $summary = "Neue hoch gewichtete, aber fachfremde Notiz {$index}";
            MemoryLink::create([
                'user_id' => $user->id,
                'external_id' => "short-token-noise-{$index}",
                'scope' => 'project',
                'dataset' => $dataset,
                'project_id' => 'p1',
                'type' => 'fact',
                'visibility' => 'syncable',
                'status' => 'active',
                'retention' => 'durable',
                'staleness' => 'fresh',
                'importance' => 1.0,
                'confidence' => 0.9,
                'summary' => $summary,
                'content_hash' => hash('sha256', $summary),
                'valid_from' => now(),
                'recorded_at' => now(),
            ]);
        }
        $cognee = new class extends CogneeClient
        {
            public int $searches = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function search(string $dataset, string $query, int $topK = 6): array
            {
                $this->searches++;

                return [];
            }
        };
        $longerTerms = collect(range(1, 25))
            ->map(fn (int $index): string => 'ausfuehrlicherfuellbegriff'.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
        $query = collect(['api'])
            ->concat($longerTerms)
            ->push('max.mustermann@example.com')
            ->implode(' ');

        $hits = (new LuczorMemoryService($cognee))->recall(
            $query,
            'project',
            ['user_id' => $user->id, 'project_id' => 'p1'],
        );

        $this->assertSame(0, $cognee->searches);
        $this->assertSame('short-api-target', $hits[0]['id']);
    }

    public function test_automatic_write_is_a_candidate_until_explicitly_promoted(): void
    {
        [, $token] = $this->token(['brain.write', 'brain.read']);

        $id = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', [
            'client_id' => 'desktop-a',
            'external_id' => 'candidate-1',
            'scope' => 'project',
            'project_id' => 'p1',
            'content' => 'Der Benutzer scheint kurze Antworten zu bevorzugen.',
            'write_intent' => 'inferred',
        ])->assertStatus(202)
            ->assertJsonPath('decision', 'candidate')
            ->assertJsonPath('status', 'candidate')
            ->json('id');

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/recall', [
            'scope' => 'project', 'project_id' => 'p1', 'query' => 'kurze Antworten',
        ])->assertOk()->assertJsonCount(0, 'data');

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/promote', [
            'external_id' => $id, 'project_id' => 'p1',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/recall', [
            'scope' => 'project', 'project_id' => 'p1', 'query' => 'kurze Antworten',
        ])->assertOk()->assertJsonPath('data.0.id', 'candidate-1');
    }

    public function test_versioned_candidate_is_promoted_by_logical_id_and_supersedes_its_predecessor(): void
    {
        [, $token] = $this->token(['brain.write', 'brain.read']);
        $base = [
            'client_id' => 'desktop-a',
            'external_id' => 'candidate-family',
            'scope' => 'project',
            'project_id' => 'p1',
        ];
        $firstId = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Bestätigte erste Version ohne Memory-Key.',
            'write_intent' => 'explicit',
            'write_id' => 'candidate-family-write-a',
        ])->assertCreated()->json('memory_link_id');
        $candidateId = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Abgeleitete zweite Version ohne Memory-Key.',
            'write_intent' => 'inferred',
            'write_id' => 'candidate-family-write-b',
            'expected_previous_id' => $firstId,
        ])->assertAccepted()->json('memory_link_id');
        $candidate = MemoryLink::query()->findOrFail($candidateId);
        $newestCandidateId = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Neueste abgeleitete Version ohne Memory-Key.',
            'write_intent' => 'inferred',
            'write_id' => 'candidate-family-write-c',
            'expected_previous_id' => $firstId,
        ])->assertAccepted()->json('memory_link_id');
        $newestCandidate = MemoryLink::query()->findOrFail($newestCandidateId);

        $this->assertNotSame('candidate-family', $candidate->external_id);
        $this->assertSame('candidate-family', $candidate->logicalExternalId());
        $this->assertSame($firstId, $candidate->supersedes_id);
        $this->assertSame('candidate-family', $newestCandidate->logicalExternalId());
        $this->assertSame($firstId, $newestCandidate->supersedes_id);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/promote', [
            'external_id' => 'candidate-family',
            'scope' => 'project',
            'project_id' => 'p1',
            'client_id' => 'desktop-a',
        ])->assertOk()
            ->assertJsonPath('data.id', 'candidate-family')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('memory_links', ['id' => $firstId, 'status' => 'superseded']);
        $this->assertDatabaseHas('memory_links', ['id' => $candidateId, 'status' => 'superseded']);
        $this->assertDatabaseHas('memory_links', ['id' => $newestCandidateId, 'status' => 'active']);
        $this->assertSame(1, MemoryLink::query()->where('status', 'active')->count());
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/recall', [
            'scope' => 'project',
            'project_id' => 'p1',
            'query' => 'Neueste abgeleitete Version',
        ])->assertOk()->assertJsonPath('data.0.content', 'Neueste abgeleitete Version ohne Memory-Key.');
    }

    public function test_logical_promotion_rereads_the_latest_candidate_after_acquiring_identity_locks(): void
    {
        $user = User::factory()->create();
        $store = new LuczorMemoryService(new CogneeClient);
        $dataset = $store->datasetFor('project', [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]);
        $base = [
            'user_id' => $user->id,
            'client_id' => 'desktop-a',
            'scope' => 'project',
            'dataset' => $dataset,
            'project_id' => 'p1',
            'type' => 'preference',
            'visibility' => 'syncable',
            'status' => 'candidate',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'projection_status' => 'not_required',
            'meta' => ['source_external_id' => 'promotion-race'],
        ];
        $stale = MemoryLink::create($base + [
            'external_id' => 'promotion-race.v.stale',
            'summary' => 'Stale proposal selected before waiting.',
            'content_hash' => hash('sha256', 'stale-proposal'),
        ]);
        $injected = null;
        $eventName = 'eloquent.retrieved: '.MemoryLink::class;
        Event::listen($eventName, function (MemoryLink $retrieved) use (&$injected, $stale, $base): void {
            if ($injected !== null || $retrieved->id !== $stale->id) {
                return;
            }
            $injected = MemoryLink::create($base + [
                'external_id' => 'promotion-race.v.newest',
                'summary' => 'Newest proposal committed before the identity lock.',
                'content_hash' => hash('sha256', 'newest-proposal'),
            ]);
        });

        try {
            $promoted = $store->promote('promotion-race', [
                'scope' => 'project',
                'user_id' => $user->id,
                'client_id' => 'desktop-a',
                'project_id' => 'p1',
            ]);
        } finally {
            Event::forget($eventName);
        }

        $this->assertNotNull($injected);
        $this->assertSame($injected->id, $promoted?->id);
        $this->assertDatabaseHas('memory_links', ['id' => $stale->id, 'status' => 'superseded']);
        $this->assertDatabaseHas('memory_links', ['id' => $injected->id, 'status' => 'active']);
    }

    public function test_same_external_id_is_independent_between_project_datasets(): void
    {
        [$user, $token] = $this->token(['brain.write', 'brain.read']);
        $base = [
            'client_id' => 'desktop-a',
            'external_id' => 'shared-external-id',
            'scope' => 'project',
            'content' => 'Dieselbe Quell-ID darf in zwei Projekten vorkommen.',
            'write_intent' => 'explicit',
        ];

        foreach (['p1', 'p2'] as $projectId) {
            $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
                'project_id' => $projectId,
            ])->assertCreated()->assertJsonPath('id', 'shared-external-id');
        }

        $links = MemoryLink::query()->where('user_id', $user->id)->orderBy('project_id')->get();
        $this->assertCount(2, $links);
        $this->assertSame(['p1', 'p2'], $links->pluck('project_id')->all());
        $this->assertSame(2, $links->pluck('dataset')->unique()->count());
        $this->assertSame(['shared-external-id', 'shared-external-id'], $links->pluck('external_id')->all());
    }

    public function test_promotion_targets_only_the_requested_project_and_client_dataset(): void
    {
        [, $token] = $this->token(['brain.write']);
        $base = [
            'client_id' => 'desktop-a',
            'external_id' => 'candidate-in-two-projects',
            'scope' => 'project',
            'content' => 'Diese abgeleitete Regel muss erst bestätigt werden.',
            'write_intent' => 'inferred',
        ];

        foreach (['p1', 'p2'] as $projectId) {
            $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
                'project_id' => $projectId,
            ])->assertAccepted();
        }

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/promote', [
            'external_id' => 'candidate-in-two-projects',
            'scope' => 'project',
            'project_id' => 'p2',
            'client_id' => 'desktop-a',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('memory_links', [
            'project_id' => 'p1',
            'external_id' => 'candidate-in-two-projects',
            'client_id' => 'desktop-a',
            'status' => 'candidate',
        ]);
        $this->assertDatabaseHas('memory_links', [
            'project_id' => 'p2',
            'external_id' => 'candidate-in-two-projects',
            'client_id' => 'desktop-a',
            'status' => 'active',
        ]);
    }

    public function test_legacy_candidates_with_secret_or_local_repository_origin_cannot_be_promoted(): void
    {
        [$user, $token] = $this->token(['brain.write']);
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        MemoryLink::create([
            'user_id' => $user->id,
            'client_id' => 'desktop-a',
            'external_id' => 'unsafe-legacy-candidate',
            'scope' => 'project',
            'dataset' => $dataset,
            'project_id' => 'p1',
            'type' => 'note',
            'visibility' => 'syncable',
            'status' => 'candidate',
            'retention' => 'durable',
            'sensitivity' => 'normal',
            'staleness' => 'fresh',
            'importance' => 0.5,
            'confidence' => 0.35,
            'summary' => 'Ein lokal analysiertes Repository-Detail.',
            'content_hash' => hash('sha256', 'Ein lokal analysiertes Repository-Detail.'),
            'source_type' => 'user',
            'meta' => ['origin' => ['source_type' => 'repository_graph']],
            'projection_status' => 'not_required',
        ]);
        MemoryLink::create([
            'user_id' => $user->id,
            'client_id' => 'desktop-a',
            'external_id' => 'unsafe-secret-candidate',
            'scope' => 'project',
            'dataset' => $dataset,
            'project_id' => 'p1',
            'type' => 'note',
            'visibility' => 'syncable',
            'status' => 'candidate',
            'retention' => 'durable',
            'sensitivity' => 'secret',
            'staleness' => 'fresh',
            'importance' => 0.5,
            'confidence' => 0.35,
            'summary' => 'Nicht extern freigeben.',
            'content_hash' => hash('sha256', 'Nicht extern freigeben.'),
            'source_type' => 'user',
            'projection_status' => 'not_required',
        ]);

        foreach (['unsafe-legacy-candidate', 'unsafe-secret-candidate'] as $externalId) {
            $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/promote', [
                'external_id' => $externalId,
                'scope' => 'project',
                'project_id' => 'p1',
                'client_id' => 'desktop-a',
            ])->assertUnprocessable()->assertJsonValidationErrors('memory');
        }

        $this->assertDatabaseHas('memory_links', [
            'external_id' => 'unsafe-legacy-candidate',
            'status' => 'candidate',
            'projection_status' => 'not_required',
        ]);
        $this->assertDatabaseHas('memory_links', [
            'external_id' => 'unsafe-secret-candidate',
            'status' => 'candidate',
            'projection_status' => 'not_required',
        ]);
        $this->assertSame(0, MemoryProjectionOutbox::count());
    }

    public function test_dataset_and_recall_are_isolated_per_tenant_and_user(): void
    {
        $tenant = Tenant::create(['name' => 'Team', 'slug' => 'team', 'status' => 'active']);
        [$first, $firstToken] = $this->token(['brain.write', 'brain.read'], ['tenant_id' => $tenant->id]);
        [$second, $secondToken] = $this->token(['brain.write', 'brain.read'], ['tenant_id' => $tenant->id]);

        $this->withHeader('X-Api-Key', $firstToken)->postJson('/api/v1/memory/remember', [
            'client_id' => 'desktop-first',
            'scope' => 'agent',
            'agent_id' => 'planner',
            'content' => 'Nur Benutzer eins darf das lesen.',
            'write_intent' => 'explicit',
            'write_id' => 'tenant-user-isolation-write',
        ])->assertCreated();

        $link = MemoryLink::firstOrFail();
        $this->assertStringStartsWith('luczor:v2:agent:', $link->dataset);
        $this->assertStringNotContainsString('tenant:'.$tenant->id, $link->dataset);
        $this->assertStringNotContainsString('user:'.$first->id, $link->dataset);
        $this->assertStringNotContainsString('planner', $link->dataset);
        $this->assertNotSame($link->dataset, app(LuczorMemoryService::class)->datasetFor('agent', [
            'tenant_id' => $tenant->id,
            'user_id' => $second->id,
            'agent_id' => 'planner',
        ]));

        $this->withHeader('X-Api-Key', $secondToken)->postJson('/api/v1/memory/recall', [
            'scope' => 'agent', 'agent_id' => 'planner', 'query' => 'Benutzer eins',
        ])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_rotated_namespace_key_updates_and_forgets_the_existing_alias_family(): void
    {
        config([
            'luczor.memory.namespace_key' => 'old-stable-memory-namespace-key-32-bytes',
            'luczor.memory.previous_namespace_keys' => [],
        ]);
        [$user, $token] = $this->token(['brain.write', 'brain.read']);
        $base = [
            'client_id' => 'desktop-a',
            'external_id' => 'rotating-rule',
            'scope' => 'project',
            'project_id' => 'p1',
            'feature_key' => 'answer.rotation',
            'write_intent' => 'explicit',
        ];
        $firstId = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Version vor der Schlüsselrotation.',
            'write_id' => 'rotation-write-a',
            'expected_previous_id' => null,
        ])->assertCreated()->json('memory_link_id');
        $oldDataset = MemoryLink::query()->findOrFail($firstId)->dataset;

        config([
            'luczor.memory.namespace_key' => 'new-stable-memory-namespace-key-32-bytes',
            'luczor.memory.previous_namespace_keys' => ['old-stable-memory-namespace-key-32-bytes'],
        ]);
        $secondId = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Version nach der Schlüsselrotation.',
            'write_id' => 'rotation-write-b',
            'expected_previous_id' => $firstId,
        ])->assertCreated()->json('memory_link_id');
        $newDataset = MemoryLink::query()->findOrFail($secondId)->dataset;

        $this->assertNotSame($oldDataset, $newDataset);
        $this->assertDatabaseHas('memory_links', ['id' => $firstId, 'status' => 'superseded']);
        $this->assertDatabaseHas('memory_links', ['id' => $secondId, 'status' => 'active']);
        $this->assertSame(1, MemoryLink::query()->where('status', 'active')->count());
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/recall', [
            'scope' => 'project',
            'project_id' => 'p1',
            'query' => 'Schlüsselrotation',
        ])->assertOk()->assertJsonPath('data.0.id', 'rotating-rule');

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/forget', [
            'scope' => 'project',
            'project_id' => 'p1',
            'external_id' => 'rotating-rule',
        ])->assertOk()->assertJsonPath('forgotten', true);
        $this->assertSame(0, MemoryLink::query()->where('user_id', $user->id)->count());
    }

    public function test_semantic_recall_searches_the_previous_opaque_dataset_after_key_rotation(): void
    {
        config([
            'luczor.memory.namespace_key' => 'old-semantic-memory-namespace-key-32b',
            'luczor.memory.previous_namespace_keys' => [],
        ]);
        $user = User::factory()->create();
        $store = new LuczorMemoryService(new CogneeClient);
        $oldDataset = $store->datasetFor('project', [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]);
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'pre-rotation-semantic',
            'scope' => 'project',
            'dataset' => $oldDataset,
            'project_id' => 'p1',
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Eine fachlich passende, aber lexikalisch andere Entscheidung.',
            'content_hash' => hash('sha256', 'pre-rotation-semantic-content'),
            'cognee_memory_id' => $dataId,
            'projection_status' => 'ready',
            'valid_from' => now(),
        ]);
        config([
            'luczor.memory.namespace_key' => 'new-semantic-memory-namespace-key-32b',
            'luczor.memory.previous_namespace_keys' => ['old-semantic-memory-namespace-key-32b'],
        ]);
        $body = json_encode([[
            'dataset_name' => $oldDataset,
            'search_result' => [[
                'document_id' => $dataId,
                'text' => $link->summary,
            ]],
        ]], JSON_THROW_ON_ERROR);
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], $body),
        ]))]);
        $rotatedStore = new LuczorMemoryService(new CogneeClient('http://cognee:8000', 'key', 15, $http));

        $hits = $rotatedStore->recall('orthogonaler-query-token', 'project', [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]);

        $this->assertSame('pre-rotation-semantic', $hits[0]['id']);
        $this->assertSame('cognee_revalidated', $hits[0]['source']);
    }

    public function test_semantic_recall_batches_aliases_and_fails_fast_to_sql_after_one_provider_failure(): void
    {
        $user = User::factory()->create();
        $ids = ['user_id' => $user->id, 'project_id' => 'p1'];
        $store = new LuczorMemoryService(new CogneeClient);
        $opaqueDataset = $store->datasetFor('project', $ids);
        $versionOneDataset = "tenant:personal:user:{$user->id}:project:p1";

        MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'sql-fallback-target',
            'scope' => 'project',
            'dataset' => $opaqueDataset,
            'project_id' => 'p1',
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Ausfallsicherer SQL Rückfall für die Erinnerungssuche.',
            'content_hash' => hash('sha256', 'sql-fallback-target'),
            'cognee_memory_id' => '09393f2c-238c-4db8-853e-7e71fa2bd9bd',
            'valid_from' => now()->subMinute(),
        ]);
        MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'occupied-legacy-alias',
            'scope' => 'project',
            'dataset' => $versionOneDataset,
            'project_id' => 'p1',
            'type' => 'note',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.4,
            'confidence' => 0.8,
            'summary' => 'Belegte ältere Alias-Projektion.',
            'content_hash' => hash('sha256', 'occupied-legacy-alias'),
            'cognee_memory_id' => 'ad171c24-a52d-4f92-869f-4553b52684e5',
            'valid_from' => now()->subMinute(),
        ]);

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(504, ['Content-Type' => 'application/json'], '{"detail":"timeout"}'),
        ]));
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack]);
        $store = new LuczorMemoryService(new CogneeClient('http://cognee:8000', 'key', 45, $http));

        $hits = $store->recall('ausfallsicherer SQL Rückfall', 'project', $ids);

        $this->assertCount(1, $history);
        $requestPayload = json_decode(
            (string) $history[0]['request']->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $requestedDatasets = $requestPayload['datasets'];
        sort($requestedDatasets);
        $expectedDatasets = [$opaqueDataset, $versionOneDataset];
        sort($expectedDatasets);
        $this->assertSame($expectedDatasets, $requestedDatasets);
        $this->assertSame(3, $history[0]['options']['timeout']);
        $this->assertSame('sql-fallback-target', $hits[0]['id']);
        $this->assertSame('sql', $hits[0]['source']);
    }

    public function test_first_v2_write_atomically_supersedes_a_version_one_memory(): void
    {
        [$user, $token] = $this->token(['brain.write', 'brain.read']);
        $legacy = MemoryLink::create([
            'user_id' => $user->id,
            'client_id' => 'desktop-a',
            'external_id' => 'legacy-rule',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'project_id' => 'p1',
            'feature_key' => 'answer.legacy',
            'type' => 'preference',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Alte Version vor opaken Datasets.',
            'content_hash' => hash('sha256', 'Alte Version vor opaken Datasets.'),
            'recorded_at' => now(),
            'projection_status' => 'not_required',
        ]);

        $newId = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', [
            'client_id' => 'desktop-a',
            'external_id' => 'legacy-rule',
            'scope' => 'project',
            'project_id' => 'p1',
            'feature_key' => 'answer.legacy',
            'content' => 'Neue Version im opaken Dataset.',
            'write_intent' => 'explicit',
            'write_id' => 'legacy-to-v2-write',
            'expected_previous_id' => $legacy->id,
        ])->assertCreated()->json('memory_link_id');

        $this->assertDatabaseHas('memory_links', ['id' => $legacy->id, 'status' => 'superseded']);
        $this->assertDatabaseHas('memory_links', ['id' => $newId, 'status' => 'active']);
        $this->assertStringStartsWith('luczor:v2:project:', MemoryLink::query()->findOrFail($newId)->dataset);
        $this->assertSame(1, MemoryLink::query()
            ->where('user_id', $user->id)
            ->where('feature_key', 'answer.legacy')
            ->where('status', 'active')
            ->count());
    }

    public function test_forget_is_user_scoped_across_devices_and_idempotent(): void
    {
        [$user, $firstToken] = $this->token(['brain.write', 'brain.read']);
        $second = ApiKey::mint([
            'user_id' => $user->id,
            'name' => 'Second Device',
            'abilities' => ['brain.write', 'brain.read'],
            'active' => true,
        ]);

        $this->withHeader('X-Api-Key', $firstToken)->postJson('/api/v1/memory/remember', [
            'client_id' => 'desktop-a',
            'external_id' => 'shared-user-memory',
            'scope' => 'user',
            'content' => 'Diese Erinnerung ist auf allen Geräten löschbar.',
            'write_intent' => 'explicit',
        ])->assertCreated();

        $payload = [
            'client_id' => 'desktop-b',
            'external_id' => 'shared-user-memory',
            'scope' => 'user',
        ];
        $this->withHeader('X-Api-Key', $second['plain'])->postJson('/api/v1/memory/forget', $payload)
            ->assertOk()
            ->assertJsonPath('forgotten', true)
            ->assertJsonPath('already_absent', false);
        $this->withHeader('X-Api-Key', $second['plain'])->postJson('/api/v1/memory/forget', $payload)
            ->assertOk()
            ->assertJsonPath('forgotten', false)
            ->assertJsonPath('already_absent', true);
    }

    public function test_forget_keeps_a_durable_delete_intent_while_cognee_is_disabled(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'projected-before-cognee-disable',
            'scope' => 'project',
            'dataset' => $dataset,
            'project_id' => 'p1',
            'type' => 'fact',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Diese Projektion entstand vor dem Abschalten von Cognee.',
            'content_hash' => hash('sha256', 'projected-before-cognee-disable'),
            'valid_from' => now(),
            'cognee_memory_id' => $dataId,
            'projection_status' => 'ready',
        ]);
        $store = new LuczorMemoryService(new CogneeClient);

        $this->assertTrue($store->forget('project', $link->external_id, [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]));

        $this->assertDatabaseMissing('memory_links', ['id' => $link->id]);
        $delete = MemoryProjectionOutbox::query()->where('action', 'delete')->firstOrFail();
        $this->assertSame($dataId, $delete->payload['cognee_memory_id']);
        $this->assertSame('queued', $delete->status);
        Queue::assertPushed(ProcessMemoryProjection::class, fn (ProcessMemoryProjection $job) => $job->outboxId === $delete->id);
    }

    public function test_server_owned_provenance_fields_cannot_be_spoofed(): void
    {
        [$user, $token] = $this->token(['brain.write']);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', [
            'client_id' => 'desktop-a',
            'external_id' => 'trusted-provenance',
            'scope' => 'user',
            'content' => 'Eine bestätigte Präferenz.',
            'write_intent' => 'explicit',
            'source_type' => 'user',
            'provenance' => [
                'actor_user_id' => 999999,
                'source_type' => 'repository_graph',
                'captured_at' => '2000-01-01T00:00:00Z',
                'policy_version' => 'attacker-policy',
                'client_note' => 'allowed',
            ],
        ])->assertCreated();

        $provenance = MemoryLink::query()->where('external_id', 'trusted-provenance')->firstOrFail()->provenance;
        $this->assertSame($user->id, $provenance['actor_user_id']);
        $this->assertSame('user', $provenance['source_type']);
        $this->assertSame('memory-policy.v2', $provenance['policy_version']);
        $this->assertNotSame('2000-01-01T00:00:00Z', $provenance['captured_at']);
        $this->assertSame('allowed', $provenance['client_note']);
    }

    public function test_workspace_and_session_recall_do_not_fall_back_to_legacy_private_memory(): void
    {
        $tenant = Tenant::create(['name' => 'Team', 'slug' => 'team-memory', 'status' => 'active']);
        [$user, $token] = $this->token(['brain.read'], ['tenant_id' => $tenant->id]);
        MemoryLink::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'external_id' => 'legacy-private',
            'scope' => 'user',
            'dataset' => "user:{$user->id}:private",
            'type' => 'preference',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Nur meine alte private Präferenz.',
            'content_hash' => hash('sha256', 'Nur meine alte private Präferenz.'),
            'recorded_at' => now(),
            'projection_status' => 'not_required',
        ]);

        foreach (['workspace', 'session'] as $scope) {
            $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/recall', [
                'scope' => $scope,
                'query' => 'private Präferenz',
            ])->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_correction_supersedes_the_previous_version_and_is_idempotent(): void
    {
        [, $token] = $this->token(['brain.write', 'brain.read']);
        $base = [
            'client_id' => 'desktop-a',
            'external_id' => 'rule-1',
            'scope' => 'project',
            'project_id' => 'p1',
            'feature_key' => 'answer.language',
            'write_intent' => 'explicit',
        ];

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Antworte auf Englisch.',
            'write_id' => 'language-write-a',
        ])->assertCreated();
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Antworte auf Deutsch.',
            'write_id' => 'language-write-b',
        ])->assertCreated();

        $this->assertSame(2, MemoryLink::count());
        $this->assertSame('superseded', MemoryLink::oldest('id')->first()->status);
        $this->assertSame('active', MemoryLink::latest('id')->first()->status);

        // A retry after a lost HTTP response repeats the original source ID
        // and content. It must resolve to the already committed correction.
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', array_merge($base, [
            'content' => 'Antworte auf Deutsch.',
            'write_id' => 'language-write-b',
        ]))->assertCreated();
        $this->assertSame(2, MemoryLink::count());

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Antworte knapp auf Deutsch.',
            'write_id' => 'language-write-c',
        ])->assertCreated();
        $this->assertSame(3, MemoryLink::count());
        $this->assertSame(2, MemoryLink::query()->where('status', 'superseded')->count());
        $this->assertSame('active', MemoryLink::latest('id')->first()->status);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/recall', [
            'scope' => 'project', 'project_id' => 'p1', 'query' => 'Antworte',
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.content', 'Antworte knapp auf Deutsch.');

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/forget', [
            'client_id' => 'desktop-a',
            'external_id' => 'rule-1',
            'scope' => 'project',
            'project_id' => 'p1',
        ])->assertOk()->assertJsonPath('forgotten', true);
        $this->assertSame(0, MemoryLink::count());
    }

    public function test_explicit_write_events_support_a_b_a_revert_and_reject_stale_replay(): void
    {
        [, $token] = $this->token(['brain.write', 'brain.read']);
        $base = [
            'client_id' => 'desktop-a',
            'external_id' => 'revertible-rule',
            'scope' => 'project',
            'project_id' => 'p1',
            'feature_key' => 'answer.tone',
            'write_intent' => 'explicit',
        ];

        $first = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Antworte sachlich.',
            'write_id' => 'write-event-a',
            'expected_previous_id' => null,
        ])->assertCreated()->json('memory_link_id');
        $second = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Antworte locker.',
            'write_id' => 'write-event-b',
            'expected_previous_id' => $first,
        ])->assertCreated()->json('memory_link_id');
        $third = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Antworte sachlich.',
            'write_id' => 'write-event-c',
            'expected_previous_id' => $second,
        ])->assertCreated()->json('memory_link_id');

        $this->assertNotSame($first, $third);
        $this->assertSame(3, MemoryLink::count());
        $this->assertSame(2, MemoryLink::query()->where('status', 'superseded')->count());
        $this->assertDatabaseHas('memory_links', [
            'id' => $third,
            'status' => 'active',
            'summary' => 'Antworte sachlich.',
            'supersedes_id' => $second,
        ]);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Veralteter paralleler Schreibversuch.',
            'write_id' => 'write-event-stale-cas',
            'expected_previous_id' => $first,
        ])->assertConflict()
            ->assertJsonPath('current_memory_id', $third)
            ->assertJsonPath('source_record_id', $third);

        // The original event may be retried late after a lost response, but it
        // must not masquerade as the current state or undo the explicit revert.
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Antworte sachlich.',
            'write_id' => 'write-event-a',
            'expected_previous_id' => null,
        ])->assertConflict();
        $this->assertSame(3, MemoryLink::count());
        $this->assertDatabaseHas('memory_links', ['id' => $third, 'status' => 'active']);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Ein anderer Inhalt mit derselben Event-ID.',
            'write_id' => 'write-event-c',
            'expected_previous_id' => $second,
        ])->assertConflict();
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', array_merge($base, [
            'external_id' => 'another-logical-memory',
            'feature_key' => 'answer.formality',
            'content' => 'Antworte sachlich.',
            'write_id' => 'write-event-c',
            'expected_previous_id' => $second,
        ]))->assertConflict();
    }

    public function test_context_update_forwards_write_events_and_expected_version_for_a_b_a_revert(): void
    {
        [, $token] = $this->token(['brain.write']);
        $base = [
            'project_id' => 'p1',
            'feature_key' => 'context.answer.tone',
        ];

        $first = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/context/update-memory', $base + [
            'content' => 'Kontextregel A',
            'write_id' => 'context-write-a',
            'expected_previous_id' => null,
        ])->assertCreated()->json('data.memory_link_id');
        $second = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/context/update-memory', $base + [
            'content' => 'Kontextregel B',
            'write_id' => 'context-write-b',
            'expected_previous_id' => $first,
        ])->assertCreated()->json('data.memory_link_id');
        $third = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/context/update-memory', $base + [
            'content' => 'Kontextregel A',
            'write_id' => 'context-write-c',
            'expected_previous_id' => $second,
        ])->assertCreated()->json('data.memory_link_id');

        $this->assertNotSame($first, $third);
        $this->assertDatabaseHas('memory_links', [
            'id' => $third,
            'status' => 'active',
            'summary' => 'Kontextregel A',
            'supersedes_id' => $second,
        ]);
        $this->assertSame(2, MemoryLink::query()
            ->where('feature_key', 'context.answer.tone')
            ->where('status', 'superseded')
            ->count());
    }

    public function test_write_event_without_external_id_is_idempotent_across_retries(): void
    {
        [, $token] = $this->token(['brain.write']);
        $payload = [
            'client_id' => 'desktop-a',
            'scope' => 'project',
            'project_id' => 'p1',
            'feature_key' => 'answer.retry',
            'content' => 'Ein verlorener HTTP-Erfolg darf sicher wiederholt werden.',
            'write_intent' => 'explicit',
            'write_id' => 'write-without-external-id',
        ];

        $first = $this->withHeader('X-Api-Key', $token)
            ->postJson('/api/v1/memory/remember', $payload)
            ->assertCreated()
            ->json('memory_link_id');
        $second = $this->withHeader('X-Api-Key', $token)
            ->postJson('/api/v1/memory/remember', $payload)
            ->assertCreated()
            ->json('memory_link_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, MemoryLink::query()->where('feature_key', 'answer.retry')->count());
    }

    public function test_write_event_fingerprint_rejects_changed_metadata_but_accepts_key_reordering(): void
    {
        [, $token] = $this->token(['brain.write']);
        $payload = [
            'client_id' => 'desktop-a',
            'external_id' => 'metadata-contract',
            'scope' => 'project',
            'project_id' => 'p1',
            'content' => 'Metadaten gehören zum Write-Vertrag.',
            'write_intent' => 'explicit',
            'write_id' => 'metadata-contract-write',
            'meta' => ['nested' => ['alpha' => 1, 'beta' => 2]],
            'tags' => ['memory', 'contract'],
            'provenance' => ['channel' => 'desktop', 'version' => 1],
        ];

        $firstId = $this->withHeader('X-Api-Key', $token)
            ->postJson('/api/v1/memory/remember', $payload)
            ->assertCreated()
            ->json('memory_link_id');
        $reordered = $payload;
        $reordered['meta'] = ['nested' => ['beta' => 2, 'alpha' => 1]];
        $reordered['provenance'] = ['version' => 1, 'channel' => 'desktop'];
        $this->withHeader('X-Api-Key', $token)
            ->postJson('/api/v1/memory/remember', $reordered)
            ->assertCreated()
            ->assertJsonPath('memory_link_id', $firstId);

        $changed = $payload;
        $changed['meta']['nested']['beta'] = 3;
        $this->withHeader('X-Api-Key', $token)
            ->postJson('/api/v1/memory/remember', $changed)
            ->assertConflict();
        $this->assertSame(1, MemoryLink::query()->where('external_id', 'metadata-contract')->count());
    }

    public function test_write_event_retry_is_independent_of_current_cognee_availability(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $store = new LuczorMemoryService(new CogneeClient('http://cognee:8000', 'key'));
        $payload = [
            'user_id' => $user->id,
            'client_id' => 'desktop-a',
            'external_id' => 'availability-stable-write',
            'scope' => 'project',
            'project_id' => 'p1',
            'content' => 'Die Schreibabsicht bleibt bei einem Provider-Ausfall identisch.',
            'status' => 'active',
            'retention' => 'durable',
            'write_id' => 'availability-stable-event',
            'project_to_cognee' => true,
        ];

        $first = $store->remember($payload);
        $retry = $store->remember(array_merge($payload, ['project_to_cognee' => false]));

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, MemoryLink::query()->where('external_id', 'availability-stable-write')->count());
    }

    public function test_forgotten_write_event_is_tombstoned_and_cannot_resurrect_memory(): void
    {
        [$user, $token] = $this->token(['brain.write']);
        $payload = [
            'client_id' => 'desktop-a',
            'external_id' => 'privacy-delete',
            'scope' => 'project',
            'project_id' => 'p1',
            'content' => 'Diese Erinnerung soll endgültig vergessen werden.',
            'write_intent' => 'explicit',
            'write_id' => 'privacy-delete-write-a',
        ];

        $this->withHeader('X-Api-Key', $token)
            ->postJson('/api/v1/memory/remember', $payload)
            ->assertCreated();
        $this->withHeader('X-Api-Key', $token)
            ->postJson('/api/v1/memory/forget', [
                'external_id' => 'privacy-delete',
                'scope' => 'project',
                'project_id' => 'p1',
            ])
            ->assertOk()
            ->assertJsonPath('forgotten', true);

        $this->assertSame(0, MemoryLink::count());
        $this->assertSame('forgotten', MemoryWriteEvent::query()->sole()->state);
        $this->assertNull(MemoryWriteEvent::query()->sole()->memory_link_id);

        $this->withHeader('X-Api-Key', $token)
            ->postJson('/api/v1/memory/remember', $payload)
            ->assertConflict();
        $secondDevice = ApiKey::mint([
            'user_id' => $user->id,
            'name' => 'Replacement Device',
            'abilities' => ['brain.write'],
            'active' => true,
        ]);
        $this->withHeader('X-Api-Key', $secondDevice['plain'])
            ->postJson('/api/v1/memory/remember', array_merge($payload, ['client_id' => 'desktop-after-reinstall']))
            ->assertConflict();
        $this->assertSame(0, MemoryLink::count());
    }

    public function test_delayed_legacy_v1_retry_cannot_resurrect_a_forgotten_memory_after_ledger_cutover(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $service = new LuczorMemoryService(new CogneeClient('http://cognee:8000', 'key'));
        $payload = [
            'user_id' => $user->id,
            'client_id' => 'legacy-desktop',
            'external_id' => 'legacy-cutover-forget',
            'scope' => 'project',
            'project_id' => 'p1',
            'content' => 'Dieser alte Retry darf die vergessene Erinnerung nicht wiederherstellen.',
            'status' => 'active',
            'retention' => 'durable',
            'write_id' => 'legacy-cutover-forget-write',
            'project_to_cognee' => false,
        ];

        $service->remember($payload);
        $this->assertTrue($service->forget('project', 'legacy-cutover-forget', [
            'tenant_id' => null,
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]));

        $legacyIdempotency = hash('sha256', json_encode([
            'user_id' => $user->id,
            'scope' => 'project',
            'namespace' => [
                'tenant_id' => 'personal',
                'user_id' => $user->id,
                'project_id' => 'p1',
            ],
            'write_id' => 'legacy-cutover-forget-write',
        ], JSON_THROW_ON_ERROR));
        $contentHash = hash('sha256', preg_replace('/\s+/u', ' ', $payload['content']) ?? $payload['content']);
        $fingerprintInput = [
            'external_id' => 'legacy-cutover-forget',
            'memory_key' => '',
            'feature_key' => null,
            'content_hash' => $contentHash,
            'status' => 'active',
            'retention' => 'durable',
            'visibility' => 'syncable',
            'sensitivity' => 'normal',
            'type' => 'note',
            'source_type' => 'user',
            'source_ref' => null,
            'importance' => 0.5,
            'confidence' => 0.5,
            'tenant_id' => null,
            'project_id' => 'p1',
            'project_ref_id' => null,
            'agent_id' => null,
            'session_id' => null,
            'meta' => [],
            'provenance' => null,
            'observed_at' => '__not_supplied__',
            'write_reason' => null,
            'expected_previous_id' => '__not_supplied__',
            'valid_from' => '__not_supplied__',
            'valid_until' => '__not_supplied__',
            'expires_at' => '__not_supplied__',
        ];
        $canonicalize = new \ReflectionMethod($service, 'canonicalFingerprintValue');
        $legacyFingerprint = hash('sha256', json_encode(
            $canonicalize->invoke($service, $fingerprintInput),
            JSON_THROW_ON_ERROR,
        ));
        // Seed the exact pre-cutover row shape. The current SQLite guard is
        // intentionally dropped only for this fixture write and immediately
        // restored before the runtime retry is exercised.
        DB::unprepared('DROP TRIGGER IF EXISTS "memory_write_events_ledger_identity_v2_update"');
        MemoryWriteEvent::query()->sole()->update([
            'idempotency_key' => $legacyIdempotency,
            'write_fingerprint' => $legacyFingerprint,
            'ledger_identity_version' => 1,
        ]);
        $migration = require database_path(
            'migrations/2026_08_23_000005_harden_memory_write_ledger_identities.php'
        );
        $installGuards = new \ReflectionMethod($migration, 'installVersionGuards');
        $installGuards->invoke($migration);

        try {
            $service->remember($payload);
            $this->fail('A delayed v1 retry must remain blocked by the forgotten ledger tombstone.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('The memory write event belongs to a forgotten memory.', $exception->getMessage());
        }

        $this->assertSame(0, MemoryLink::count());
        $this->assertSame(1, MemoryWriteEvent::count());
        $this->assertSame('forgotten', MemoryWriteEvent::query()->sole()->state);
    }

    public function test_versioned_write_returns_logical_id_and_physical_id_forgets_the_complete_family(): void
    {
        [, $token] = $this->token(['brain.write']);
        $base = [
            'client_id' => 'desktop-a',
            'external_id' => 'logical-memory',
            'scope' => 'project',
            'project_id' => 'p1',
            'feature_key' => 'logical-memory-key',
            'write_intent' => 'explicit',
        ];

        $first = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Version A',
            'write_id' => 'logical-write-a',
        ])->assertCreated()->json('memory_link_id');
        $secondResponse = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'content' => 'Version B',
            'write_id' => 'logical-write-b',
            'expected_previous_id' => $first,
        ])->assertCreated()->assertJsonPath('id', 'logical-memory');
        $second = MemoryLink::query()->findOrFail($secondResponse->json('memory_link_id'));

        $this->assertNotSame('logical-memory', $second->external_id);
        $this->assertSame('logical-memory', $second->logicalExternalId());

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/forget', [
            'external_id' => $second->external_id,
            'scope' => 'project',
            'project_id' => 'p1',
        ])->assertOk()->assertJsonPath('forgotten', true);

        $this->assertSame(0, MemoryLink::count());
        $this->assertSame(2, MemoryWriteEvent::query()->where('state', 'forgotten')->count());
    }

    public function test_forget_expands_a_feature_family_with_different_external_ids(): void
    {
        [, $token] = $this->token(['brain.write']);
        $base = [
            'client_id' => 'desktop-a',
            'scope' => 'project',
            'project_id' => 'p1',
            'feature_key' => 'answer.tone',
            'write_intent' => 'explicit',
        ];

        $first = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'external_id' => 'desktop-record-a',
            'content' => 'Ton A',
            'write_id' => 'desktop-feature-write-a',
        ])->assertCreated()->json('memory_link_id');
        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', $base + [
            'external_id' => 'desktop-record-b',
            'content' => 'Ton B',
            'write_id' => 'desktop-feature-write-b',
            'expected_previous_id' => $first,
        ])->assertCreated()->assertJsonPath('id', 'desktop-record-b');

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/forget', [
            'external_id' => 'desktop-record-b',
            'scope' => 'project',
            'project_id' => 'p1',
        ])->assertOk()->assertJsonPath('forgotten', true);

        $this->assertSame(0, MemoryLink::count());
        $this->assertSame(2, MemoryWriteEvent::query()->where('state', 'forgotten')->count());
    }

    public function test_confirmed_write_creates_an_async_cognee_projection_outbox(): void
    {
        Queue::fake();
        $this->app->instance(CogneeClient::class, new CogneeClient('http://cognee:8000', 'internal-key'));
        [, $token] = $this->token(['brain.write']);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', [
            'client_id' => 'desktop-a',
            'scope' => 'project',
            'project_id' => 'p1',
            'content' => 'Bestätigte Architekturentscheidung.',
            'write_intent' => 'confirmed',
            'write_id' => 'confirmed-projection-write',
        ])->assertCreated()->assertJsonPath('projection_status', 'pending');

        $this->assertDatabaseHas('memory_projection_outbox', ['action' => 'upsert', 'status' => 'queued']);
        Queue::assertPushed(ProcessMemoryProjection::class);
    }

    public function test_forget_before_a_new_upsert_turn_finishes_source_ineligible_without_provider_egress(): void
    {
        Queue::fake();
        $configuredCognee = new CogneeClient('http://cognee:8000', 'internal-key');
        $this->app->instance(CogneeClient::class, $configuredCognee);
        [, $token] = $this->token(['brain.write']);

        $remember = $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', [
            'client_id' => 'desktop-a',
            'external_id' => 'forget-before-new-upsert',
            'scope' => 'project',
            'project_id' => 'p1',
            'content' => 'Diese bestätigte Erinnerung wird vor dem Projektionslauf gelöscht.',
            'write_intent' => 'confirmed',
            'write_id' => 'forget-before-new-upsert-write',
        ])->assertCreated();
        $linkId = (int) $remember->json('memory_link_id');
        $upsert = MemoryProjectionOutbox::query()
            ->where('memory_link_id', $linkId)
            ->where('action', 'upsert')
            ->sole();
        $payload = $upsert->payload ?? [];
        $payload['content_ciphertext'] = 'stale-encrypted-recovery-snapshot';
        $payload['content_snapshot_expires_at'] = now()->addHour()->toIso8601String();
        $upsert->update(['payload' => $payload]);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/forget', [
            'external_id' => 'forget-before-new-upsert',
            'scope' => 'project',
            'project_id' => 'p1',
        ])->assertOk()->assertJsonPath('forgotten', true);
        $this->assertDatabaseMissing('memory_links', ['id' => $linkId]);

        $cognee = new class extends CogneeClient
        {
            public int $providerWrites = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function add(string $dataset, string $content, array $metadata = [], bool $throw = false): array
            {
                $this->providerWrites++;

                return [];
            }

            public function cognify(array $datasets, bool $throw = false, ?string $idempotencyKey = null): array
            {
                $this->providerWrites++;

                return [];
            }
        };
        (new MemoryProjectionService($cognee))->process($upsert->id);

        $terminal = $upsert->fresh();
        $this->assertSame('done', $terminal->status);
        $this->assertSame('add_skipped_source_ineligible', $terminal->payload['phase']);
        $this->assertArrayNotHasKey('content_ciphertext', $terminal->payload);
        $this->assertArrayNotHasKey('content_snapshot_expires_at', $terminal->payload);
        $this->assertSame(0, $cognee->providerWrites);
    }

    public function test_future_confirmed_write_is_deferred_without_an_early_projection(): void
    {
        Queue::fake();
        $this->app->instance(CogneeClient::class, new CogneeClient('http://cognee:8000', 'internal-key'));
        [, $token] = $this->token(['brain.write']);

        $this->withHeader('X-Api-Key', $token)->postJson('/api/v1/memory/remember', [
            'client_id' => 'desktop-a',
            'external_id' => 'future-confirmed-memory',
            'scope' => 'project',
            'project_id' => 'p1',
            'content' => 'Diese Entscheidung gilt erst morgen.',
            'write_intent' => 'confirmed',
            'valid_from' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('projection_status', 'deferred');

        $this->assertSame(0, MemoryProjectionOutbox::count());
        Queue::assertNothingPushed();
    }

    public function test_cognee_failure_keeps_the_canonical_memory_and_marks_the_outbox_failed(): void
    {
        $user = User::factory()->create();
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'm1',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Canonical memory survives.',
            'content_hash' => hash('sha256', 'Canonical memory survives.'),
            'projection_status' => 'pending',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'failure-test'),
            'status' => 'queued',
        ]);
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(503)]))]);
        $service = new MemoryProjectionService(new CogneeClient('http://cognee:8000', 'key', 15, $http));

        try {
            $service->process($outbox->id);
            $this->fail('Projection should fail.');
        } catch (\RuntimeException) {
            // Expected: the queue retries, while SQL remains authoritative.
        }

        $this->assertDatabaseHas('memory_links', ['id' => $link->id, 'status' => 'active']);
        $this->assertDatabaseHas('memory_projection_outbox', ['id' => $outbox->id, 'status' => 'failed']);
        $this->assertDatabaseHas('memory_links', ['id' => $link->id, 'projection_status' => 'failed']);
    }

    public function test_cognee_response_without_data_uuid_is_retried_instead_of_marked_ready(): void
    {
        $user = User::factory()->create();
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'm-invalid',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Canonical memory requires a Cognee data UUID.',
            'content_hash' => hash('sha256', 'Canonical memory requires a Cognee data UUID.'),
            'projection_status' => 'pending',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'invalid-response-test'),
            'status' => 'queued',
        ]);
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], '{"status":"completed","items":[]}'),
        ]))]);
        $service = new MemoryProjectionService(new CogneeClient('http://cognee:8000', 'key', 15, $http));

        $this->expectException(\RuntimeException::class);
        try {
            $service->process($outbox->id);
        } finally {
            $this->assertDatabaseHas('memory_projection_outbox', ['id' => $outbox->id, 'status' => 'failed']);
            $this->assertDatabaseHas('memory_links', ['id' => $link->id, 'projection_status' => 'failed']);
        }
    }

    public function test_projection_waits_for_background_cognify_before_compensating_delete(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'in-flight-memory',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'This memory is forgotten while projection runs.',
            'content_hash' => hash('sha256', 'This memory is forgotten while projection runs.'),
            'valid_from' => now(),
            'recorded_at' => now(),
            'projection_status' => 'pending',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'in-flight-upsert'),
            'status' => 'queued',
        ]);
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $cognee = new class($link->id, $dataId, $datasetId, $runId, $instanceId) extends CogneeClient
        {
            public int $polls = 0;

            public function __construct(
                private int $linkId,
                private string $dataId,
                private string $datasetId,
                private string $runId,
                private string $instanceId,
            ) {
                parent::__construct('http://cognee:8000', 'key');
            }

            public function add(string $dataset, string $content, array $metadata = [], bool $throw = false): array
            {
                return [
                    'dataset_id' => $this->datasetId,
                    'data_ingestion_info' => [['data_id' => $this->dataId]],
                ];
            }

            public function observedInstanceId(): ?string
            {
                return $this->instanceId;
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                return $this->instanceId;
            }

            public function observedLaunchInstanceId(): ?string
            {
                return $this->instanceId;
            }

            public function acknowledgeLaunch(string $idempotencyKey, bool $throw = false): bool
            {
                return true;
            }

            public function cognifyOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                MemoryLink::query()->whereKey($this->linkId)->delete();

                return [
                    $this->datasetId => [
                        'pipeline_run_id' => $this->runId,
                        'status' => 'PipelineRunStarted',
                        'dataset_id' => $this->datasetId,
                    ],
                ];
            }

            public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
            {
                $this->polls++;

                return [
                    'pipeline_name' => 'cognify_pipeline',
                    'pipeline_run_id' => $this->runId,
                    'dataset_id' => $this->datasetId,
                    'status' => $this->polls === 1
                        ? 'DATASET_PROCESSING_STARTED'
                        : 'DATASET_PROCESSING_COMPLETED',
                    'created_at' => now()->toIso8601String(),
                ];
            }
        };

        (new MemoryProjectionService($cognee))->process($outbox->id);
        $this->travel(16)->seconds();
        (new MemoryProjectionService($cognee))->process($outbox->id);
        $this->assertDatabaseMissing('memory_projection_outbox', ['action' => 'delete']);
        $this->travel(16)->seconds();
        (new MemoryProjectionService($cognee))->process($outbox->id);
        $this->travelBack();

        $this->assertDatabaseMissing('memory_links', ['id' => $link->id]);
        $this->assertDatabaseHas('memory_projection_outbox', [
            'action' => 'delete',
            'dataset' => $link->dataset,
            'status' => 'queued',
        ]);
        $delete = MemoryProjectionOutbox::query()->where('action', 'delete')->firstOrFail();
        $this->assertSame($dataId, $delete->payload['cognee_memory_id']);
        Queue::assertPushed(ProcessMemoryProjection::class, fn (ProcessMemoryProjection $job) => $job->outboxId === $delete->id);
    }

    public function test_cognify_deadline_never_treats_a_live_exact_run_as_dead(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'dedupe_key' => hash('sha256', 'overdue-cognify'),
            'payload' => [
                'phase' => 'polling',
                'cognee_memory_id' => '09393f2c-238c-4db8-853e-7e71fa2bd9bd',
                'cognee_dataset_id' => $datasetId,
                'pipeline_run_id' => $runId,
                'cognee_instance_id' => $instanceId,
                'content_hash' => hash('sha256', 'overdue content'),
                'cognify_started_at' => now()->subMinutes(31)->toIso8601String(),
            ],
            'status' => 'queued',
        ]);
        $cognee = new class($datasetId, $runId, $instanceId) extends CogneeClient
        {
            public int $launches = 0;

            public function __construct(
                private string $datasetId,
                private string $runId,
                private string $instanceId,
            ) {
                parent::__construct('http://cognee:8000', 'key');
            }

            public function observedInstanceId(): ?string
            {
                return $this->instanceId;
            }

            public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
            {
                return [
                    'pipeline_name' => 'cognify_pipeline',
                    'pipeline_run_id' => $this->runId,
                    'dataset_id' => $this->datasetId,
                    'status' => 'DATASET_PROCESSING_STARTED',
                    'created_at' => now()->subMinutes(31)->toIso8601String(),
                ];
            }

            public function cognifyOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->launches++;

                return [];
            }
        };

        (new MemoryProjectionService($cognee))->process($outbox->id);

        $outbox->refresh();
        $this->assertSame('pending', $outbox->status);
        $this->assertSame('polling', $outbox->payload['phase']);
        $this->assertArrayHasKey('deadline_exceeded_at', $outbox->payload);
        $this->assertSame(0, $outbox->attempts);
        $this->assertSame(0, $cognee->launches);
        $this->assertDatabaseMissing('memory_projection_outbox', ['action' => 'delete']);
        Queue::assertNothingPushed();
    }

    public function test_polling_run_restarts_once_only_after_its_cognee_process_is_provably_gone(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $oldRunId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $newRunId = '77f83175-d4bf-46a9-a481-a4b0b04f45c7';
        $oldInstanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $newInstanceId = '5f950d9b-5538-4d11-82f9-d916d1ea0777';
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'restart-cognify',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Restart content.',
            'content_hash' => hash('sha256', 'restart content'),
            'valid_from' => now(),
            'projection_status' => 'processing',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'dedupe_key' => hash('sha256', 'restart-cognify'),
            'payload' => [
                'phase' => 'polling',
                'cognee_memory_id' => '09393f2c-238c-4db8-853e-7e71fa2bd9bd',
                'cognee_dataset_id' => $datasetId,
                'pipeline_run_id' => $oldRunId,
                'cognee_instance_id' => $oldInstanceId,
                'content_hash' => hash('sha256', 'restart content'),
                'cognify_started_at' => now()->subMinute()->toIso8601String(),
            ],
            'status' => 'queued',
        ]);
        $cognee = new class($datasetId, $oldRunId, $newRunId, $newInstanceId) extends CogneeClient
        {
            public int $launches = 0;

            public function __construct(
                private string $datasetId,
                private string $oldRunId,
                private string $newRunId,
                private string $newInstanceId,
            ) {
                parent::__construct('http://cognee:8000', 'key');
            }

            public function observedInstanceId(): ?string
            {
                return $this->newInstanceId;
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                return $this->newInstanceId;
            }

            public function observedLaunchInstanceId(): ?string
            {
                return $this->newInstanceId;
            }

            public function acknowledgeLaunch(string $idempotencyKey, bool $throw = false): bool
            {
                return true;
            }

            public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
            {
                return [
                    'pipeline_name' => 'cognify_pipeline',
                    'pipeline_run_id' => $this->oldRunId,
                    'dataset_id' => $this->datasetId,
                    'status' => 'DATASET_PROCESSING_STARTED',
                ];
            }

            public function cognifyOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->launches++;

                return [
                    $this->datasetId => [
                        'pipeline_run_id' => $this->newRunId,
                        'status' => 'PipelineRunStarted',
                        'dataset_id' => $this->datasetId,
                    ],
                ];
            }
        };

        (new MemoryProjectionService($cognee))->process($outbox->id);

        $outbox->refresh();
        $this->assertSame('pending', $outbox->status);
        $this->assertSame('polling', $outbox->payload['phase']);
        $this->assertSame($newRunId, $outbox->payload['pipeline_run_id']);
        $this->assertSame($newInstanceId, $outbox->payload['cognee_instance_id']);
        $this->assertSame(1, $outbox->payload['recovery_generation']);
        $this->assertSame(1, $cognee->launches);
        $this->assertSame(0, $outbox->attempts);
        Queue::assertNothingPushed();
    }

    public function test_completed_exact_run_is_finalized_after_restart_without_an_extra_launch(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $oldInstanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $newInstanceId = '5f950d9b-5538-4d11-82f9-d916d1ea0777';
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'completed-before-restart',
            'scope' => 'project',
            'dataset' => $dataset,
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Finalize the durable terminal transition.',
            'content_hash' => hash('sha256', 'Finalize the durable terminal transition.'),
            'valid_from' => now(),
            'projection_status' => 'processing',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'completed-before-restart'),
            'payload' => [
                'phase' => 'polling',
                'cognee_memory_id' => $dataId,
                'cognee_dataset_id' => $datasetId,
                'pipeline_run_id' => $runId,
                'cognee_instance_id' => $oldInstanceId,
                'content_hash' => $link->content_hash,
                'cognify_started_at' => now()->subMinute()->toIso8601String(),
            ],
            'status' => 'queued',
        ]);
        $cognee = new class($datasetId, $runId, $newInstanceId) extends CogneeClient
        {
            public int $launches = 0;

            public function __construct(
                private string $datasetId,
                private string $runId,
                private string $newInstanceId,
            ) {
                parent::__construct('http://cognee:8000', 'key');
            }

            public function observedInstanceId(): ?string
            {
                return $this->newInstanceId;
            }

            public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
            {
                return [
                    'pipeline_name' => 'cognify_pipeline',
                    'pipeline_run_id' => $this->runId,
                    'dataset_id' => $this->datasetId,
                    'status' => 'DATASET_PROCESSING_COMPLETED',
                ];
            }

            public function cognifyOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->launches++;

                return [];
            }
        };

        (new MemoryProjectionService($cognee))->process($outbox->id);

        $this->assertSame('done', $outbox->fresh()->status);
        $this->assertSame(0, $cognee->launches);
        $this->assertDatabaseHas('memory_links', [
            'id' => $link->id,
            'cognee_memory_id' => $dataId,
            'projection_status' => 'ready',
        ]);
        $this->assertDatabaseMissing('memory_projection_outbox', ['action' => 'delete']);
        Queue::assertNothingPushed();
    }

    public function test_lost_add_response_is_replayed_deterministically_and_forgotten_after_source_delete(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'lost-add-response',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Recover the deterministic Cognee Data UUID.',
            'content_hash' => hash('sha256', 'Recover the deterministic Cognee Data UUID.'),
            'valid_from' => now(),
            'projection_status' => 'pending',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'lost-add-response'),
            'payload' => ['content_hash' => $link->content_hash],
            'status' => 'queued',
        ]);
        $cognee = new class($datasetId, $dataId) extends CogneeClient
        {
            public int $adds = 0;

            public int $cognifies = 0;

            public int $lookups = 0;

            /** @var list<int> */
            public array $lookupMemoryIds = [];

            public function __construct(private string $datasetId, private string $dataId)
            {
                parent::__construct('http://cognee:8000', 'key');
            }

            public function add(string $dataset, string $content, array $metadata = [], bool $throw = false): array
            {
                $this->adds++;
                if ($this->adds === 1) {
                    throw new \RuntimeException('Connection lost after Cognee accepted add.');
                }

                return [
                    'dataset_id' => $this->datasetId,
                    'data_ingestion_info' => [['data_id' => $this->dataId]],
                ];
            }

            public function findData(
                string $dataset,
                int $memoryId,
                string $contentHash,
                bool $throw = false,
            ): array {
                $this->lookups++;
                $this->lookupMemoryIds[] = $memoryId;

                return [
                    'dataset_id' => $this->datasetId,
                    'data_ids' => [$this->dataId],
                ];
            }

            public function cognifyOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->cognifies++;

                return [];
            }
        };
        $service = new MemoryProjectionService($cognee);

        try {
            $service->process($outbox->id);
            $this->fail('The simulated lost add response must be retried.');
        } catch (\RuntimeException) {
            // The durable `adding` intent and encrypted recovery envelope survive.
        }
        $this->assertSame('adding', $outbox->fresh()->payload['phase']);
        $this->assertArrayNotHasKey('content', $outbox->fresh()->payload);
        $this->assertNotEmpty($outbox->fresh()->payload['content_ciphertext']);
        $this->assertStringNotContainsString($link->summary, json_encode($outbox->fresh()->payload, JSON_THROW_ON_ERROR));

        $this->assertTrue((new LuczorMemoryService($cognee))->forget('project', $link->external_id, [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]));
        $erasedPayload = $outbox->fresh()->payload;
        $this->assertSame($link->id, $erasedPayload['provider_memory_link_id']);
        $this->assertSame('memory_forgotten', $erasedPayload['source_erasure_reason']);
        $this->assertArrayNotHasKey('content_ciphertext', $erasedPayload);
        $this->assertArrayNotHasKey('content_snapshot_expires_at', $erasedPayload);
        $this->travel(11)->seconds();
        $service->process($outbox->id);
        $this->travelBack();

        $this->assertSame(1, $cognee->adds);
        $this->assertSame(1, $cognee->lookups);
        $this->assertSame([$link->id], $cognee->lookupMemoryIds);
        $this->assertSame(0, $cognee->cognifies);
        $this->assertSame('done', $outbox->fresh()->status);
        $this->assertArrayNotHasKey('content', $outbox->fresh()->payload);
        $this->assertArrayNotHasKey('content_ciphertext', $outbox->fresh()->payload);
        $delete = MemoryProjectionOutbox::query()->where('action', 'delete')->firstOrFail();
        $this->assertSame($dataId, $delete->payload['cognee_memory_id']);
    }

    public function test_forget_blocks_a_provider_identity_conflict_then_recovers_a_missing_hash_from_sql(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $summary = 'Monotonic provider recovery identity.';
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'provider-identity-conflict',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => $summary,
            'content_hash' => hash('sha256', $summary),
            'valid_from' => now(),
            'projection_status' => 'processing',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'provider-identity-conflict'),
            'payload' => [
                'phase' => 'adding',
                'provider_memory_link_id' => $link->id + 100,
                'content_hash' => $link->content_hash,
            ],
            'status' => 'failed',
        ]);
        $memory = new LuczorMemoryService(new CogneeClient('http://cognee:8000', 'key'));

        try {
            $memory->forget('project', $link->external_id, [
                'user_id' => $user->id,
                'project_id' => 'p1',
            ]);
            $this->fail('Forget must not replace a previously persisted provider filename identity.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('provider filename identity is invalid or conflicting', $error->getMessage());
        }

        $this->assertDatabaseHas('memory_links', ['id' => $link->id]);
        $outbox->refresh();
        $this->assertSame($link->id + 100, $outbox->payload['provider_memory_link_id']);
        $this->assertSame('failed', $outbox->status);
        Queue::assertNothingPushed();

        $outbox->update(['payload' => [
            'phase' => 'adding',
            'provider_memory_link_id' => $link->id,
        ]]);
        $this->assertTrue($memory->forget('project', $link->external_id, [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]));
        $this->assertDatabaseMissing('memory_links', ['id' => $link->id]);
        $outbox->refresh();
        $this->assertSame($link->content_hash, $outbox->payload['content_hash']);
        $this->assertSame($link->id, $outbox->payload['provider_memory_link_id']);
        $this->assertSame('queued', $outbox->status);
    }

    public function test_rejected_cognify_never_relaunches_after_its_source_is_deleted(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'rejected-cognify',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Do not rebuild a forgotten rejected projection.',
            'content_hash' => hash('sha256', 'Do not rebuild a forgotten rejected projection.'),
            'valid_from' => now(),
            'projection_status' => 'pending',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'rejected-cognify'),
            'payload' => ['content_hash' => $link->content_hash],
            'status' => 'queued',
        ]);
        $cognee = new class($datasetId, $dataId, $instanceId) extends CogneeClient
        {
            public int $cognifies = 0;

            public int $forgets = 0;

            public function __construct(
                private string $datasetIdValue,
                private string $dataIdValue,
                private string $instanceIdValue,
            ) {}

            public function enabled(): bool
            {
                return true;
            }

            public function add(string $dataset, string $content, array $metadata = [], bool $throw = false): array
            {
                return [
                    'dataset_id' => $this->datasetIdValue,
                    'data_ingestion_info' => [['data_id' => $this->dataIdValue]],
                ];
            }

            public function observedInstanceId(): ?string
            {
                return $this->instanceIdValue;
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                return $this->instanceIdValue;
            }

            public function cognifyOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->cognifies++;

                throw new CogneeRequestException('/api/v1/cognify', 422, ['detail' => 'Rejected']);
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                ];
            }
        };
        $service = new MemoryProjectionService($cognee);

        try {
            $service->process($outbox->id);
            $this->fail('The deterministic Cognify rejection must be retained for retry diagnostics.');
        } catch (CogneeRequestException) {
            // The adapter proves that no background task was accepted.
        }
        $this->assertSame('cognify_rejected', $outbox->fresh()->payload['phase']);
        $this->assertSame(422, $outbox->fresh()->payload['last_launch_rejection_status']);
        $this->assertSame(1, $cognee->cognifies);

        $link->delete();
        $this->travel(11)->seconds();
        $service->process($outbox->id);
        $this->travelBack();

        $this->assertSame(1, $cognee->cognifies);
        $this->assertSame('done', $outbox->fresh()->status);
        $delete = MemoryProjectionOutbox::query()->where('action', 'delete')->firstOrFail();
        $service->process($delete->id);
        $this->assertSame(1, $cognee->forgets);
        $this->assertSame('done', $delete->fresh()->status);
    }

    public function test_lost_cognify_response_is_reconciled_without_launching_a_duplicate_run(): void
    {
        $user = User::factory()->create();
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'lost-cognify-response',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Reconcile an ambiguous cognify launch.',
            'content_hash' => hash('sha256', 'Reconcile an ambiguous cognify launch.'),
            'valid_from' => now(),
            'projection_status' => 'pending',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'lost-cognify-response'),
            'payload' => ['content_hash' => $link->content_hash],
            'status' => 'queued',
        ]);
        $cognee = new class($datasetId, $dataId, $runId, $instanceId) extends CogneeClient
        {
            public int $launches = 0;

            public int $launchRequests = 0;

            public int $statusCalls = 0;

            public function __construct(
                private string $datasetId,
                private string $dataId,
                private string $runId,
                private string $instanceId,
            ) {
                parent::__construct('http://cognee:8000', 'key');
            }

            public function add(string $dataset, string $content, array $metadata = [], bool $throw = false): array
            {
                return [
                    'dataset_id' => $this->datasetId,
                    'data_ingestion_info' => [['data_id' => $this->dataId]],
                ];
            }

            public function observedInstanceId(): ?string
            {
                return $this->instanceId;
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                return $this->instanceId;
            }

            public function observedLaunchInstanceId(): ?string
            {
                return $this->instanceId;
            }

            public function acknowledgeLaunch(string $idempotencyKey, bool $throw = false): bool
            {
                return true;
            }

            public function cognifyOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->launchRequests++;
                if ($this->launchRequests === 1) {
                    $this->launches++;
                    throw new \RuntimeException('Connection lost after Cognee accepted cognify.');
                }

                return [
                    $this->datasetId => [
                        'pipeline_run_id' => $this->runId,
                        'status' => 'PipelineRunStarted',
                        'dataset_id' => $this->datasetId,
                    ],
                ];
            }

            public function pipelineRuns(string $datasetId, bool $throw = false): array
            {
                $this->statusCalls++;

                return [[
                    'pipeline_name' => 'cognify_pipeline',
                    'pipeline_run_id' => $this->runId,
                    'dataset_id' => $this->datasetId,
                    'status' => 'DATASET_PROCESSING_STARTED',
                    'created_at' => now()->toIso8601String(),
                ]];
            }

            public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
            {
                $this->statusCalls++;

                return [
                    'pipeline_name' => 'cognify_pipeline',
                    'pipeline_run_id' => $this->runId,
                    'dataset_id' => $this->datasetId,
                    'status' => $this->statusCalls === 1
                        ? 'DATASET_PROCESSING_STARTED'
                        : 'DATASET_PROCESSING_COMPLETED',
                    'created_at' => now()->toIso8601String(),
                ];
            }
        };
        $service = new MemoryProjectionService($cognee);

        try {
            $service->process($outbox->id);
            $this->fail('The simulated lost cognify response must be reconciled.');
        } catch (\RuntimeException) {
            // The launch intent was committed before the external call.
        }
        $this->assertSame('cognify_launching', $outbox->fresh()->payload['phase']);

        $this->travel(11)->seconds();
        $service->process($outbox->id);
        $this->assertSame('polling', $outbox->fresh()->payload['phase']);
        $this->assertSame(1, $cognee->launches);

        $this->travel(16)->seconds();
        $service->process($outbox->id);
        $this->travelBack();

        $this->assertSame(1, $cognee->launches);
        $this->assertDatabaseHas('memory_links', [
            'id' => $link->id,
            'cognee_memory_id' => $dataId,
            'projection_status' => 'ready',
        ]);
    }

    public function test_cached_launch_from_a_dead_cognee_instance_never_relaunches_an_ineligible_source(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $oldRunId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $newRunId = '77f83175-d4bf-46a9-a481-a4b0b04f45c7';
        $oldInstanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $newInstanceId = '5f950d9b-5538-4d11-82f9-d916d1ea0777';
        $launchKey = hash('sha256', 'cached-old-launch');
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'dedupe_key' => hash('sha256', 'cached-old-launch-outbox'),
            'payload' => [
                'phase' => 'cognify_launching',
                'cognee_memory_id' => $dataId,
                'cognee_dataset_id' => $datasetId,
                'content_hash' => hash('sha256', 'cached old launch'),
                'launch_key' => $launchKey,
                'launch_generation' => 1,
                'launch_intent_at' => now()->subMinute()->toIso8601String(),
                'cognee_instance_id' => $oldInstanceId,
            ],
            'status' => 'queued',
        ]);
        $cognee = new class($datasetId, $oldRunId, $newRunId, $oldInstanceId, $newInstanceId) extends CogneeClient
        {
            public int $launchRequests = 0;

            public int $actualNewLaunches = 0;

            private ?string $lastLaunchInstance = null;

            public function __construct(
                private string $datasetId,
                private string $oldRunId,
                private string $newRunId,
                private string $oldInstanceId,
                private string $newInstanceId,
            ) {
                parent::__construct('http://cognee:8000', 'key');
            }

            public function observedInstanceId(): ?string
            {
                return $this->newInstanceId;
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                return $this->newInstanceId;
            }

            public function observedLaunchInstanceId(): ?string
            {
                return $this->lastLaunchInstance;
            }

            public function acknowledgeLaunch(string $idempotencyKey, bool $throw = false): bool
            {
                return true;
            }

            public function pipelineRuns(string $datasetId, bool $throw = false): array
            {
                return [];
            }

            public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
            {
                return [
                    'pipeline_name' => 'cognify_pipeline',
                    'pipeline_run_id' => $this->oldRunId,
                    'dataset_id' => $this->datasetId,
                    'status' => 'DATASET_PROCESSING_STARTED',
                ];
            }

            public function cognifyOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->launchRequests++;
                if ($this->launchRequests === 1) {
                    // Durable wrapper cache from the process that accepted the
                    // original request. This replay does not launch again.
                    $this->lastLaunchInstance = $this->oldInstanceId;
                    $runId = $this->oldRunId;
                } else {
                    $this->actualNewLaunches++;
                    $this->lastLaunchInstance = $this->newInstanceId;
                    $runId = $this->newRunId;
                }

                return [$this->datasetId => [
                    'pipeline_run_id' => $runId,
                    'status' => 'PipelineRunStarted',
                    'dataset_id' => $this->datasetId,
                ]];
            }
        };
        $service = new MemoryProjectionService($cognee);

        $service->process($outbox->id);
        $outbox->refresh();
        $this->assertSame('done', $outbox->status);
        $this->assertSame('restart_source_ineligible', $outbox->payload['phase']);
        $this->assertArrayNotHasKey('pipeline_run_id', $outbox->payload);
        $this->assertArrayNotHasKey('cognee_instance_id', $outbox->payload);
        $this->assertSame(0, $cognee->launchRequests);
        $this->assertSame(0, $cognee->actualNewLaunches);
        $this->assertSame(1, $outbox->payload['recovery_generation']);
        Queue::assertPushed(ProcessMemoryProjection::class);
    }

    public function test_projection_adds_then_polls_background_cognify_without_blocking_the_worker(): void
    {
        $user = User::factory()->create();
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'async-memory',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Async Cognee projection.',
            'content_hash' => hash('sha256', 'Async Cognee projection.'),
            'valid_from' => now(),
            'projection_status' => 'pending',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'async-projection'),
            'payload' => ['content_hash' => $link->content_hash],
            'status' => 'queued',
        ]);
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['X-Luczor-Cognee-Instance' => $instanceId], json_encode([
                'status' => 'PipelineRunCompleted',
                'dataset_id' => $datasetId,
                'data_ingestion_info' => [['data_id' => $dataId]],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['X-Luczor-Cognee-Instance' => $instanceId], json_encode([
                'instance_id' => $instanceId,
                'guarded_operations' => ['cognify', 'improve'],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [
                'X-Luczor-Cognee-Instance' => $instanceId,
                'X-Luczor-Cognee-Launch-Instance' => $instanceId,
            ], json_encode([
                $datasetId => [
                    'pipeline_run_id' => $runId,
                    'status' => 'PipelineRunStarted',
                    'dataset_id' => $datasetId,
                ],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['X-Luczor-Cognee-Instance' => $instanceId], json_encode([
                'acknowledged' => true,
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['X-Luczor-Cognee-Instance' => $instanceId], json_encode([
                'pipeline_name' => 'cognify_pipeline',
                'pipeline_run_id' => $runId,
                'dataset_id' => $datasetId,
                'status' => 'DATASET_PROCESSING_IN_PROGRESS',
                'created_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['X-Luczor-Cognee-Instance' => $instanceId], json_encode([
                'pipeline_name' => 'cognify_pipeline',
                'pipeline_run_id' => $runId,
                'dataset_id' => $datasetId,
                'status' => 'DATASET_PROCESSING_COMPLETED',
                'created_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR)),
        ]))]);
        $service = new MemoryProjectionService(new CogneeClient('http://cognee:8000', 'key', 15, $http));

        $service->process($outbox->id);
        $this->assertDatabaseHas('memory_projection_outbox', ['id' => $outbox->id, 'status' => 'pending']);
        $this->assertDatabaseHas('memory_links', ['id' => $link->id, 'projection_status' => 'processing']);

        $this->travel(16)->seconds();
        $service->process($outbox->id);
        $this->assertDatabaseHas('memory_projection_outbox', ['id' => $outbox->id, 'status' => 'pending']);
        $this->assertSame(0, $outbox->fresh()->attempts);

        $this->travel(16)->seconds();
        $service->process($outbox->id);
        $this->assertDatabaseHas('memory_projection_outbox', ['id' => $outbox->id, 'status' => 'done']);
        $this->assertDatabaseHas('memory_links', [
            'id' => $link->id,
            'cognee_memory_id' => $dataId,
            'projection_status' => 'ready',
        ]);
        $this->travelBack();
    }

    public function test_cognify_aborts_when_the_fresh_runtime_probe_omits_its_boot_header(): void
    {
        $user = User::factory()->create();
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'missing-runtime-header',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Require a fresh guarded runtime for Cognify.',
            'content_hash' => hash('sha256', 'Require a fresh guarded runtime for Cognify.'),
            'valid_from' => now(),
            'projection_status' => 'pending',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'missing-runtime-header'),
            'payload' => ['content_hash' => $link->content_hash],
            'status' => 'queued',
        ]);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['X-Luczor-Cognee-Instance' => $instanceId], json_encode([
                'dataset_id' => $datasetId,
                'data_ingestion_info' => [['data_id' => $dataId]],
            ], JSON_THROW_ON_ERROR)),
            // A body UUID without the wrapper header is not an authenticated
            // boot fence and must never authorize the following POST.
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'instance_id' => $instanceId,
                'guarded_operations' => ['cognify', 'improve'],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $service = new MemoryProjectionService(new CogneeClient(
            'http://cognee:8000',
            'key',
            15,
            new Client(['handler' => $stack]),
        ));

        try {
            $service->process($outbox->id);
            $this->fail('Cognify must not launch without the fresh wrapper boot header.');
        } catch (\RuntimeException) {
            // The ingested Data UUID remains compensatable and no launch intent exists.
        }

        $this->assertSame('ingested', $outbox->fresh()->payload['phase']);
        $this->assertSame('failed', $outbox->fresh()->status);
        $this->assertCount(2, $history);
        $this->assertSame('/api/v1/add', $history[0]['request']->getUri()->getPath());
        $this->assertSame('/api/v1/luczor/runtime', $history[1]['request']->getUri()->getPath());
    }

    public function test_failed_launch_ack_is_persisted_and_retried_before_completion(): void
    {
        $user = User::factory()->create();
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $launchKey = hash('sha256', 'durable-launch-ack');
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'durable-launch-ack',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Retry the durable launch acknowledgement.',
            'content_hash' => hash('sha256', 'Retry the durable launch acknowledgement.'),
            'valid_from' => now(),
            'cognee_memory_id' => $dataId,
            'projection_status' => 'processing',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'durable-launch-ack-outbox'),
            'payload' => [
                'phase' => 'polling',
                'content_hash' => $link->content_hash,
                'cognee_memory_id' => $dataId,
                'cognee_dataset_id' => $datasetId,
                'pipeline_run_id' => $runId,
                'launch_ack_pending_key' => $launchKey,
            ],
            'status' => 'queued',
        ]);
        $cognee = new class($datasetId, $runId) extends CogneeClient
        {
            public int $acks = 0;

            public int $forgets = 0;

            public int $polls = 0;

            public function __construct(private string $datasetIdValue, private string $runIdValue) {}

            public function enabled(): bool
            {
                return true;
            }

            public function acknowledgeLaunch(string $idempotencyKey, bool $throw = false): bool
            {
                $this->acks++;

                return $this->acks >= 3;
            }

            public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
            {
                $this->polls++;

                return [
                    'pipeline_name' => 'cognify_pipeline',
                    'pipeline_run_id' => $this->runIdValue,
                    'dataset_id' => $this->datasetIdValue,
                    'status' => 'DATASET_PROCESSING_COMPLETED',
                ];
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                ];
            }
        };
        $service = new MemoryProjectionService($cognee);

        $service->process($outbox->id);
        $this->assertSame('pending', $outbox->fresh()->status);
        $this->assertSame('launch_ack_pending_terminal', $outbox->fresh()->payload['phase']);
        $this->assertSame($launchKey, $outbox->fresh()->payload['launch_ack_pending_key']);
        $this->assertSame(2, $cognee->acks);
        $this->assertSame(1, $cognee->polls);
        $this->assertSame('ready', $link->fresh()->projection_status);

        // A terminal run must no longer hold privacy-sensitive deletion behind
        // an unavailable acknowledgement endpoint.
        $link->delete();
        $delete = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'delete',
            'dataset' => $outbox->dataset,
            'dedupe_key' => hash('sha256', 'delete-during-durable-launch-ack'),
            'payload' => ['cognee_memory_id' => $dataId],
            'status' => 'queued',
        ]);
        $service->process($delete->id);
        $this->assertSame('done', $delete->fresh()->status);
        $this->assertSame(1, $cognee->forgets);

        $this->travel(16)->seconds();
        $service->process($outbox->id);
        $this->travelBack();

        $this->assertSame('done', $outbox->fresh()->status);
        $this->assertArrayNotHasKey('launch_ack_pending_key', $outbox->fresh()->payload);
        $this->assertSame(3, $cognee->acks);
        $this->assertSame(1, $cognee->polls);
    }

    public function test_pending_ack_is_never_overwritten_by_a_restart_relaunch(): void
    {
        $user = User::factory()->create();
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $oldInstanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $newInstanceId = '5f950d9b-5538-4d11-82f9-d916d1ea0777';

        foreach ([
            ['action' => 'upsert', 'phase' => 'polling', 'pipeline' => 'cognify_pipeline'],
            ['action' => 'improve', 'phase' => 'improve_polling', 'pipeline' => 'improve_pipeline'],
        ] as $case) {
            $launchKey = hash('sha256', 'pending-ack-'.$case['action']);
            $dataset = "tenant:personal:user:{$user->id}:project:{$case['action']}";
            $payload = [
                'phase' => $case['phase'],
                'cognee_dataset_id' => $datasetId,
                'pipeline_run_id' => $runId,
                'cognee_instance_id' => $oldInstanceId,
                'launch_ack_pending_key' => $launchKey,
            ];
            if ($case['action'] === 'upsert') {
                $payload['cognee_memory_id'] = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
            }
            $outbox = MemoryProjectionOutbox::create([
                'user_id' => $user->id,
                'action' => $case['action'],
                'dataset' => $dataset,
                'dedupe_key' => hash('sha256', 'pending-ack-outbox-'.$case['action']),
                'payload' => $payload,
                'status' => 'queued',
            ]);
            $cognee = new class($datasetId, $runId, $newInstanceId, $case['pipeline']) extends CogneeClient
            {
                public int $acks = 0;

                public int $launches = 0;

                public function __construct(
                    private string $datasetIdValue,
                    private string $runIdValue,
                    private string $instanceIdValue,
                    private string $pipelineName,
                ) {}

                public function enabled(): bool
                {
                    return true;
                }

                public function acknowledgeLaunch(string $idempotencyKey, bool $throw = false): bool
                {
                    $this->acks++;

                    return false;
                }

                public function observedInstanceId(): ?string
                {
                    return $this->instanceIdValue;
                }

                public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
                {
                    return [
                        'pipeline_name' => $this->pipelineName,
                        'pipeline_run_id' => $this->runIdValue,
                        'dataset_id' => $this->datasetIdValue,
                        'status' => 'DATASET_PROCESSING_STARTED',
                    ];
                }

                public function cognifyOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
                {
                    $this->launches++;

                    return [];
                }

                public function improveOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
                {
                    $this->launches++;

                    return [];
                }
            };

            (new MemoryProjectionService($cognee))->process($outbox->id);

            $outbox->refresh();
            $this->assertSame('pending', $outbox->status);
            $this->assertSame($case['phase'], $outbox->payload['phase']);
            $this->assertSame($launchKey, $outbox->payload['launch_ack_pending_key']);
            $this->assertSame(3, $cognee->acks);
            $this->assertSame(0, $cognee->launches);
        }
    }

    public function test_only_one_background_cognify_runs_per_dataset(): void
    {
        $user = User::factory()->create();
        $dataset = app(LuczorMemoryService::class)->datasetFor('project', [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]);
        $first = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'dataset-owner'),
            'payload' => [
                'phase' => 'polling',
                'cognee_memory_id' => '09393f2c-238c-4db8-853e-7e71fa2bd9bd',
                'cognee_dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
            ],
            'status' => 'pending',
            'next_attempt_at' => now()->addMinute(),
        ]);
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'waiting-memory',
            'scope' => 'project',
            'dataset' => $dataset,
            'type' => 'note',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.5,
            'confidence' => 0.8,
            'summary' => 'Wait for the dataset owner.',
            'content_hash' => hash('sha256', 'wait'),
            'valid_from' => now(),
            'projection_status' => 'pending',
        ]);
        $second = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'dataset-waiter'),
            'payload' => ['content_hash' => $link->content_hash],
            'status' => 'queued',
        ]);
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([]))]);

        (new MemoryProjectionService(new CogneeClient('http://cognee:8000', 'key', 15, $http)))
            ->process($second->id);

        $this->assertDatabaseHas('memory_projection_outbox', ['id' => $first->id, 'status' => 'pending']);
        $this->assertDatabaseHas('memory_projection_outbox', ['id' => $second->id, 'status' => 'pending']);
        $this->assertTrue($second->fresh()->next_attempt_at->isFuture());
        $this->assertDatabaseHas('memory_links', ['id' => $link->id, 'projection_status' => 'pending']);
    }

    public function test_improve_is_guarded_and_waits_for_its_exact_background_run(): void
    {
        config(['luczor.cognee.improve_enabled' => true]);
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $outbox = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'improve',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'guarded-improve'),
            'payload' => [],
            'status' => 'queued',
        ]);
        $cognee = new class($datasetId, $runId, $instanceId) extends CogneeClient
        {
            public int $launches = 0;

            public int $polls = 0;

            /** @var array<int,string> */
            public array $launchKeys = [];

            public function __construct(
                private string $datasetIdValue,
                private string $runIdValue,
                private string $instanceIdValue,
            ) {}

            public function enabled(): bool
            {
                return true;
            }

            public function improveOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->launches++;
                $this->launchKeys[] = $idempotencyKey;

                return [
                    'pipeline_run_id' => $this->runIdValue,
                    'dataset_id' => $this->datasetIdValue,
                    'status' => 'PipelineRunStarted',
                ];
            }

            public function observedInstanceId(): ?string
            {
                return $this->instanceIdValue;
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                return $this->instanceIdValue;
            }

            public function observedLaunchInstanceId(): ?string
            {
                return $this->instanceIdValue;
            }

            public function acknowledgeLaunch(string $idempotencyKey, bool $throw = false): bool
            {
                return true;
            }

            public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
            {
                $this->polls++;

                return [
                    'pipeline_name' => 'memify_pipeline',
                    'pipeline_run_id' => $this->runIdValue,
                    'dataset_id' => $this->datasetIdValue,
                    'status' => $this->polls === 1
                        ? 'DATASET_PROCESSING_IN_PROGRESS'
                        : 'DATASET_PROCESSING_COMPLETED',
                ];
            }
        };
        $service = new MemoryProjectionService($cognee);

        $service->process($outbox->id);
        $outbox->refresh();
        $this->assertSame('pending', $outbox->status);
        $this->assertSame('improve_polling', $outbox->payload['phase']);
        $this->assertSame($runId, $outbox->payload['pipeline_run_id']);
        $this->assertSame(1, $cognee->launches);

        $this->travel(16)->seconds();
        $service->process($outbox->id);
        $this->assertSame('pending', $outbox->fresh()->status);
        $this->assertSame(1, $cognee->launches);

        $this->travel(16)->seconds();
        $service->process($outbox->id);
        $this->assertSame('done', $outbox->fresh()->status);
        $this->assertSame(1, $cognee->launches);
        $this->assertSame(2, $cognee->polls);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cognee->launchKeys[0]);
        $this->travelBack();
    }

    public function test_new_improve_intent_is_skipped_when_the_cognee_1_4_feature_is_disabled(): void
    {
        Queue::fake();
        config([
            'luczor.cognee.improve_enabled' => false,
            'luczor.cognee.improve_min_interval_seconds' => 3600,
        ]);
        $user = User::factory()->create();
        $dataset = (new LuczorMemoryService(new CogneeClient))->datasetFor('project', [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]);
        $bucket = (string) intdiv(now()->timestamp, 3600);
        $outbox = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'improve',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', implode('|', ['improve', $dataset, 'none', 'bucket:'.$bucket])),
            'payload' => [],
            'status' => 'queued',
        ]);
        $cognee = new class extends CogneeClient
        {
            public int $launches = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function improveOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->launches++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                ];
            }
        };

        (new MemoryProjectionService($cognee))->process($outbox->id);

        $this->assertSame('done', $outbox->fresh()->status);
        $this->assertSame('improve_disabled', $outbox->fresh()->payload['phase']);
        $this->assertSame(0, $cognee->launches);

        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'ready-after-disabled-improve',
            'scope' => 'project',
            'dataset' => $outbox->dataset,
            'project_id' => 'p1',
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'A ready projection makes Improve eligible.',
            'content_hash' => hash('sha256', 'A ready projection makes Improve eligible.'),
            'valid_from' => now(),
            'projection_status' => 'ready',
        ]);
        config(['luczor.cognee.improve_enabled' => true]);

        $this->assertTrue((new LuczorMemoryService($cognee))->improve('project', [
            'user_id' => $user->id,
            'project_id' => $link->project_id,
        ]));
        $this->assertSame(1, MemoryProjectionOutbox::query()->where('action', 'improve')->count());
        $this->assertSame('queued', $outbox->fresh()->status);
        $this->assertNull($outbox->fresh()->payload);
        Queue::assertPushed(ProcessMemoryProjection::class, fn (ProcessMemoryProjection $job) => $job->outboxId === $outbox->id);
    }

    public function test_deterministic_add_rejection_scrubs_content_and_never_readds_after_forget(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $content = 'Content that must not be sent again after Forget.';
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'rejected-add',
            'scope' => 'project',
            'dataset' => $dataset,
            'project_id' => 'p1',
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => $content,
            'content_hash' => hash('sha256', $content),
            'valid_from' => now(),
            'projection_status' => 'pending',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'rejected-add'),
            'payload' => ['content_hash' => $link->content_hash],
            'status' => 'queued',
        ]);
        $cognee = new class extends CogneeClient
        {
            public int $adds = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function add(string $dataset, string $content, array $metadata = [], bool $throw = false): array
            {
                $this->adds++;

                throw new CogneeRequestException('/api/v1/add', 422, ['detail' => 'Rejected']);
            }
        };
        $projection = new MemoryProjectionService($cognee);

        try {
            $projection->process($outbox->id);
            $this->fail('A deterministic Add rejection must be recorded once.');
        } catch (CogneeRequestException) {
            // The source snapshot is scrubbed before the worker reports failure.
        }

        $this->assertSame('add_rejected', $outbox->fresh()->payload['phase']);
        $this->assertArrayNotHasKey('content', $outbox->fresh()->payload);
        $this->assertArrayNotHasKey('content_ciphertext', $outbox->fresh()->payload);
        $this->assertSame(1, $cognee->adds);

        $this->assertTrue((new LuczorMemoryService($cognee))->forget('project', $link->external_id, [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]));
        $projection->process($outbox->id);

        $this->assertSame(1, $cognee->adds);
        $this->assertSame('done', $outbox->fresh()->status);
        $this->assertArrayNotHasKey('content', $outbox->fresh()->payload ?? []);
        $this->assertArrayNotHasKey('content_ciphertext', $outbox->fresh()->payload ?? []);
    }

    public function test_repairable_add_auth_failure_scrubs_then_reloads_content_from_sql(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $content = 'Canonical content may be retried after credentials are repaired.';
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'repairable-add',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => $content,
            'content_hash' => hash('sha256', $content),
            'valid_from' => now(),
            'projection_status' => 'pending',
        ]);
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $link->dataset,
            'dedupe_key' => hash('sha256', 'repairable-add'),
            'payload' => ['content_hash' => $link->content_hash],
            'status' => 'queued',
        ]);
        $cognee = new class($datasetId, $dataId) extends CogneeClient
        {
            public int $adds = 0;

            public function __construct(private string $datasetIdValue, private string $dataIdValue)
            {
                parent::__construct('http://cognee:8000', 'key');
            }

            public function add(string $dataset, string $content, array $metadata = [], bool $throw = false): array
            {
                $this->adds++;
                if ($this->adds === 1) {
                    throw new CogneeRequestException('/api/v1/add', 401, ['detail' => 'Credentials expired']);
                }

                return [
                    'dataset_id' => $this->datasetIdValue,
                    'data_ingestion_info' => [['data_id' => $this->dataIdValue]],
                ];
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                return null;
            }
        };
        $projection = new MemoryProjectionService($cognee);

        try {
            $projection->process($outbox->id);
            $this->fail('The repairable authentication failure must be retried.');
        } catch (CogneeRequestException) {
        }
        $this->assertSame('new', $outbox->fresh()->payload['phase']);
        $this->assertArrayNotHasKey('content_ciphertext', $outbox->fresh()->payload);

        $this->travel(11)->seconds();
        try {
            $projection->process($outbox->id);
            $this->fail('The runtime probe is intentionally unavailable after the repaired Add.');
        } catch (\RuntimeException) {
        }
        $this->travelBack();

        $this->assertSame(2, $cognee->adds);
        $this->assertSame('ingested', $outbox->fresh()->payload['phase']);
        $this->assertArrayNotHasKey('content_ciphertext', $outbox->fresh()->payload);
        $this->assertSame($dataId, $outbox->fresh()->payload['cognee_memory_id']);
    }

    public function test_missing_exact_run_after_runtime_restart_deletes_ineligible_source_without_relaunch(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $oldInstance = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $newInstance = '5f950d9b-5538-4d11-82f9-d916d1ea0777';
        $outbox = MemoryProjectionOutbox::create([
            'memory_link_id' => null,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'dedupe_key' => hash('sha256', 'missing-run-after-restart'),
            'payload' => [
                'phase' => 'polling',
                'cognee_memory_id' => $dataId,
                'cognee_dataset_id' => $datasetId,
                'pipeline_run_id' => $runId,
                'cognee_instance_id' => $oldInstance,
                'content_hash' => hash('sha256', 'forgotten source'),
            ],
            'status' => 'queued',
        ]);
        $cognee = new class($newInstance) extends CogneeClient
        {
            public int $launches = 0;

            public int $forgets = 0;

            public function __construct(private string $newInstance) {}

            public function enabled(): bool
            {
                return true;
            }

            public function observedInstanceId(): ?string
            {
                return $this->newInstance;
            }

            public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
            {
                throw new CogneeRequestException('/api/v1/luczor/pipeline-runs/'.$runId, 404);
            }

            public function acknowledgeLaunch(string $idempotencyKey, bool $throw = false): bool
            {
                return true;
            }

            public function cognifyOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->launches++;

                return [];
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                ];
            }
        };
        $projection = new MemoryProjectionService($cognee);

        $projection->process($outbox->id);

        $this->assertSame('done', $outbox->fresh()->status);
        $this->assertSame('restart_source_ineligible', $outbox->fresh()->payload['phase']);
        $this->assertSame(0, $cognee->launches);
        $delete = MemoryProjectionOutbox::query()->where('action', 'delete')->firstOrFail();
        $projection->process($delete->id);
        $this->assertSame(1, $cognee->forgets);
        $this->assertSame('done', $delete->fresh()->status);
    }

    public function test_missing_improve_run_recovers_only_after_runtime_instance_changes(): void
    {
        config(['luczor.cognee.improve_enabled' => true]);
        $user = User::factory()->create();
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $oldRunId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $newRunId = '77f83175-d4bf-46a9-a481-a4b0b04f45c7';
        $oldInstance = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $newInstance = '5f950d9b-5538-4d11-82f9-d916d1ea0777';
        $outbox = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'improve',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'dedupe_key' => hash('sha256', 'missing-improve-after-restart'),
            'payload' => [
                'phase' => 'improve_polling',
                'pipeline_run_id' => $oldRunId,
                'cognee_dataset_id' => $datasetId,
                'cognee_instance_id' => $oldInstance,
            ],
            'status' => 'queued',
        ]);
        $cognee = new class($datasetId, $newRunId, $newInstance) extends CogneeClient
        {
            public int $launches = 0;

            public function __construct(
                private string $datasetId,
                private string $newRunId,
                private string $newInstance,
            ) {}

            public function enabled(): bool
            {
                return true;
            }

            public function observedInstanceId(): ?string
            {
                return $this->newInstance;
            }

            public function observedLaunchInstanceId(): ?string
            {
                return $this->newInstance;
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                return $this->newInstance;
            }

            public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
            {
                throw new CogneeRequestException('/api/v1/luczor/pipeline-runs/'.$runId, 404);
            }

            public function acknowledgeLaunch(string $idempotencyKey, bool $throw = false): bool
            {
                return true;
            }

            public function improveOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->launches++;

                return [
                    'pipeline_run_id' => $this->newRunId,
                    'dataset_id' => $this->datasetId,
                    'status' => 'PipelineRunStarted',
                ];
            }
        };

        (new MemoryProjectionService($cognee))->process($outbox->id);

        $this->assertSame('pending', $outbox->fresh()->status);
        $this->assertSame('improve_polling', $outbox->fresh()->payload['phase']);
        $this->assertSame($newRunId, $outbox->fresh()->payload['pipeline_run_id']);
        $this->assertSame(1, $outbox->fresh()->payload['recovery_generation']);
        $this->assertSame(1, $cognee->launches);
    }

    public function test_improve_never_launches_without_the_authenticated_runtime_guard(): void
    {
        config(['luczor.cognee.improve_enabled' => true]);
        $user = User::factory()->create();
        $outbox = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'improve',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'dedupe_key' => hash('sha256', 'unguarded-improve'),
            'payload' => [],
            'status' => 'queued',
        ]);
        $cognee = new class extends CogneeClient
        {
            public int $launches = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                return null;
            }

            public function improveOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->launches++;

                return [];
            }
        };

        try {
            (new MemoryProjectionService($cognee))->process($outbox->id);
            $this->fail('Improve must require the authenticated Luczor runtime guard.');
        } catch (\RuntimeException) {
            // Expected fail-closed behavior before the external launch.
        }

        $this->assertSame('failed', $outbox->fresh()->status);
        $this->assertSame(0, $cognee->launches);
    }

    public function test_improve_is_opt_in_requires_ready_memory_and_coalesces_per_dataset(): void
    {
        Queue::fake();
        config([
            'luczor.cognee.base_url' => 'http://cognee:8000',
            'luczor.cognee.api_key' => 'test-service-key',
            'luczor.cognee.improve_enabled' => true,
            'luczor.cognee.improve_min_interval_seconds' => 3600,
        ]);
        [, $token] = $this->token(['brain.write']);

        $this->withHeader('X-Api-Key', $token)
            ->postJson('/api/v1/memory/improve', ['scope' => 'project', 'project_id' => 'p1'])
            ->assertOk()
            ->assertJsonPath('scheduled', false);
        $this->assertSame(0, MemoryProjectionOutbox::count());

        // Use a fresh actor so the deliberately strict per-minute limiter does
        // not include the preceding no-op eligibility probe.
        [$secondUser, $secondToken] = $this->token(['brain.write']);
        $secondDataset = app(LuczorMemoryService::class)->datasetFor('project', [
            'user_id' => $secondUser->id,
            'project_id' => 'p1',
        ]);
        MemoryLink::create([
            'user_id' => $secondUser->id,
            'external_id' => 'ready-for-coalescing',
            'scope' => 'project',
            'dataset' => $secondDataset,
            'project_id' => 'p1',
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'sensitivity' => 'normal',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Ein zweiter sicherer Ausgangspunkt.',
            'content_hash' => hash('sha256', 'Ein zweiter sicherer Ausgangspunkt.'),
            'valid_from' => now(),
            'projection_status' => 'ready',
        ]);

        $this->withHeader('X-Api-Key', $secondToken)
            ->postJson('/api/v1/memory/improve', ['scope' => 'project', 'project_id' => 'p1'])
            ->assertOk()
            ->assertJsonPath('scheduled', true);
        $this->withHeader('X-Api-Key', $secondToken)
            ->postJson('/api/v1/memory/improve', ['scope' => 'project', 'project_id' => 'p1'])
            ->assertOk()
            ->assertJsonPath('scheduled', false);
        $this->withHeader('X-Api-Key', $secondToken)
            ->postJson('/api/v1/memory/improve', ['scope' => 'project', 'project_id' => 'p1'])
            ->assertStatus(429);

        $this->assertSame(1, MemoryProjectionOutbox::query()
            ->where('dataset', $secondDataset)
            ->where('action', 'improve')
            ->count());
        Queue::assertPushed(ProcessMemoryProjection::class, 1);
    }

    public function test_improve_targets_an_authorized_previous_dataset_alias_after_namespace_key_rotation(): void
    {
        Queue::fake();
        $oldKey = 'old-memory-namespace-key-with-at-least-32-bytes';
        $newKey = 'new-memory-namespace-key-with-at-least-32-bytes';
        config([
            'luczor.cognee.improve_enabled' => true,
            'luczor.cognee.improve_min_interval_seconds' => 3600,
            'luczor.memory.namespace_key' => $oldKey,
            'luczor.memory.previous_namespace_keys' => [],
        ]);
        $user = User::factory()->create();
        $oldService = new LuczorMemoryService(new CogneeClient('http://cognee:8000', 'key'));
        $oldDataset = $oldService->datasetFor('project', [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]);
        MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'ready-before-key-rotation',
            'scope' => 'project',
            'dataset' => $oldDataset,
            'project_id' => 'p1',
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'sensitivity' => 'normal',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Vor der Namespace-Rotation projiziert.',
            'content_hash' => hash('sha256', 'Vor der Namespace-Rotation projiziert.'),
            'valid_from' => now(),
            'projection_status' => 'ready',
        ]);

        config([
            'luczor.memory.namespace_key' => $newKey,
            'luczor.memory.previous_namespace_keys' => [$oldKey],
        ]);
        $rotatedService = new LuczorMemoryService(new CogneeClient('http://cognee:8000', 'key'));
        $this->assertNotSame($oldDataset, $rotatedService->datasetFor('project', [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]));

        $this->assertTrue($rotatedService->improve('project', [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]));
        $this->assertDatabaseHas('memory_projection_outbox', [
            'dataset' => $oldDataset,
            'action' => 'improve',
            'status' => 'queued',
        ]);
        $this->assertSame(1, MemoryProjectionOutbox::query()->where('action', 'improve')->count());
        Queue::assertPushed(ProcessMemoryProjection::class, 1);
    }

    public function test_terminal_failed_improve_backoff_never_blocks_a_later_delete(): void
    {
        config(['luczor.cognee.improve_enabled' => true]);
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $improve = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'improve',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'terminal-failed-improve'),
            'payload' => [
                'phase' => 'improve_polling',
                'pipeline_run_id' => $runId,
                'cognee_dataset_id' => $datasetId,
                'cognee_instance_id' => $instanceId,
                'improve_started_at' => now()->subMinute()->toIso8601String(),
            ],
            'status' => 'queued',
        ]);
        $cognee = new class($datasetId, $runId, $instanceId) extends CogneeClient
        {
            public int $forgets = 0;

            public function __construct(
                private string $datasetIdValue,
                private string $runIdValue,
                private string $instanceIdValue,
            ) {}

            public function enabled(): bool
            {
                return true;
            }

            public function observedInstanceId(): ?string
            {
                return $this->instanceIdValue;
            }

            public function pipelineRun(string $datasetId, string $runId, bool $throw = false): ?array
            {
                return [
                    'pipeline_name' => 'improve_pipeline',
                    'pipeline_run_id' => $this->runIdValue,
                    'dataset_id' => $this->datasetIdValue,
                    'status' => 'DATASET_PROCESSING_FAILED',
                ];
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                ];
            }
        };
        $service = new MemoryProjectionService($cognee);

        try {
            $service->process($improve->id);
            $this->fail('The terminal failed improve must retain its retry diagnostics.');
        } catch (\RuntimeException) {
            // The exact failed run is no longer live and may yield the turn.
        }
        $this->assertSame('failed', $improve->fresh()->status);
        $this->assertSame('new', $improve->fresh()->payload['phase']);
        $this->assertTrue($improve->fresh()->next_attempt_at->isFuture());

        $delete = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'delete',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'delete-after-failed-improve'),
            'payload' => ['cognee_memory_id' => $dataId],
            'status' => 'queued',
        ]);
        $service->process($delete->id);

        $this->assertSame('done', $delete->fresh()->status);
        $this->assertSame(1, $cognee->forgets);
    }

    public function test_improve_claim_is_visible_before_runtime_probe_and_serializes_a_new_delete(): void
    {
        config(['luczor.cognee.improve_enabled' => true]);
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $runId = '744a537f-bb81-4637-8287-79b5c55f0913';
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $improve = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'improve',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'atomic-improve-before-probe'),
            'payload' => [],
            'status' => 'queued',
        ]);
        $cognee = new class($user->id, $dataset, $datasetId, $runId, $instanceId, $dataId) extends CogneeClient
        {
            public int $improves = 0;

            public int $forgets = 0;

            public ?int $deleteId = null;

            public function __construct(
                private int $userIdValue,
                private string $datasetValue,
                private string $datasetIdValue,
                private string $runIdValue,
                private string $instanceIdValue,
                private string $dataIdValue,
            ) {}

            public function enabled(): bool
            {
                return true;
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                $delete = MemoryProjectionOutbox::create([
                    'user_id' => $this->userIdValue,
                    'action' => 'delete',
                    'dataset' => $this->datasetValue,
                    'dedupe_key' => hash('sha256', 'delete-created-during-improve-probe'),
                    'payload' => ['cognee_memory_id' => $this->dataIdValue],
                    'status' => 'queued',
                ]);
                $this->deleteId = $delete->id;
                (new MemoryProjectionService($this))->process($delete->id);

                return $this->instanceIdValue;
            }

            public function improveOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->improves++;

                return [
                    'pipeline_run_id' => $this->runIdValue,
                    'dataset_id' => $this->datasetIdValue,
                    'status' => 'PipelineRunStarted',
                ];
            }

            public function observedInstanceId(): ?string
            {
                return $this->instanceIdValue;
            }

            public function observedLaunchInstanceId(): ?string
            {
                return $this->instanceIdValue;
            }

            public function acknowledgeLaunch(string $idempotencyKey, bool $throw = false): bool
            {
                return true;
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                ];
            }
        };

        (new MemoryProjectionService($cognee))->process($improve->id);

        $delete = MemoryProjectionOutbox::query()->findOrFail($cognee->deleteId);
        $this->assertSame('improve_polling', $improve->fresh()->payload['phase']);
        $this->assertSame('pending', $delete->status);
        $this->assertTrue($delete->next_attempt_at->isFuture());
        $this->assertSame(1, $cognee->improves);
        $this->assertSame(0, $cognee->forgets);
    }

    public function test_ambiguous_improve_retry_stays_protected_when_its_runtime_probe_fails(): void
    {
        config(['luczor.cognee.improve_enabled' => true]);
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $launchKey = hash('sha256', 'ambiguous-improve-retry');
        $improve = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'improve',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'ambiguous-improve-outbox'),
            'payload' => [
                'phase' => 'improve_launching',
                'launch_key' => $launchKey,
                'launch_generation' => 1,
                'launch_intent_at' => now()->subMinute()->toIso8601String(),
            ],
            'status' => 'queued',
        ]);
        $cognee = new class extends CogneeClient
        {
            public int $launches = 0;

            public int $forgets = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                return null;
            }

            public function improveOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->launches++;

                return [];
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                ];
            }
        };
        $service = new MemoryProjectionService($cognee);

        try {
            $service->process($improve->id);
            $this->fail('An unavailable runtime probe must keep an ambiguous Improve retry protected.');
        } catch (\RuntimeException) {
            // No new POST was made, but the prior durable POST remains ambiguous.
        }

        $this->assertSame('improve_launching', $improve->fresh()->payload['phase']);
        $this->assertSame('failed', $improve->fresh()->status);
        $this->assertSame(0, $cognee->launches);

        $delete = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'delete',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'delete-after-ambiguous-improve'),
            'payload' => ['cognee_memory_id' => $dataId],
            'status' => 'queued',
        ]);
        $service->process($delete->id);

        $this->assertSame('pending', $delete->fresh()->status);
        $this->assertTrue($delete->fresh()->next_attempt_at->isFuture());
        $this->assertSame(0, $cognee->forgets);
    }

    public function test_delete_rechecks_dataset_ownership_after_acquiring_the_content_lock(): void
    {
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $contentHash = hash('sha256', 'concurrent-cognify-delete');
        $upsert = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'concurrent-cognify-upsert'),
            'payload' => [
                'phase' => 'cognify_failed',
                'content_hash' => $contentHash,
                'cognee_memory_id' => $dataId,
            ],
            'status' => 'queued',
        ]);
        $delete = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'delete',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'concurrent-cognify-delete'),
            'payload' => [
                'cognee_memory_id' => $dataId,
                'content_hash' => $contentHash,
            ],
            'status' => 'queued',
        ]);
        $cognee = new class extends CogneeClient
        {
            public int $forgets = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function forget(string $dataset, string $memoryId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                ];
            }
        };
        $lock = \Mockery::mock();
        $lock->shouldReceive('get')->once()->andReturnUsing(function () use ($upsert): bool {
            $payload = $upsert->fresh()->payload;
            $payload['phase'] = 'cognify_launching';
            $upsert->update(['payload' => $payload]);

            return true;
        });
        $lock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->andReturn($lock);

        (new MemoryProjectionService($cognee))->process($delete->id);

        $this->assertSame('cognify_launching', $upsert->fresh()->payload['phase']);
        $this->assertSame('pending', $delete->fresh()->status);
        $this->assertTrue($delete->fresh()->next_attempt_at->isFuture());
        $this->assertSame(0, $cognee->forgets);
    }

    public function test_acknowledged_delete_replays_stale_local_link_cleanup_without_repeating_forget(): void
    {
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $contentHash = hash('sha256', 'acknowledged-delete-local-replay');
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'acknowledged-delete-local-replay',
            'scope' => 'project',
            'dataset' => $dataset,
            'project_id' => 'p1',
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'superseded',
            'retention' => 'durable',
            'sensitivity' => 'normal',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'The provider deletion was already acknowledged.',
            'content_hash' => $contentHash,
            'cognee_memory_id' => $dataId,
            'projection_status' => 'delete_pending',
            'valid_from' => now()->subDay(),
        ]);
        $delete = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'memory_link_id' => $link->id,
            'action' => 'delete',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'acknowledged-delete-local-replay'),
            'payload' => [
                'cognee_memory_id' => $dataId,
                'content_hash' => $contentHash,
                'exact_forget_ack_at' => now()->subMinute()->toIso8601String(),
            ],
            'status' => 'queued',
        ]);
        $cognee = new class extends CogneeClient
        {
            public int $forgets = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [];
            }
        };
        $lock = \Mockery::mock();
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->andReturn($lock);

        (new MemoryProjectionService($cognee))->process($delete->id);

        $this->assertSame('done', $delete->fresh()->status);
        $this->assertNull($link->fresh()->cognee_memory_id);
        $this->assertSame('not_required', $link->fresh()->projection_status);
        $this->assertSame(0, $cognee->forgets);
    }

    public function test_content_lock_contention_defers_without_consuming_a_projection_attempt(): void
    {
        $user = User::factory()->create();
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $outbox = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'delete',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'dedupe_key' => hash('sha256', 'content-lock-contended-delete'),
            'payload' => [
                'cognee_memory_id' => $dataId,
                'content_hash' => hash('sha256', 'content-lock-contended-delete'),
            ],
            'status' => 'queued',
            'attempts' => 0,
        ]);
        $cognee = new class extends CogneeClient
        {
            public int $forgets = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [];
            }
        };
        $lock = \Mockery::mock();
        $lock->shouldReceive('get')->once()->andReturnFalse();
        $lock->shouldNotReceive('release');
        Cache::shouldReceive('lock')->once()->andReturn($lock);

        (new MemoryProjectionService($cognee))->process($outbox->id);

        $outbox->refresh();
        $this->assertSame('pending', $outbox->status);
        $this->assertSame(0, $outbox->attempts);
        $this->assertNull($outbox->last_error);
        $this->assertTrue($outbox->next_attempt_at->isFuture());
        $this->assertSame(0, $cognee->forgets);
    }

    public function test_terminal_http_420_improve_rejection_releases_the_dataset_for_delete(): void
    {
        config(['luczor.cognee.improve_enabled' => true]);
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $instanceId = '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $improve = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'improve',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'http-420-improve'),
            'payload' => [],
            'status' => 'queued',
        ]);
        $cognee = new class($instanceId) extends CogneeClient
        {
            public int $improves = 0;

            public int $forgets = 0;

            public function __construct(private string $instanceIdValue) {}

            public function enabled(): bool
            {
                return true;
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                return $this->instanceIdValue;
            }

            public function improveOnce(string $dataset, string $idempotencyKey, bool $throw = false): array
            {
                $this->improves++;

                throw new CogneeRequestException('/api/v1/improve', 420, [
                    'pipeline_run_id' => '744a537f-bb81-4637-8287-79b5c55f0913',
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                    'status' => 'PipelineRunErrored',
                ]);
            }

            public function acknowledgeLaunch(string $idempotencyKey, bool $throw = false): bool
            {
                return true;
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                ];
            }
        };
        $service = new MemoryProjectionService($cognee);

        try {
            $service->process($improve->id);
            $this->fail('Cognee HTTP 420 must be recorded as a terminal Improve failure.');
        } catch (CogneeRequestException) {
            // The wrapper response proves that the Improve run is terminal.
        }
        $this->assertSame('failed', $improve->fresh()->status);
        $this->assertSame('new', $improve->fresh()->payload['phase']);
        $this->assertSame(420, $improve->fresh()->payload['last_launch_rejection_status']);
        $this->assertArrayNotHasKey('launch_key', $improve->fresh()->payload);

        $delete = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'delete',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'delete-after-http-420-improve'),
            'payload' => ['cognee_memory_id' => $dataId],
            'status' => 'queued',
        ]);
        $service->process($delete->id);

        $this->assertSame('done', $delete->fresh()->status);
        $this->assertSame(1, $cognee->improves);
        $this->assertSame(1, $cognee->forgets);
    }

    public function test_forget_wakes_a_terminal_failed_cognify_and_deletes_its_payload_data_immediately(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $datasetId = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';
        $cognee = new class extends CogneeClient
        {
            public int $forgets = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                ];
            }
        };
        $store = new LuczorMemoryService($cognee);
        $dataset = $store->datasetFor('project', [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]);
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'forget-terminal-cognify',
            'scope' => 'project',
            'dataset' => $dataset,
            'project_id' => 'p1',
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Forget a terminal failed projection without waiting for backoff.',
            'content_hash' => hash('sha256', 'Forget a terminal failed projection without waiting for backoff.'),
            'valid_from' => now(),
            'projection_status' => 'failed',
        ]);
        $upsert = MemoryProjectionOutbox::create([
            'memory_link_id' => $link->id,
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'forget-terminal-cognify-upsert'),
            'payload' => [
                'phase' => 'cognify_failed',
                'content_hash' => $link->content_hash,
                'cognee_memory_id' => $dataId,
                'cognee_dataset_id' => $datasetId,
            ],
            'status' => 'failed',
            'attempts' => 3,
            'next_attempt_at' => now()->addHour(),
        ]);

        $this->assertTrue($store->forget('project', $link->external_id, [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]));

        $this->assertDatabaseMissing('memory_links', ['id' => $link->id]);
        $this->assertSame('queued', $upsert->fresh()->status);
        $this->assertNull($upsert->fresh()->next_attempt_at);
        $delete = MemoryProjectionOutbox::query()->where('action', 'delete')->firstOrFail();
        $this->assertSame($dataId, $delete->payload['cognee_memory_id']);

        (new MemoryProjectionService($cognee))->process($delete->id);

        $this->assertSame('done', $delete->fresh()->status);
        $this->assertSame(1, $cognee->forgets);

        // The awakened upsert must reconcile against the exact same delete
        // identity instead of creating a second Forget for the deleted link.
        (new MemoryProjectionService($cognee))->process($upsert->id);
        $this->assertSame('done', $upsert->fresh()->status);
        $this->assertSame(1, MemoryProjectionOutbox::query()->where('action', 'delete')->count());
        $this->assertSame(1, $cognee->forgets);
    }

    public function test_failed_delete_backoff_keeps_priority_over_a_new_improve(): void
    {
        config(['luczor.cognee.improve_enabled' => true]);
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $delete = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'delete',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'failed-delete-owner'),
            'payload' => ['cognee_memory_id' => '09393f2c-238c-4db8-853e-7e71fa2bd9bd'],
            'status' => 'failed',
            'attempts' => 2,
            'next_attempt_at' => now()->addHour(),
        ]);
        $improve = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'improve',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'improve-after-failed-delete'),
            'payload' => [],
            'status' => 'queued',
        ]);
        $cognee = new class extends CogneeClient
        {
            public int $probes = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function probeRuntime(bool $throw = false): ?string
            {
                $this->probes++;

                return '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
            }
        };

        (new MemoryProjectionService($cognee))->process($improve->id);

        $this->assertSame('failed', $delete->fresh()->status);
        $this->assertSame('pending', $improve->fresh()->status);
        $this->assertTrue($improve->fresh()->next_attempt_at->isFuture());
        $this->assertSame(0, $cognee->probes);
    }

    public function test_projection_reconciler_activates_future_memory_and_removes_expired_projection(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $future = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'future-memory',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Diese Entscheidung gilt ab morgen.',
            'content_hash' => hash('sha256', 'future'),
            'valid_from' => now()->addDay(),
            'projection_status' => 'deferred',
        ]);
        $expired = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'expired-projection',
            'scope' => 'project',
            'dataset' => $future->dataset,
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'Diese Projektion ist dann abgelaufen.',
            'content_hash' => hash('sha256', 'expired'),
            'valid_until' => now()->addHours(12),
            'cognee_memory_id' => '09393f2c-238c-4db8-853e-7e71fa2bd9bd',
            'projection_status' => 'ready',
        ]);
        $cognee = new CogneeClient('http://cognee:8000', 'key');
        $reconciler = new MemoryProjectionReconciler($cognee);

        $this->travel(25)->hours();
        $result = $reconciler->reconcile();

        $this->assertSame(['upserts' => 1, 'deletes' => 1], $result);
        $this->assertDatabaseHas('memory_links', ['id' => $future->id, 'projection_status' => 'pending']);
        $this->assertDatabaseHas('memory_links', ['id' => $expired->id, 'projection_status' => 'delete_pending']);
        $this->assertDatabaseHas('memory_projection_outbox', [
            'memory_link_id' => $future->id,
            'action' => 'upsert',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('memory_projection_outbox', [
            'memory_link_id' => $expired->id,
            'action' => 'delete',
            'status' => 'pending',
        ]);
        $delete = MemoryProjectionOutbox::query()->where('memory_link_id', $expired->id)->firstOrFail();
        $this->assertSame($expired->cognee_memory_id, $delete->payload['cognee_memory_id']);
        $this->travelBack();
    }

    public function test_reconciler_never_auto_projects_legacy_or_dlp_blocked_deferred_rows(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $base = [
            'user_id' => $user->id,
            'scope' => 'project',
            'dataset' => $dataset,
            'project_id' => 'p1',
            'type' => 'note',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'sensitivity' => 'normal',
            'staleness' => 'fresh',
            'importance' => 0.5,
            'confidence' => 0.8,
            'valid_from' => now()->subMinute(),
        ];
        $legacy = MemoryLink::create($base + [
            'external_id' => 'legacy-review-only',
            'summary' => 'Diese Alt-Erinnerung ist noch nicht klassifiziert.',
            'content_hash' => hash('sha256', 'Diese Alt-Erinnerung ist noch nicht klassifiziert.'),
            'source_type' => 'user',
            'projection_status' => 'legacy_review_required',
        ]);
        $blocked = MemoryLink::create($base + [
            'external_id' => 'blocked-deferred-repository',
            'summary' => 'Repository-Detail aus einer lokalen Analyse.',
            'content_hash' => hash('sha256', 'Repository-Detail aus einer lokalen Analyse.'),
            'source_type' => 'user',
            'meta' => ['origin_type' => 'repository_graph'],
            'projection_status' => 'deferred',
        ]);

        $result = (new MemoryProjectionReconciler(
            new CogneeClient('http://cognee:8000', 'key')
        ))->reconcile();

        $this->assertSame(['upserts' => 0, 'deletes' => 0], $result);
        $this->assertDatabaseHas('memory_links', [
            'id' => $legacy->id,
            'projection_status' => 'legacy_review_required',
        ]);
        $this->assertDatabaseHas('memory_links', [
            'id' => $blocked->id,
            'projection_status' => 'not_required',
        ]);
        $this->assertSame(0, MemoryProjectionOutbox::count());
        Queue::assertNothingPushed();
    }

    public function test_reconciler_scrubs_legacy_and_dlp_blocked_existing_cognee_projections(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $base = [
            'user_id' => $user->id,
            'scope' => 'project',
            'dataset' => $dataset,
            'project_id' => 'p1',
            'type' => 'note',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'sensitivity' => 'normal',
            'staleness' => 'fresh',
            'importance' => 0.5,
            'confidence' => 0.8,
            'valid_from' => now()->subMinute(),
        ];
        $legacy = MemoryLink::create($base + [
            'external_id' => 'projected-legacy-review',
            'summary' => 'Nicht klassifizierter Altbestand.',
            'content_hash' => hash('sha256', 'Nicht klassifizierter Altbestand.'),
            'source_type' => 'user',
            'cognee_memory_id' => '09393f2c-238c-4db8-853e-7e71fa2bd9bd',
            'projection_status' => 'legacy_review_required',
        ]);
        $blocked = MemoryLink::create($base + [
            'external_id' => 'projected-repository-memory',
            'summary' => 'Lokaler Repository-Kontext.',
            'content_hash' => hash('sha256', 'Lokaler Repository-Kontext.'),
            'source_type' => 'user',
            'meta' => ['origin_type' => 'repository_graph'],
            'cognee_memory_id' => '88588824-946d-43de-86ee-17ee4212ca65',
            'projection_status' => 'ready',
        ]);
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'status' => 'success',
                'data_id' => $legacy->cognee_memory_id,
                'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'status' => 'success',
                'data_id' => $blocked->cognee_memory_id,
                'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
            ], JSON_THROW_ON_ERROR)),
        ]))]);
        $cognee = new CogneeClient('http://cognee:8000', 'key', 15, $http);
        $reconciler = new MemoryProjectionReconciler($cognee);

        $this->assertSame(['upserts' => 0, 'deletes' => 2], $reconciler->reconcile());
        $outboxes = MemoryProjectionOutbox::query()->where('action', 'delete')->orderBy('id')->get();
        $this->assertCount(2, $outboxes);
        $this->assertSame('legacy_review_required', $outboxes[0]->payload['final_projection_status']);
        $this->assertSame('not_required', $outboxes[1]->payload['final_projection_status']);

        $service = new MemoryProjectionService($cognee);
        $service->process($outboxes[0]->id);
        $service->process($outboxes[1]->id);

        $this->assertDatabaseHas('memory_links', [
            'id' => $legacy->id,
            'cognee_memory_id' => null,
            'projection_status' => 'legacy_review_required',
        ]);
        $this->assertDatabaseHas('memory_links', [
            'id' => $blocked->id,
            'cognee_memory_id' => null,
            'projection_status' => 'not_required',
        ]);
    }

    public function test_shared_cognee_data_is_retained_while_an_active_sql_reference_exists(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $dataset = "tenant:personal:user:{$user->id}:project:p1";
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $contentHash = hash('sha256', 'identischer Inhalt');
        $expired = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'expired-shared-memory',
            'scope' => 'project',
            'dataset' => $dataset,
            'type' => 'fact',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'identischer Inhalt',
            'content_hash' => $contentHash,
            'valid_until' => now()->subMinute(),
            'cognee_memory_id' => $dataId,
            'projection_status' => 'ready',
        ]);
        $active = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'active-shared-memory',
            'scope' => 'project',
            'dataset' => $dataset,
            'type' => 'fact',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.8,
            'confidence' => 0.9,
            'summary' => 'identischer Inhalt',
            'content_hash' => $contentHash,
            'valid_from' => now()->subMinute(),
            // The replacement is active but its shared Data UUID may still be
            // present only in an in-flight projection outbox.
            'cognee_memory_id' => null,
            'projection_status' => 'processing',
        ]);
        $cognee = new class extends CogneeClient
        {
            public int $forgets = 0;

            public function enabled(): bool
            {
                return true;
            }

            public function forget(string $dataset, string $dataId, bool $throw = false): array
            {
                $this->forgets++;

                return [
                    'status' => 'success',
                    'data_id' => $dataId,
                    'dataset_id' => '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9',
                ];
            }
        };
        $reconciler = new MemoryProjectionReconciler($cognee);

        $this->assertSame(['upserts' => 0, 'deletes' => 1], $reconciler->reconcile());
        $outbox = MemoryProjectionOutbox::query()->where('memory_link_id', $expired->id)->firstOrFail();
        (new MemoryProjectionService($cognee))->process($outbox->id);

        $this->assertDatabaseHas('memory_links', [
            'id' => $expired->id,
            'cognee_memory_id' => null,
            'projection_status' => 'not_required',
        ]);
        $this->assertDatabaseHas('memory_links', [
            'id' => $active->id,
            'cognee_memory_id' => $dataId,
            'projection_status' => 'processing',
        ]);
        $this->assertDatabaseHas('memory_projection_outbox', ['id' => $outbox->id, 'status' => 'done']);
        $this->assertSame(0, $cognee->forgets);

        // The retained UUID must survive on the replacement long enough for a
        // subsequent Forget to enqueue and execute the final erasure.
        $store = new LuczorMemoryService($cognee);
        $this->assertTrue($store->forget('project', $active->external_id, [
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]));
        $lastDelete = MemoryProjectionOutbox::query()
            ->where('action', 'delete')
            ->where('status', 'queued')
            ->latest('id')
            ->firstOrFail();
        (new MemoryProjectionService($cognee))->process($lastDelete->id);

        $this->assertSame('done', $lastDelete->fresh()->status);
        $this->assertSame(1, $cognee->forgets);
    }

    public function test_untrusted_cognee_hit_is_revalidated_against_user_owned_sql_rows(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $firstLink = MemoryLink::create([
            'user_id' => $first->id,
            'external_id' => 'first-secret',
            'scope' => 'agent',
            'dataset' => "tenant:personal:user:{$first->id}:agent:planner:runs",
            'type' => 'note',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.9,
            'confidence' => 0.9,
            'summary' => 'Only the first user may read this.',
            'content_hash' => hash('sha256', 'first'),
            'cognee_memory_id' => '09393f2c-238c-4db8-853e-7e71fa2bd9bd',
        ]);
        $body = json_encode([[
            'dataset_name' => $firstLink->dataset,
            'search_result' => [[
                'text' => $firstLink->summary,
                'document_id' => $firstLink->cognee_memory_id,
            ]],
        ]], JSON_THROW_ON_ERROR);
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)]))]);
        $store = new LuczorMemoryService(new CogneeClient('http://cognee:8000', 'key', 15, $http));

        $hits = $store->recall('first user', 'agent', [
            'tenant_id' => null,
            'user_id' => $second->id,
            'agent_id' => 'planner',
        ]);

        $this->assertSame([], $hits);
    }

    public function test_cognee_document_id_only_ranks_an_active_authorized_sql_memory(): void
    {
        $user = User::factory()->create();
        $dataId = '09393f2c-238c-4db8-853e-7e71fa2bd9bd';
        $link = MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'semantic-memory',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'decision',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 0.7,
            'confidence' => 0.9,
            'summary' => 'Die semantisch passende Architekturentscheidung.',
            'content_hash' => hash('sha256', 'Die semantisch passende Architekturentscheidung.'),
            'cognee_memory_id' => $dataId,
            'valid_from' => now()->subDay(),
        ]);
        $body = json_encode([[
            'dataset_name' => $link->dataset,
            'search_result' => [['document_id' => $dataId, 'text' => $link->summary]],
        ]], JSON_THROW_ON_ERROR);
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)]))]);
        $store = new LuczorMemoryService(new CogneeClient('http://cognee:8000', 'key', 15, $http));

        $hits = $store->recall('unverwandte Suchworte', 'project', [
            'tenant_id' => null,
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]);

        $this->assertSame('semantic-memory', $hits[0]['id']);
        $this->assertSame('cognee_revalidated', $hits[0]['source']);
    }

    public function test_recall_excludes_memories_outside_their_validity_window(): void
    {
        $user = User::factory()->create();
        MemoryLink::create([
            'user_id' => $user->id,
            'external_id' => 'expired-memory',
            'scope' => 'project',
            'dataset' => "tenant:personal:user:{$user->id}:project:p1",
            'type' => 'fact',
            'visibility' => 'syncable',
            'status' => 'active',
            'retention' => 'durable',
            'staleness' => 'fresh',
            'importance' => 1,
            'confidence' => 1,
            'summary' => 'Diese Information ist nicht mehr gültig.',
            'content_hash' => hash('sha256', 'Diese Information ist nicht mehr gültig.'),
            'valid_until' => now()->subMinute(),
        ]);
        $store = new LuczorMemoryService(new CogneeClient);

        $this->assertSame([], $store->recall('Information', 'project', [
            'tenant_id' => null,
            'user_id' => $user->id,
            'project_id' => 'p1',
        ]));
    }

    /** @return array{0:User,1:string} */
    private function token(array $abilities, array $userAttributes = []): array
    {
        $user = User::factory()->create($userAttributes);
        $minted = ApiKey::mint([
            'user_id' => $user->id,
            'name' => 'Test Device',
            'abilities' => $abilities,
            'active' => true,
        ]);

        return [$user, $minted['plain']];
    }
}
