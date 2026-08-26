<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemoryIdentityLockPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prunes_only_idle_identity_lock_rows(): void
    {
        DB::table('memory_identity_locks')->insert([
            [
                'identity_hash' => hash('sha256', 'old'),
                'created_at' => now()->subDays(9),
                'updated_at' => now()->subDays(8),
            ],
            [
                'identity_hash' => hash('sha256', 'fresh'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->artisan('luczor:prune-memory-identity-locks --days=7')
            ->expectsOutput('Pruned 1 idle memory identity locks.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('memory_identity_locks', [
            'identity_hash' => hash('sha256', 'old'),
        ]);
        $this->assertDatabaseHas('memory_identity_locks', [
            'identity_hash' => hash('sha256', 'fresh'),
        ]);
    }
}
