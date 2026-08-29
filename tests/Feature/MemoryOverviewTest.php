<?php

namespace Tests\Feature;

use App\Models\MemoryLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemoryOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_archives_page_shows_memory_scope_distribution(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);

        foreach (['project', 'project', 'global'] as $scope) {
            MemoryLink::create([
                'client_id' => 'c1', 'external_id' => (string) Str::uuid(), 'scope' => $scope,
                'dataset' => 'user:1:'.$scope, 'type' => 'note', 'visibility' => 'syncable',
                'summary' => 'x', 'project_id' => 'p1',
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.page', 'archives'))
            ->assertOk()
            ->assertSee('Kanonisches Memory im Überblick')
            ->assertSee('Scopes')
            ->assertSee('project');
    }
}
