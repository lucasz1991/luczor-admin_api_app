<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** SOLL §14 P16 — Board-Editor, Run-Preview und die zugehörigen Admin-Endpoints. */
class WorkflowEditorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
    }

    private function definition(array $overrides = []): array
    {
        return array_merge([
            'lists' => [['key' => 'liste-1', 'name' => 'Ablauf']],
            'steps' => [
                ['key' => 'a', 'type' => 'manual', 'payload' => ['title' => 'Start', 'list' => 'liste-1']],
                ['key' => 'b', 'type' => 'manual', 'depends_on' => ['a'], 'payload' => ['list' => 'liste-1'], 'routes' => ['success' => ['type' => 'end']]],
            ],
        ], $overrides);
    }

    private function workflow(User $owner, ?array $definition = null, array $attributes = []): WorkflowDefinition
    {
        return WorkflowDefinition::create(array_merge([
            'user_id' => $owner->id, 'name' => 'Demo', 'version' => 1, 'status' => 'active',
            'definition' => $definition ?? $this->definition(),
        ], $attributes));
    }

    public function test_board_editor_page_renders_with_catalog_and_definition(): void
    {
        $admin = $this->admin();
        $wf = $this->workflow($admin);

        $this->actingAs($admin)
            ->get(route('admin.page', ['page' => 'workflows', 'wf' => $wf->id]))
            ->assertOk()
            ->assertSee('Task-Bibliothek')
            ->assertSee('Neue Liste anlegen')
            ->assertSee('Experten-Modus: JSON-Definition');
    }

    public function test_update_workflow_replaces_definition_and_bumps_version(): void
    {
        $admin = $this->admin();
        $wf = $this->workflow($admin);
        $next = $this->definition();
        $next['steps'][] = ['key' => 'c', 'type' => 'llm', 'depends_on' => ['b'], 'payload' => ['list' => 'liste-1']];

        $this->actingAs($admin)
            ->putJson(route('dashboard.workflows.update', $wf), ['name' => 'Demo v2', 'definition_json' => json_encode($next)])
            ->assertOk()
            ->assertJsonPath('workflow.version', 2);

        $wf->refresh();
        $this->assertSame('Demo v2', $wf->name);
        $this->assertCount(3, $wf->definition['steps']);
    }

    public function test_update_rejects_invalid_definition_with_422(): void
    {
        $admin = $this->admin();
        $wf = $this->workflow($admin);
        $bad = ['steps' => [['key' => 'x', 'type' => 'shell.exec']]]; // nicht im Katalog

        $this->actingAs($admin)
            ->putJson(route('dashboard.workflows.update', $wf), ['name' => 'Demo', 'definition_json' => json_encode($bad)])
            ->assertStatus(422);

        $this->assertSame(1, $wf->refresh()->version);
    }

    public function test_locked_workflow_cannot_be_updated(): void
    {
        $admin = $this->admin();
        $wf = $this->workflow($admin, null, ['is_locked' => true]);

        $this->actingAs($admin)
            ->putJson(route('dashboard.workflows.update', $wf), ['name' => 'Neu', 'definition_json' => json_encode($this->definition())])
            ->assertStatus(422);
    }

    public function test_duplicate_creates_an_editable_copy(): void
    {
        $admin = $this->admin();
        $wf = $this->workflow($admin, null, ['is_locked' => true]);

        $this->actingAs($admin)->post(route('dashboard.workflows.duplicate', $wf))->assertRedirect();

        $copy = WorkflowDefinition::where('name', 'Demo (Kopie)')->first();
        $this->assertNotNull($copy);
        $this->assertFalse((bool) $copy->is_locked);
        $this->assertSame($wf->definition, $copy->definition);
    }

    public function test_toggle_and_lock_switch_status_and_edit_lock(): void
    {
        $admin = $this->admin();
        $wf = $this->workflow($admin);

        $this->actingAs($admin)->post(route('dashboard.workflows.toggle', $wf));
        $this->assertSame('disabled', $wf->refresh()->status);

        $this->actingAs($admin)->post(route('dashboard.workflows.lock', $wf));
        $this->assertTrue($wf->refresh()->is_edit_locked);
    }

    public function test_disabled_workflow_cannot_be_started(): void
    {
        $admin = $this->admin();
        $wf = $this->workflow($admin, null, ['status' => 'disabled']);

        $this->actingAs($admin)
            ->postJson(route('dashboard.workflows.start', $wf))
            ->assertStatus(422);

        $this->assertSame(0, WorkflowRun::count());
    }

    public function test_run_status_endpoint_returns_steps_and_preview_page_renders(): void
    {
        $admin = $this->admin();
        $wf = $this->workflow($admin);
        $svc = app(WorkflowService::class);
        $run = $svc->advance($svc->createRun($wf));

        $this->actingAs($admin)
            ->getJson(route('dashboard.workflow-runs.status', $run))
            ->assertOk()
            ->assertJsonPath('run.id', $run->id)
            ->assertJsonPath('steps.0.key', 'a')
            ->assertJsonCount(2, 'steps');

        $this->actingAs($admin)
            ->get(route('admin.page', ['page' => 'workflows', 'run' => $run->id]))
            ->assertOk()
            ->assertSee('Workflow-Vorschau');
    }

    public function test_awaiting_approval_step_can_be_approved_and_run_cancelled(): void
    {
        $admin = $this->admin();
        $definition = $this->definition();
        $definition['steps'][0]['type'] = 'approval';
        unset($definition['steps'][1]['routes']);
        $wf = $this->workflow($admin, $definition);
        $svc = app(WorkflowService::class);
        $run = $svc->advance($svc->createRun($wf));

        $step = $run->steps()->where('step_key', 'a')->first();
        $this->assertSame('awaiting_approval', $step->status);

        $this->actingAs($admin)
            ->postJson(route('dashboard.workflow-steps.approve', $step))
            ->assertOk();
        $this->assertSame('completed', $step->refresh()->status);

        $this->actingAs($admin)
            ->postJson(route('dashboard.workflow-runs.cancel', $run))
            ->assertOk();
        $this->assertSame('cancelled', $run->refresh()->status);
    }

    public function test_non_admin_gets_403_on_editor_endpoints(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $wf = $this->workflow($this->admin());

        $this->actingAs($user)
            ->putJson(route('dashboard.workflows.update', $wf), ['name' => 'x', 'definition_json' => '{}'])
            ->assertForbidden();
        $this->actingAs($user)->post(route('dashboard.workflows.duplicate', $wf))->assertForbidden();
    }
}
