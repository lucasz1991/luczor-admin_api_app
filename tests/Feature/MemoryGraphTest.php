<?php

namespace Tests\Feature;

use App\Models\MemoryLink;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SOLL §15 P20 — interactive memory relationship graph on the archives page. */
class MemoryGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_archives_page_renders_the_relationship_graph_with_memory_data(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $project = Project::create(['user_id' => $admin->id, 'external_id' => 'proj-1', 'name' => 'Luczor Kern', 'status' => 'active']);
        MemoryLink::create([
            'user_id' => $admin->id, 'scope' => 'project', 'dataset' => "user:{$admin->id}:projects:proj-1",
            'project_id' => 'proj-1', 'project_ref_id' => $project->id,
            'type' => 'note', 'visibility' => 'syncable', 'staleness' => 'fresh',
            'importance' => 0.8, 'summary' => 'Der Kunde nutzt Plesk als Deployment-Ziel.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.page', 'archives'))
            ->assertOk()
            ->assertSee('Beziehungsgraph')
            ->assertSee('Luczor Kern')
            ->assertSee('Plesk als Deployment-Ziel');
    }

    public function test_graph_section_shows_empty_state_without_memories(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.page', 'archives'))
            ->assertOk()
            ->assertSee('Noch keine Erinnerungen');
    }
}
