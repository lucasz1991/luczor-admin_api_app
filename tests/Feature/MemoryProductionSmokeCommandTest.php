<?php

namespace Tests\Feature;

use App\Console\Commands\MemoryProductionSmoke;
use App\Jobs\ProcessMemoryProjection;
use App\Models\ApiKey;
use App\Models\MemoryLink;
use App\Models\MemoryProjectionOutbox;
use App\Models\User;
use App\Services\Cognee\CogneeClient;
use App\Services\MemoryOrchestrator;
use App\Services\MemoryProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use ReflectionMethod;
use Tests\TestCase;

class MemoryProductionSmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_improve_option_runs_one_guarded_improve_and_cleans_every_temporary_identity(): void
    {
        config([
            'luczor.cognee.improve_enabled' => false,
            'luczor.cognee.improve_min_interval_seconds' => 3600,
        ]);

        $cognee = new MemoryProductionSmokeCogneeClient;
        $projections = new MemoryProductionSmokeProjectionService;
        $this->app->instance(CogneeClient::class, $cognee);
        $this->app->instance(MemoryProjectionService::class, $projections);

        $this->artisan('luczor:memory-production-smoke', [
            '--force' => true,
            '--improve' => true,
            '--timeout' => 180,
        ])
            ->expectsOutputToContain(
                'Memory production smoke passed: Remember, Cognify, Improve, semantic recall, SQL fallback, Forget and provider cleanup.'
            )
            ->assertSuccessful();

        $this->assertTrue($projections->improveFlagObserved);
        $this->assertSame(1, $projections->improveCalls);
        $this->assertSame(MemoryProductionSmokeProjectionService::RUN_ID, $projections->improvePayload['pipeline_run_id'] ?? null);
        $this->assertSame(MemoryProductionSmokeProjectionService::DATASET_ID, $projections->improvePayload['cognee_dataset_id'] ?? null);
        $this->assertSame('improve_polling', $projections->improvePayload['phase'] ?? null);
        $this->assertGreaterThanOrEqual(1, $cognee->runtimeProbes);
        $this->assertGreaterThanOrEqual(1, $cognee->semanticSearches);
        $this->assertGreaterThanOrEqual(2, $cognee->exactLookups);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, ApiKey::query()->count());
        $this->assertSame(0, MemoryLink::query()->count());
        $this->assertSame(0, MemoryProjectionOutbox::query()->where('status', '!=', 'done')->count());
        $this->assertFalse(MemoryProjectionOutbox::query()
            ->get(['payload'])
            ->contains(fn (MemoryProjectionOutbox $row): bool => str_contains(
                json_encode($row->payload ?? [], JSON_THROW_ON_ERROR),
                'violettem Nordlicht',
            )));
    }

    public function test_command_refuses_a_production_write_without_explicit_force(): void
    {
        $this->artisan('luczor:memory-production-smoke')
            ->expectsOutputToContain('Refusing a production write without --force.')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, ApiKey::query()->count());
        $this->assertSame(0, MemoryLink::query()->count());
    }

    public function test_cleanup_drains_projection_reopened_by_temporary_account_erasure(): void
    {
        Queue::fake([ProcessMemoryProjection::class]);

        $user = User::factory()->create();
        $dataset = 'luczor:v2:project:'.str_repeat('a', 64);
        $outbox = MemoryProjectionOutbox::create([
            'user_id' => $user->id,
            'action' => 'upsert',
            'dataset' => $dataset,
            'dedupe_key' => hash('sha256', 'production-smoke-account-erasure'),
            'payload' => [
                'phase' => 'polling',
                'content_hash' => hash('sha256', 'synthetic-memory'),
                'provider_memory_link_id' => 123,
                'cognee_memory_id' => 'ea1ae0d8-c24e-55fa-8d16-3ec92c8d1d8c',
            ],
            'status' => 'done',
            'processed_at' => now(),
        ]);

        /** @var MemoryProjectionService&MockInterface $projections */
        $projections = Mockery::mock(MemoryProjectionService::class);
        $projections->shouldReceive('process')
            ->once()
            ->with($outbox->id)
            ->andReturnUsing(function (int $outboxId): void {
                MemoryProjectionOutbox::query()->whereKey($outboxId)->update([
                    'status' => 'done',
                    'next_attempt_at' => null,
                    'processed_at' => now(),
                ]);
            });

        /** @var MemoryOrchestrator&MockInterface $memory */
        $memory = Mockery::mock(MemoryOrchestrator::class);
        /** @var CogneeClient&MockInterface $cognee */
        $cognee = Mockery::mock(CogneeClient::class);

        $cleanup = new ReflectionMethod(MemoryProductionSmoke::class, 'cleanup');
        $cleanup->invoke(
            new MemoryProductionSmoke,
            $user,
            null,
            $memory,
            $projections,
            $cognee,
            'luczor-memory-smoke-test',
            'luczor-project-smoke-test',
            null,
            $dataset,
            null,
            'synthetic marker absent from outbox',
            microtime(true) + 10,
        );

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('memory_projection_outbox', [
            'id' => $outbox->id,
            'user_id' => null,
            'status' => 'done',
        ]);
        $this->assertSame(0, MemoryProjectionOutbox::query()
            ->where('dataset', $dataset)
            ->where('status', '!=', 'done')
            ->count());
    }
}

