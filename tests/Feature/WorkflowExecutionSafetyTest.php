<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\DeviceJobService;
use App\Services\WorkflowBindings;
use App\Services\WorkflowBudgetService;
use App\Services\WorkflowDeviceCapabilities;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowExecutionSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function definition(array $steps, array $budgets = [], ?int $userId = null): WorkflowDefinition
    {
        return WorkflowDefinition::create(['user_id' => $userId ?? User::factory()->create()->id, 'name' => 'Safety', 'version' => 1, 'status' => 'active', 'definition' => ['schema_version' => 2, 'budgets' => $budgets, 'steps' => $steps]]);
    }

    public function test_generic_job_api_cannot_sign_caller_supplied_workflow_grants(): void
    {
        try {
            app(DeviceJobService::class)->create(Request::create('/api/v1/device-jobs', 'POST'), [
                'device_id' => 'any', 'tool_profile' => 'workflow.task', 'payload' => ['workflow' => ['automatic' => true, 'grant' => ['status' => 'testing']]],
            ]);
            $this->fail('Generic API issued a workflow bundle.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
            $this->assertDatabaseCount('device_jobs', 0);
        }
    }

    public function test_nonterminal_device_failure_cannot_be_reported_as_successful_data(): void
    {
        Queue::fake();
        $definition = $this->definition([['key' => 'read', 'type' => 'browser.read', 'max_attempts' => 1]]);
        $service = app(WorkflowService::class);
        $run = $service->createRun($definition);
        $service->advance($run);
        $step = $run->steps()->sole();
        app(WorkflowBudgetService::class)->claim($step);
        $service->complete($step->fresh(), ['ok' => false, 'text' => 'Unavailable']);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('failed', $step->fresh()->status);
    }

    public function test_v2_parent_counts_legacy_child_execution_and_stops_at_root_budget(): void
    {
        $user = User::factory()->create();
        $child = WorkflowDefinition::create(['user_id' => $user->id, 'name' => 'Legacy', 'version' => 1, 'status' => 'active', 'definition' => ['steps' => [['key' => 'legacy', 'type' => 'condition', 'payload' => ['left' => 1, 'right' => 1]]]]]);
        $parent = $this->definition([['key' => 'nested', 'type' => 'workflow', 'payload' => ['workflow_definition_id' => $child->id]]], ['max_executions' => 1], $user->id);
        $service = app(WorkflowService::class);
        $run = $service->createRun($parent);
        $service->advance($run);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->budget_state['executions']);
        $this->assertSame(1, DB::table('workflow_execution_records')->count());
    }

    public function test_revisited_control_executes_new_child_and_keeps_old_child_immutable(): void
    {
        $definition = $this->definition([['key' => 'loop', 'type' => 'control.foreach', 'payload' => ['items' => [1], 'body' => ['steps' => [['key' => 'item', 'type' => 'data.collect', 'payload' => ['items' => [['$ref' => 'input.item']]]]]]],
            'routes' => ['success' => ['type' => 'step', 'step_key' => 'loop', 'max_iterations' => 1]]]]);
        $service = app(WorkflowService::class);
        $run = $service->createRun($definition);
        $service->advance($run);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(4, $run->fresh()->budget_state['executions']);
        $children = WorkflowRun::where('parent_workflow_run_id', $run->id)->get();
        $this->assertCount(2, $children);
        $this->assertSame(['completed', 'completed'], $children->pluck('status')->all());
        $this->assertCount(2, $children->pluck('parent_execution_id')->unique());
    }

    public function test_v2_parent_requires_a_device_capability_even_for_a_legacy_child(): void
    {
        $user = User::factory()->create();
        Device::create(['user_id' => $user->id, 'device_id' => 'legacy-device', 'name' => 'No adapter evidence', 'status' => 'online']);
        $child = WorkflowDefinition::create(['user_id' => $user->id, 'name' => 'Legacy device', 'version' => 1, 'status' => 'active', 'definition' => ['steps' => [['key' => 'browser', 'type' => 'browser.read']]]]);
        $parent = $this->definition([['key' => 'nested', 'type' => 'workflow', 'payload' => ['workflow_definition_id' => $child->id]]], [], $user->id);
        $run = app(WorkflowService::class)->advance(app(WorkflowService::class)->createRun($parent, [], null, false, ['device_id' => 'legacy-device']));
        $childRun = WorkflowRun::where('parent_workflow_run_id', $run->id)->sole();
        $this->assertSame('waiting_for_capability', $childRun->steps()->sole()->status);
        $this->assertDatabaseCount('device_jobs', 0);
        $this->assertSame(1, $run->fresh()->budget_state['executions']);
    }

    public function test_route_loop_cannot_exceed_the_root_loop_policy(): void
    {
        $definition = $this->definition([['key' => 'again', 'type' => 'data.collect', 'payload' => ['items' => []], 'routes' => ['success' => ['type' => 'step', 'step_key' => 'again', 'max_iterations' => 50]]]], ['max_loop_iterations' => 1]);
        $service = app(WorkflowService::class);
        $run = $service->createRun($definition);
        $service->advance($run);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(2, $run->fresh()->budget_state['executions']);
    }

    public function test_binding_and_literal_for_one_browser_session_share_the_same_slot(): void
    {
        Queue::fake();
        $definition = $this->definition([
            ['key' => 'literal', 'type' => 'browser.read', 'payload' => ['browser_session_id' => 'same']],
            ['key' => 'bound', 'type' => 'browser.read', 'payload' => ['browser_session_id' => ['$ref' => 'input.session']]],
        ]);
        $service = app(WorkflowService::class);
        $run = $service->createRun($definition, ['session' => 'same']);
        $service->advance($run);
        $steps = $run->steps()->orderBy('position')->get();
        foreach ($steps as $step) {
            app(WorkflowBindings::class)->resolve($step);
        }
        $this->assertTrue(app(WorkflowBudgetService::class)->claim($steps[0]));
        $this->assertFalse(app(WorkflowBudgetService::class)->claim($steps[1]));
    }

    public function test_cancel_waits_for_running_server_work_and_preserves_its_committed_output(): void
    {
        Queue::fake();
        $definition = $this->definition([['key' => 'work', 'type' => 'data.collect', 'payload' => ['items' => []]]]);
        $service = app(WorkflowService::class);
        $run = $service->createRun($definition);
        $service->advance($run);
        $step = $run->steps()->sole();
        $this->assertTrue(app(WorkflowBudgetService::class)->claim($step));
        $this->assertSame('cancelling', $service->cancel($run)->status);
        $this->assertSame('running', $step->fresh()->status);
        $service->complete($step->fresh(), ['outcome' => 'success', 'data' => [42]]);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame([42], $step->fresh()->output['data']);
        $this->assertSame('cancelled', DB::table('workflow_execution_records')->sole()->status);
    }

    public function test_capability_wait_targets_payload_device_and_cancels_cleanly(): void
    {
        $definition = $this->definition([['key' => 'browser', 'type' => 'browser.read', 'payload' => ['device_id' => 'target']]]);
        Device::create(['user_id' => $definition->user_id, 'device_id' => 'target', 'name' => 'Unavailable adapter', 'status' => 'online']);
        $other = Device::create(['user_id' => $definition->user_id, 'device_id' => 'other', 'name' => 'Available adapter', 'status' => 'online', 'last_seen_at' => now()]);
        app(WorkflowDeviceCapabilities::class)->report($other, ['schema_version' => 1, 'environment_hash' => str_repeat('a', 64), 'tasks' => [['type' => 'browser.read', 'version' => 1, 'adapter' => 'browser.session', 'available' => true]]]);
        $service = app(WorkflowService::class);
        $run = $service->createRun($definition);
        $service->advance($run);
        $this->assertSame('waiting_for_capability', $run->steps()->sole()->status);
        $service->cancel($run);
        $this->assertSame('cancelled', $run->steps()->sole()->status);
        $this->assertDatabaseCount('device_jobs', 0);
    }

    public function test_timeout_retains_device_slot_until_ack_and_does_not_dispatch_route(): void
    {
        Queue::fake();
        $definition = $this->definition([
            ['key' => 'browser', 'type' => 'browser.read', 'payload' => ['timeout_seconds' => 1]],
            ['key' => 'next', 'type' => 'data.collect', 'depends_on' => ['browser'], 'payload' => ['items' => []]],
        ]);
        $service = app(WorkflowService::class);
        $run = $service->createRun($definition);
        $service->advance($run);
        $step = $run->steps()->where('step_key', 'browser')->sole();
        app(WorkflowBudgetService::class)->claim($step);
        $device = Device::create(['user_id' => $definition->user_id, 'device_id' => 'timeout', 'name' => 'Fixture', 'status' => 'online']);
        $job = DeviceJob::create(['public_id' => (string) Str::uuid(), 'user_id' => $definition->user_id, 'device_id' => $device->id,
            'workflow_execution_id' => $step->execution_id, 'tool_profile' => 'workflow.task', 'status' => 'running', 'risk_level' => 'normal', 'payload' => [],
            'payload_hash' => str_repeat('a', 64), 'started_at' => now(), 'expires_at' => now()->addMinutes(5)]);
        $step->update(['external_run_type' => 'device_job', 'external_run_id' => $job->public_id, 'started_at' => now()]);
        $this->travel(2)->seconds();
        $service->expireTimedOutSteps($run);
        $this->assertSame('cancelling', $run->fresh()->status);
        $this->assertNotNull($job->fresh()->cancel_requested_at);
        $this->assertSame('running', DB::table('workflow_execution_records')->sole()->status);
        $this->assertSame('cancelled', $run->steps()->where('step_key', 'next')->sole()->status);
        $job->update(['status' => 'cancelled', 'finished_at' => now()]);
        $service->settleCancellation($run->fresh());
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame('cancelled', DB::table('workflow_execution_records')->sole()->status);
        $this->assertSame(1, $run->fresh()->budget_state['executions']);
    }
}
