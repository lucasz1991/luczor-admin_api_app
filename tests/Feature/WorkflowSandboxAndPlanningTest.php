<?php

namespace Tests\Feature;

use App\Models\ModelUseCase;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Services\WorkflowPlanner;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** SOLL §15 P27 — sandbox runs, planning engine and advisory-review config. */
class WorkflowSandboxAndPlanningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
    }

    public function test_sandbox_run_simulates_mutating_task_without_side_effects(): void
    {
        $admin = $this->admin();
        $definition = WorkflowDefinition::create([
            'user_id' => $admin->id, 'name' => 'Muta', 'version' => 1, 'status' => 'active',
            'definition' => ['steps' => [
                ['key' => 't', 'type' => 'task.create', 'payload' => ['title' => 'Nicht anlegen'], 'routes' => ['success' => ['type' => 'end']]],
            ]],
        ]);
        $svc = app(WorkflowService::class);
        $run = $svc->advance($svc->createRun($definition, [], null, true));

        $this->assertTrue($run->sandbox);
        $step = $run->steps()->first();
        $this->assertSame('completed', $step->status);
        $this->assertTrue($step->output['sandbox']);
        // The mutating task must NOT have created a real user task.
        $this->assertSame(0, Task::count());
    }

    public function test_sandbox_run_still_executes_read_only_tasks(): void
    {
        $admin = $this->admin();
        $definition = WorkflowDefinition::create([
            'user_id' => $admin->id, 'name' => 'RO', 'version' => 1, 'status' => 'active',
            'definition' => ['steps' => [
                ['key' => 'r', 'type' => 'memory.recall', 'payload' => ['query' => 'x'], 'routes' => ['success' => ['type' => 'end']]],
            ]],
        ]);
        $svc = app(WorkflowService::class);
        $run = $svc->advance($svc->createRun($definition, [], null, true));

        $step = $run->steps()->first();
        $this->assertSame('completed', $step->status);
        $this->assertArrayNotHasKey('sandbox', $step->output ?? []);   // really ran, not simulated
    }

    public function test_global_sandbox_setting_forces_every_run_into_sandbox(): void
    {
        Setting::putValue('sandbox_enabled', true, ['group' => 'workflow', 'type' => 'bool']);
        $admin = $this->admin();
        $definition = WorkflowDefinition::create([
            'user_id' => $admin->id, 'name' => 'Forced', 'version' => 1, 'status' => 'active',
            'definition' => ['steps' => [['key' => 'a', 'type' => 'manual', 'routes' => ['success' => ['type' => 'end']]]]],
        ]);
        $svc = app(WorkflowService::class);
        $run = $svc->createRun($definition);   // no explicit sandbox flag

        $this->assertTrue($run->sandbox);
    }

    public function test_start_endpoint_supports_a_sandbox_run(): void
    {
        $admin = $this->admin();
        $definition = WorkflowDefinition::create([
            'user_id' => $admin->id, 'name' => 'Startable', 'version' => 1, 'status' => 'active',
            'definition' => ['steps' => [['key' => 'a', 'type' => 'manual', 'routes' => ['success' => ['type' => 'end']]]]],
        ]);

        $this->actingAs($admin)
            ->postJson(route('dashboard.workflows.start', $definition), ['sandbox' => true])
            ->assertOk()
            ->assertJsonPath('run.sandbox', true);
    }

    public function test_planner_emits_a_valid_definition_and_persists_a_draft(): void
    {
        $admin = $this->admin();
        $planner = app(WorkflowPlanner::class);

        $definition = $planner->planDefinition('Wettbewerber-Preise recherchieren', true);
        // Must pass the same validation the editor/store path uses.
        $steps = app(WorkflowService::class)->assertDefinition($definition);
        $this->assertNotEmpty($steps);
        $this->assertContains('recherche', array_column($definition['steps'], 'key'));

        $this->actingAs($admin)
            ->post(route('dashboard.workflows.plan'), ['goal' => 'Angebot erstellen', 'include_research' => false])
            ->assertRedirect();
        $this->assertDatabaseHas('workflow_definitions', ['name' => 'Plan: Angebot erstellen']);
    }

    public function test_planner_rejects_an_empty_goal(): void
    {
        $this->expectException(HttpException::class);
        app(WorkflowPlanner::class)->planDefinition('   ');
    }

    public function test_advisory_review_policy_can_be_configured_per_use_case(): void
    {
        $admin = $this->admin();
        $chat = ModelUseCase::create(['name' => 'Chat', 'slug' => 'chat', 'active' => true]);
        $verifier = ModelUseCase::create(['name' => 'Verifier', 'slug' => 'verifier', 'active' => true]);

        $this->actingAs($admin)->post(route('dashboard.model-use-cases.review', $chat), [
            'review_enabled' => '1', 'review_use_case_id' => $verifier->id,
        ])->assertRedirect();

        $chat->refresh();
        $this->assertTrue($chat->review_enabled);
        $this->assertSame($verifier->id, $chat->review_use_case_id);
    }
}
