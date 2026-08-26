<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\MemoryLink;
use App\Models\Skill;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\SkillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SOLL §15 P27 — reusable skill bundles (prompt + workflow). */
class SkillSystemTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
    }

    public function test_admin_creates_a_prompt_skill_and_it_is_listed(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('dashboard.skills.store'), [
            'name' => 'Code-Review-Bündel', 'kind' => 'prompt',
            'description' => 'Prüft Diffs streng.', 'prompt' => 'Prüfe den Diff auf Bugs, Sicherheit und Tests.',
            'tags' => 'review, coding',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $skill = Skill::where('name', 'Code-Review-Bündel')->first();
        $this->assertNotNull($skill);
        $this->assertSame('prompt', $skill->kind);
        $this->assertSame(['review', 'coding'], $skill->tags);

        $this->actingAs($admin)->get(route('admin.page', 'optimizer'))->assertOk()->assertSee('Code-Review-Bündel');
    }

    public function test_prompt_skill_requires_a_prompt(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('dashboard.skills.store'), [
            'name' => 'Leer', 'kind' => 'prompt', 'prompt' => '  ',
        ])->assertSessionHasErrors('skill');
        $this->assertDatabaseMissing('skills', ['slug' => 'leer']);
    }

    public function test_workflow_skill_runs_its_workflow(): void
    {
        $admin = $this->admin();
        $definition = WorkflowDefinition::create([
            'user_id' => $admin->id, 'name' => 'Skill-Flow', 'version' => 1, 'status' => 'active',
            'definition' => ['steps' => [['key' => 'a', 'type' => 'manual', 'routes' => ['success' => ['type' => 'end']]]]],
        ]);
        $skill = app(SkillService::class)->upsert([
            'user_id' => $admin->id, 'name' => 'Flow-Skill', 'kind' => 'workflow', 'workflow_definition_id' => $definition->id,
        ]);

        $this->actingAs($admin)->post(route('dashboard.skills.run', $skill))->assertRedirect();

        $this->assertSame(1, WorkflowRun::where('workflow_definition_id', $definition->id)->count());
        $this->assertSame(1, $skill->fresh()->use_count);
    }

    public function test_prompt_fragments_returns_active_prompt_skills(): void
    {
        $admin = $this->admin();
        $svc = app(SkillService::class);
        $svc->upsert(['user_id' => $admin->id, 'name' => 'A', 'kind' => 'prompt', 'prompt' => 'Regel A']);
        $inactive = $svc->upsert(['user_id' => $admin->id, 'name' => 'B', 'kind' => 'prompt', 'prompt' => 'Regel B']);
        $inactive->update(['active' => false]);

        $fragments = $svc->promptFragments($admin->id);
        $this->assertContains('Regel A', $fragments);
        $this->assertNotContains('Regel B', $fragments);
    }

    public function test_skill_memory_supports_a_b_a_reverts_as_distinct_write_events(): void
    {
        $admin = $this->admin();
        $service = app(SkillService::class);
        $base = [
            'user_id' => $admin->id,
            'name' => 'Revidierbarer Skill',
            'kind' => 'prompt',
        ];

        $service->upsert($base + ['prompt' => 'Regel A']);
        $service->upsert($base + ['prompt' => 'Regel B']);
        $service->upsert($base + ['prompt' => 'Regel A']);

        $links = MemoryLink::query()
            ->where('user_id', $admin->id)
            ->where('scope', 'skill')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $links);
        $this->assertSame(2, $links->where('status', 'superseded')->count());
        $this->assertStringEndsWith("\nRegel A", $links->where('status', 'active')->sole()->summary);
    }

    public function test_identical_skill_retry_does_not_create_a_phantom_memory_version(): void
    {
        $admin = $this->admin();
        $service = app(SkillService::class);
        $base = [
            'user_id' => $admin->id,
            'name' => 'Retry-fester Skill',
            'kind' => 'prompt',
        ];

        $service->upsert($base + ['prompt' => 'Regel A']);
        $service->upsert($base + ['prompt' => 'Regel B']);
        $service->upsert($base + ['prompt' => 'Regel B']);

        $links = MemoryLink::query()
            ->where('user_id', $admin->id)
            ->where('scope', 'skill')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $links);
        $this->assertSame('superseded', $links->first()->status);
        $this->assertSame('active', $links->last()->status);
        $this->assertStringEndsWith("\nRegel B", $links->last()->summary);
    }

    public function test_identical_skill_upsert_repairs_a_missing_memory_mirror(): void
    {
        $admin = $this->admin();
        Skill::create([
            'user_id' => $admin->id,
            'slug' => 'reparierbarer-skill',
            'name' => 'Reparierbarer Skill',
            'kind' => 'prompt',
            'prompt' => 'Regel A',
            'active' => true,
        ]);

        app(SkillService::class)->upsert([
            'user_id' => $admin->id,
            'name' => 'Reparierbarer Skill',
            'kind' => 'prompt',
            'prompt' => 'Regel A',
        ]);

        $mirror = MemoryLink::query()
            ->where('user_id', $admin->id)
            ->where('scope', 'skill')
            ->where('status', 'active')
            ->sole();
        $this->assertSame('skill:reparierbarer-skill', $mirror->logicalExternalId());
        $this->assertStringEndsWith("\nRegel A", $mirror->summary);
    }

    public function test_skills_api_lists_active_skills_for_the_user(): void
    {
        $admin = $this->admin();
        app(SkillService::class)->upsert(['user_id' => $admin->id, 'name' => 'Sichtbar', 'kind' => 'prompt', 'prompt' => 'x']);
        $minted = ApiKey::mint(['user_id' => $admin->id, 'name' => 'K', 'abilities' => ['brain.read'], 'active' => true]);

        $this->withHeader('X-Api-Key', $minted['plain'])->getJson('/api/v1/skills')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sichtbar');
    }

    public function test_toggle_and_delete_skill(): void
    {
        $admin = $this->admin();
        $skill = app(SkillService::class)->upsert(['user_id' => $admin->id, 'name' => 'T', 'kind' => 'prompt', 'prompt' => 'x']);

        $this->actingAs($admin)->post(route('dashboard.skills.toggle', $skill));
        $this->assertFalse($skill->fresh()->active);

        $this->actingAs($admin)->delete(route('dashboard.skills.destroy', $skill));
        $this->assertDatabaseMissing('skills', ['id' => $skill->id]);
    }
}
