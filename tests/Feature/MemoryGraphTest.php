<?php

namespace Tests\Feature;

use App\Models\MemoryLink;
use App\Models\Project;
use App\Models\User;
use App\Services\AdminDashboardData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** SOLL §15 P20 — interactive 3D memory relationship graph on the archives page. */
class MemoryGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_archives_page_renders_the_relationship_graph_with_memory_data(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $project = Project::create(['user_id' => $admin->id, 'external_id' => 'proj-1', 'name' => 'Luczor Kern', 'status' => 'active']);
        $original = MemoryLink::create([
            'user_id' => $admin->id, 'scope' => 'project', 'dataset' => "user:{$admin->id}:projects:proj-1",
            'project_id' => 'proj-1', 'project_ref_id' => $project->id,
            'type' => 'note', 'visibility' => 'syncable', 'staleness' => 'fresh',
            'importance' => 0.6, 'confidence' => 0.75, 'summary' => 'Der Kunde nutzt Plesk als Deployment-Ziel.',
        ]);
        MemoryLink::create([
            'user_id' => $admin->id, 'scope' => 'project', 'dataset' => "user:{$admin->id}:projects:proj-1",
            'project_id' => 'proj-1', 'project_ref_id' => $project->id,
            'type' => 'decision', 'visibility' => 'syncable', 'staleness' => 'fresh',
            'importance' => 0.9, 'confidence' => 0.95, 'summary' => 'Deployments werden weiterhin über Plesk ausgeführt.',
            'supersedes_id' => $original->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.page', 'archives'))
            ->assertOk()
            ->assertSee('3D-Beziehungsgraph')
            ->assertSee('data-memory-network-3d', false)
            ->assertSee('data-memory-network-canvas', false)
            ->assertSee('data-memory-network-announcer', false)
            ->assertSee('aria-controls="memory-network-inspector-detail"', false)
            ->assertSee('Ansicht zurücksetzen')
            ->assertSee('Barrierefreie Alternative zur 3D-Ansicht')
            ->assertSee('Echte Versionskanten')
            ->assertSee('Luczor Kern')
            ->assertSee('Plesk als Deployment-Ziel');
    }

    public function test_graph_section_shows_empty_state_without_memories(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.page', 'archives'))
            ->assertOk()
            ->assertSee('Noch keine Erinnerungen')
            ->assertDontSee('data-memory-network-canvas', false);
    }

    public function test_archives_memory_network_remains_admin_only(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'user']);

        $this->actingAs($user)
            ->get(route('admin.page', 'archives'))
            ->assertForbidden();
    }

    public function test_projects_with_the_same_display_name_keep_separate_network_hubs(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $secondOwner = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $firstProject = Project::create(['user_id' => $admin->id, 'external_id' => 'shared-a', 'name' => 'Gemeinsamer Name', 'status' => 'active']);
        $secondProject = Project::create(['user_id' => $secondOwner->id, 'external_id' => 'shared-b', 'name' => 'Gemeinsamer Name', 'status' => 'active']);

        foreach ([$firstProject, $secondProject] as $project) {
            MemoryLink::create([
                'user_id' => $project->user_id,
                'scope' => 'project',
                'dataset' => 'project:'.$project->external_id,
                'project_id' => $project->external_id,
                'project_ref_id' => $project->id,
                'type' => 'note',
                'summary' => 'Getrennte Projektidentität '.$project->id,
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.page', 'archives'))
            ->assertOk()
            ->assertSee('<dt>Projekt-Hubs</dt><dd>2</dd>', false)
            ->assertSee('"project_key":"ref:'.$firstProject->id.'"', false)
            ->assertSee('"project_key":"ref:'.$secondProject->id.'"', false);
    }

    public function test_archives_page_uses_a_dedicated_bounded_query_budget(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(AdminDashboardData::class)->forPage('archives');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(13, $queryCount);
    }
}