final class MemoryProductionSmokeCogneeClient extends CogneeClient
{
    public int $runtimeProbes = 0;

    public int $semanticSearches = 0;

    public int $exactLookups = 0;

    public function enabled(): bool
    {
        return true;
    }

    public function probeRuntime(bool $throw = false): ?string
    {
        $this->runtimeProbes++;

        return '18eb4da1-32d8-4b27-9e68-f6e3c00adc67';
    }

    public function searchDatasetsOrFail(array $datasets, string $query, int $topK = 6): array
    {
        $this->semanticSearches++;

        return $datasets === [] ? [] : [[
            'document_id' => MemoryProductionSmokeProjectionService::DATA_ID,
            'score' => 0.99,
        ]];
    }

    public function findData(
        string $dataset,
        int $memoryId,
        string $contentHash,
        bool $throw = false,
    ): array {
        $this->exactLookups++;

        return [
            'dataset_id' => MemoryProductionSmokeProjectionService::DATASET_ID,
            'data_ids' => [],
        ];
    }
}

final class MemoryProductionSmokeProjectionService extends MemoryProjectionService
{
    public const DATA_ID = 'ea1ae0d8-c24e-55fa-8d16-3ec92c8d1d8c';

    public const DATASET_ID = '3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9';

    public const RUN_ID = '744a537f-bb81-4637-8287-79b5c55f0913';

    public int $improveCalls = 0;

    public bool $improveFlagObserved = false;

    /** @var array<string,mixed> */
    public array $improvePayload = [];

    public function __construct() {}

    public function process(int $outboxId): void
    {
        $outbox = MemoryProjectionOutbox::query()->findOrFail($outboxId);
        if ($outbox->status === 'done') {
            return;
        }

        $payload = $outbox->payload ?? [];
        if ($outbox->action === 'upsert') {
            MemoryLink::query()->whereKey($outbox->memory_link_id)->update([
                'cognee_memory_id' => self::DATA_ID,
                'projection_status' => 'ready',
            ]);
            $payload = array_merge($payload, [
                'phase' => 'polling',
                'cognee_memory_id' => self::DATA_ID,
                'cognee_dataset_id' => self::DATASET_ID,
                'pipeline_run_id' => self::RUN_ID,
            ]);
        } elseif ($outbox->action === 'improve') {
            if ($outbox->user_id !== null) {
                $this->improveCalls++;
                $this->improveFlagObserved = (bool) config('luczor.cognee.improve_enabled');
            }
            $payload = array_merge($payload, [
                'phase' => 'improve_polling',
                'cognee_dataset_id' => self::DATASET_ID,
                'pipeline_run_id' => self::RUN_ID,
            ]);
            if ($outbox->user_id !== null) {
                $this->improvePayload = $payload;
            }
        } elseif ($outbox->action === 'delete') {
            $payload['exact_forget_ack_at'] = now()->toIso8601String();
        }

        $outbox->update([
            'payload' => $payload,
            'status' => 'done',
            'attempts' => 0,
            'last_error' => null,
            'next_attempt_at' => null,
            'processed_at' => now(),
        ]);
    }
}
