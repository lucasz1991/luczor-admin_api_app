<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowOperation;
use App\Models\WorkflowRun;
use App\Models\WorkflowTestCase;
use App\Models\WorkflowTestEvidence;
use App\Models\WorkflowTrigger;
use App\Services\WorkflowService;
use App\Services\WorkflowBudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionWorkflowEditorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    }

    private function workflow(User $user): WorkflowDefinition
    {
        return WorkflowDefinition::create(['user_id' => $user->id, 'name' => 'Owned workflow', 'version' => 1, 'status' => 'active',
            'definition' => ['steps' => [['key' => 'a', 'type' => 'manual', 'payload' => []]]]]);
    }

    private function savePayload(WorkflowDefinition $workflow): array
    {
        return ['name' => 'Edited workflow', 'definition_json' => json_encode($workflow->definition),
            'expected_version' => $workflow->version, 'operation_id' => (string) Str::uuid()];
    }

    private function casePayload(): array
    {
        return ['operation_id' => (string) Str::uuid(), 'name' => 'Definition fixture',
            'specification' => ['input' => [], 'fixtures' => [], 'assertions' => [['step_key' => 'a', 'path' => 'data.ok', 'operator' => 'eq', 'value' => true]]]];
    }

    public function test_session_boundary_stop_is_replayable_and_preserves_running_step_until_completion(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $workflow = $this->workflow($admin);
        $workflow->update(['definition' => ['schema_version' => 2, 'steps' => [['key' => 'work', 'type' => 'data.collect', 'payload' => ['items' => [1]]]]]]);
        $service = app(WorkflowService::class);
        $run = $service->advance($service->createRun($workflow));
        $step = $run->steps()->sole();
        app(WorkflowBudgetService::class)->claim($step);
        $payload = ['operation_id' => (string) Str::uuid(), 'run_id' => $run->public_id, 'action' => 'stop_after_step'];
        $url = route('dashboard.workflows.editor.run-controls', $workflow);
        $this->actingAs($admin)->postJson($url, $payload)->assertOk()
            ->assertJsonPath('data.run.status', 'running')->assertJsonPath('data.run.budget_state.boundary_stop.status', 'pending')
            ->assertJsonMissingPath('data.run.context')->assertJsonMissingPath('data.run.input');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.run.budget_state.boundary_stop.status', 'pending');
        $this->assertSame(1, WorkflowOperation::where('operation_id', $payload['operation_id'])->count());
        $this->getJson(route('dashboard.workflows.editor.operations', [$workflow, $payload['operation_id']]))
            ->assertOk()->assertJsonPath('response.data.run.id', $run->id);
        $this->assertSame('running', $step->fresh()->status);
        $service->complete($step->fresh(), ['outcome' => 'success', 'data' => [1]]);
        $this->getJson(route('dashboard.workflows.editor.state', $workflow))->assertOk()
            ->assertJsonPath('runs.0.status', 'cancelled')->assertJsonPath('runs.0.budget_state.boundary_stop.status', 'completed');
    }

    public function test_session_run_control_rejects_other_workflow_and_cross_owner_operations(): void
    {
        $admin = $this->admin();
        $workflow = $this->workflow($admin);
        $other = $this->workflow($admin);
        $run = app(WorkflowService::class)->createRun($other);
        $payload = ['operation_id' => (string) Str::uuid(), 'run_id' => $run->public_id, 'action' => 'cancel'];
        $this->actingAs($admin)->postJson(route('dashboard.workflows.editor.run-controls', $workflow), $payload)->assertNotFound();
        $this->assertSame('queued', $run->fresh()->status);
        $this->postJson(route('dashboard.workflows.editor.run-controls', $other), $payload)->assertOk()->assertJsonPath('data.run.status', 'cancelled');
        $this->getJson(route('dashboard.workflows.editor.operations', [$workflow, $payload['operation_id']]))->assertOk()->assertJsonPath('status', 'not_found');
        $this->flushSession();
        $this->actingAs($this->admin())->postJson(route('dashboard.workflows.editor.run-controls', $other), $payload)->assertNotFound();
    }

    public function test_session_monitor_projects_owned_root_budget_without_private_data(): void
    {
        $admin = $this->admin();
        $workflow = $this->workflow($admin);
        $root = app(WorkflowService::class)->createRun($workflow);
        $root->update(['status' => 'running', 'budget_state' => ['executions' => 175, 'active_ms' => 2000], 'context' => ['private' => 'secret'], 'input' => ['private' => 'secret']]);
        $child = app(WorkflowService::class)->createRun($workflow);
        $child->update(['root_workflow_run_id' => $root->id]);
        $this->actingAs($admin)->getJson(route('dashboard.workflows.editor.state', $workflow))->assertOk()
            ->assertJsonPath('runs.0.root_budget.id', $root->id)->assertJsonPath('runs.0.root_budget.budget_state.executions', 175)
            ->assertJsonMissingPath('runs.0.root_budget.context')->assertJsonMissingPath('runs.0.root_budget.input')->assertJsonMissingPath('runs.1.user_id');
        $foreignRoot = app(WorkflowService::class)->createRun($this->workflow($this->admin()));
        $child->update(['root_workflow_run_id' => $foreignRoot->id]);
        $this->getJson(route('dashboard.workflows.editor.state', $workflow))->assertOk()->assertJsonMissingPath('runs.0.root_budget');
        $this->postJson(route('dashboard.workflows.editor.run-controls', $workflow), ['operation_id' => (string) Str::uuid(), 'run_id' => $child->public_id, 'action' => 'stop_after_step'])->assertNotFound();
    }

    public function test_state_and_editor_shell_are_owner_scoped_and_have_no_device_authority(): void
    {
        $admin = $this->admin();
        $workflow = $this->workflow($admin);
        WorkflowTrigger::create(['user_id' => $admin->id, 'workflow_definition_id' => $workflow->id, 'public_id' => (string) Str::uuid(),
            'name' => 'Private trigger', 'kind' => 'webhook', 'config' => [], 'secret_hash' => str_repeat('a', 64)]);
        $this->actingAs($admin)->getJson(route('dashboard.workflows.editor.state', $workflow))
            ->assertOk()->assertJsonPath('workflow.id', $workflow->id)
            ->assertJsonStructure(['workflow', 'catalog', 'tests', 'testCases', 'repairs', 'runs', 'triggers', 'urls'])
            ->assertJsonPath('capabilities.deviceBound', false)->assertJsonPath('capabilities.canRunRealTests', false)
            ->assertJsonMissingPath('triggers.0.secret_hash');
        $this->get(route('admin.page', ['page' => 'workflows', 'wf' => $workflow->id]))
            ->assertOk()->assertSee('data-luczor-workflow-editor', false)->assertSee('data-state-url', false);
        $other = $this->workflow($this->admin());
        $this->getJson(route('dashboard.workflows.editor.state', $other))->assertNotFound();
        $this->get(route('admin.page', ['page' => 'workflows', 'wf' => $other->id]))->assertNotFound();
    }

    public function test_guest_and_non_admin_cannot_use_session_editor(): void
    {
        $workflow = $this->workflow($this->admin());
        $this->getJson(route('dashboard.workflows.editor.state', $workflow))->assertUnauthorized();
        $member = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($member)->getJson(route('dashboard.workflows.editor.state', $workflow))->assertForbidden();
        $this->putJson(route('dashboard.workflows.update', $workflow), $this->savePayload($workflow))->assertForbidden();
    }

    public function test_run_version_is_derived_from_frozen_snapshot_without_selecting_a_nonexistent_column(): void
    {
        $admin = $this->admin();
        $workflow = $this->workflow($admin);
        $workflow->update(['version' => 7]);
        WorkflowRun::create(['public_id' => (string) Str::uuid(), 'user_id' => $admin->id,
            'workflow_definition_id' => $workflow->id, 'status' => 'completed', 'sandbox' => false,
            'definition_snapshot' => ['version' => 2, 'definition' => $workflow->definition, 'private_snapshot_data' => 'not-for-editor-state']]);
        WorkflowRun::create(['public_id' => (string) Str::uuid(), 'user_id' => $admin->id,
            'workflow_definition_id' => $workflow->id, 'status' => 'completed', 'sandbox' => false]);
        $runQueries = [];
        DB::listen(function ($query) use (&$runQueries) {
            if (str_contains($query->sql, 'workflow_runs')) {
                $runQueries[] = $query->sql;
            }
        });
        $this->actingAs($admin)->getJson(route('dashboard.workflows.editor.state', $workflow))
            ->assertOk()->assertJsonPath('workflow.version', 7)
            ->assertJsonPath('runs.0.definition_version', null)->assertJsonPath('runs.1.definition_version', 2)
            ->assertJsonPath('runs.1.workflow_definition_id', $workflow->id)
            ->assertJsonMissingPath('runs.0.definition_snapshot')->assertJsonMissingPath('runs.1.definition_snapshot');
        $this->assertNotEmpty($runQueries);
        foreach ($runQueries as $sql) {
            $this->assertStringNotContainsString('definition_version', $sql);
        }
    }

    public function test_save_is_owner_bound_replayable_and_preserves_real_conflict_status(): void
    {
        $admin = $this->admin();
        $workflow = $this->workflow($admin);
        $payload = $this->savePayload($workflow);
        $this->actingAs($admin)->putJson(route('dashboard.workflows.update', $workflow), $payload)
            ->assertOk()->assertJsonPath('workflow.version', 2)->assertJsonPath('workflow.definition.steps.0.key', 'a');
        $this->putJson(route('dashboard.workflows.update', $workflow), $payload)->assertOk()->assertJsonPath('workflow.version', 2);
        $this->assertSame(2, $workflow->fresh()->version);
        $this->putJson(route('dashboard.workflows.update', $workflow), array_merge($payload, ['operation_id' => (string) Str::uuid()]))
            ->assertConflict()->assertJsonPath('message', 'workflow_version_conflict');
        $other = $this->workflow($this->admin());
        $this->putJson(route('dashboard.workflows.update', $other), $this->savePayload($other))->assertNotFound();
        $this->assertSame(1, $other->fresh()->version);
    }

    public function test_missing_version_or_operation_is_not_silently_accepted(): void
    {
        $admin = $this->admin();
        $workflow = $this->workflow($admin);
        $this->actingAs($admin)->putJson(route('dashboard.workflows.update', $workflow), ['name' => 'Missing', 'definition_json' => json_encode($workflow->definition)])
            ->assertUnprocessable()->assertJsonValidationErrors(['expected_version', 'operation_id']);
        $workflow->update(['is_locked' => true]);
        $this->putJson(route('dashboard.workflows.update', $workflow), $this->savePayload($workflow))->assertConflict();
    }

    public function test_operation_lookup_never_exposes_other_workflow_or_user_responses(): void
    {
        $admin = $this->admin();
        $workflow = $this->workflow($admin);
        $other = $this->workflow($admin);
        $payload = $this->savePayload($workflow);
        $this->actingAs($admin)->putJson(route('dashboard.workflows.update', $workflow), $payload)->assertOk();
        $this->getJson(route('dashboard.workflows.editor.operations', [$workflow, $payload['operation_id']]))
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('response.workflow.id', $workflow->id);
        $this->getJson(route('dashboard.workflows.editor.operations', [$other, $payload['operation_id']]))
            ->assertOk()->assertJsonPath('status', 'not_found')->assertJsonMissingPath('response');
        $foreign = $this->admin();
        $id = (string) Str::uuid();
        WorkflowOperation::create(['user_id' => $foreign->id, 'operation_id' => $id, 'action' => 'update', 'request_hash' => str_repeat('a', 64),
            'response' => ['id' => $workflow->id, 'private' => 'must not leak']]);
        $this->getJson(route('dashboard.workflows.editor.operations', [$workflow, $id]))
            ->assertOk()->assertJsonPath('status', 'not_found')->assertJsonMissingPath('response');
    }

    public function test_fixture_and_definition_tests_replay_without_duplicate_evidence(): void
    {
        $admin = $this->admin();
        $workflow = $this->workflow($admin);
        $casePayload = $this->casePayload();
        $caseId = $this->actingAs($admin)->postJson(route('dashboard.workflows.editor.test-cases', $workflow), $casePayload)
            ->assertCreated()->json('data.id');
        $this->postJson(route('dashboard.workflows.editor.test-cases', $workflow), $casePayload)->assertCreated()->assertJsonPath('data.id', $caseId);
        $this->assertSame(1, WorkflowTestCase::count());
        $payload = ['operation_id' => (string) Str::uuid(), 'test_case_id' => $caseId, 'mode' => 'definition', 'expected_version' => 1];
        $this->postJson(route('dashboard.workflows.editor.tests', $workflow), $payload)->assertCreated()->assertJsonPath('data.status', 'passed');
        $this->postJson(route('dashboard.workflows.editor.tests', $workflow), $payload)->assertCreated()->assertJsonPath('data.status', 'passed');
        $this->assertSame(1, WorkflowTestEvidence::count());
        $this->getJson(route('dashboard.workflows.editor.operations', [$workflow, $payload['operation_id']]))
            ->assertOk()->assertJsonPath('response.data.workflow_definition_id', $workflow->id);
    }

    public function test_web_session_cannot_mint_real_test_or_local_device_approval(): void
    {
        $admin = $this->admin();
        $workflow = $this->workflow($admin);
        $case = $this->casePayload();
        $case['specification']['real_test_authorized'] = true;
        $this->actingAs($admin)->postJson(route('dashboard.workflows.editor.test-cases', $workflow), $case)->assertUnprocessable();
        $this->postJson(route('dashboard.workflows.editor.tests', $workflow), ['operation_id' => (string) Str::uuid(), 'mode' => 'real',
            'test_case_id' => 1, 'expected_version' => 1, 'device_id' => 'forged', 'local_approved' => true])->assertUnprocessable();
        $this->assertSame(0, WorkflowTestEvidence::count());
        $this->postJson('/dashboard/workflows/'.$workflow->id.'/repair-policy', ['local_approved' => true])->assertNotFound();
    }

    public function test_cross_workflow_fixture_and_repair_sources_are_rejected_before_execution(): void
    {
        $admin = $this->admin();
        $workflow = $this->workflow($admin);
        $other = $this->workflow($admin);
        $caseId = $this->actingAs($admin)->postJson(route('dashboard.workflows.editor.test-cases', $other), $this->casePayload())->json('data.id');
        $this->postJson(route('dashboard.workflows.editor.tests', $workflow), ['operation_id' => (string) Str::uuid(), 'mode' => 'definition',
            'test_case_id' => $caseId, 'expected_version' => 1])->assertNotFound();
        $this->postJson(route('dashboard.workflows.editor.repairs', $workflow), ['operation_id' => (string) Str::uuid(),
            'source_run_id' => 999999, 'expected_version' => 1, 'definition' => $workflow->definition])->assertNotFound();
    }

    public function test_session_mutation_requires_csrf_when_middleware_is_active(): void
    {
        $this->app->bind(VerifyCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends VerifyCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $admin = $this->admin();
        $workflow = $this->workflow($admin);
        $this->actingAs($admin)->withSession(['_token' => 'session-csrf-fixture'])
            ->putJson(route('dashboard.workflows.update', $workflow), $this->savePayload($workflow))->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', 'session-csrf-fixture')
            ->putJson(route('dashboard.workflows.update', $workflow), $this->savePayload($workflow))->assertOk();
    }
}
