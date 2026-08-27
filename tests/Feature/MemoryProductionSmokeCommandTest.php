<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\MemoryLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
