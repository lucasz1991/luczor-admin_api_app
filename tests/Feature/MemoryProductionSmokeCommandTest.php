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
