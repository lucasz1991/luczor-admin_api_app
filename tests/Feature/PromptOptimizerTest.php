<?php

namespace Tests\Feature;

use App\Models\PromptTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptOptimizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_archives_old_active_version(): void
    {
        PromptTemplate::publish('luczor.role.coder', 'v1', ['role' => 'coder', 'priority' => 10]);
        $v2 = PromptTemplate::publish('luczor.role.coder', 'v2', ['role' => 'coder', 'priority' => 10]);

        $active = PromptTemplate::where('key', 'luczor.role.coder')->where('status', 'active')->get();
        $this->assertCount(1, $active);
        $this->assertSame(2, $active->first()->version);
        $this->assertSame('v2', $v2->body);
        $this->assertSame('archived', PromptTemplate::where('key', 'luczor.role.coder')->where('version', 1)->first()->status);
    }

    public function test_active_role_prompts_are_priority_ordered_and_exclude_global(): void
    {
        PromptTemplate::publish('r.a', 'A', ['role' => 'coder', 'priority' => 50]);
        PromptTemplate::publish('r.b', 'B', ['role' => 'coder', 'priority' => 10]);
        PromptTemplate::publish('r.c', 'C', ['role' => 'all', 'priority' => 30]);
        PromptTemplate::publish('luczor.system', 'GLOBAL', ['role' => null]);

        $this->assertSame(['B', 'C', 'A'], PromptTemplate::activeRolePrompts('coder')->pluck('body')->all());
        // Only the 'all'-role rule applies to an unrelated role; the global (role=null) never does.
        $this->assertSame(['C'], PromptTemplate::activeRolePrompts('planner')->pluck('body')->all());
    }

    public function test_admin_publishes_a_prompt_with_role_and_priority(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('dashboard.prompt-templates.store'), ['key' => 'luczor.role.planner', 'role' => 'planner', 'priority' => 20, 'body' => 'Plane sorgfältig.'])
            ->assertRedirect();
        $this->assertDatabaseHas('prompt_templates', ['key' => 'luczor.role.planner', 'role' => 'planner', 'status' => 'active', 'version' => 1]);

        $this->actingAs($admin)
            ->post(route('dashboard.prompt-templates.store'), ['key' => 'luczor.role.planner', 'role' => 'planner', 'priority' => 20, 'body' => 'v2'])
            ->assertRedirect();
        $this->assertSame(2, PromptTemplate::where('key', 'luczor.role.planner')->where('status', 'active')->first()->version);
    }
}
