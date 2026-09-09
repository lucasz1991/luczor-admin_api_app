<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\WorkflowBudgetService;
use App\Services\WorkflowDefinitionValidator;
use App\Services\WorkflowDeviceCapabilities;
use App\Services\WorkflowService;
use App\Services\WorkflowTaskCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowVersionedExecutionTest extends TestCase
{
    use RefreshDatabase;

    private function definition(array $steps, array $extra = []): WorkflowDefinition
    {
        return WorkflowDefinition::create(['user_id' => User::factory()->create()->id, 'name' => 'V2', 'version' => 1, 'status' => 'active', 'definition' => array_merge(['schema_version' => 2, 'steps' => $steps], $extra)]);
    }

    private function startRun(WorkflowDefinition $definition): WorkflowRun
    {
        return app(WorkflowService::class)->advance(app(WorkflowService::class)->createRun($definition))->fresh();
    }

    public function test_every_task_has_a_versioned_capability_and_test_contract(): void
    {
        foreach (WorkflowTaskCatalog::options() as $task) {
            $this->assertSame(1, $task['version']);
            $this->assertSame('object', $task['input_schema']['type']);
            $this->assertSame(['definition', 'simulation', 'real'], $task['test']['modes']);
            $this->assertNotEmpty($task['adapters']);
            $this->assertSame($task['runner'] === 'client', $task['required_capabilities'] !== []);
        }
        $this->assertSame(['windows.user.node'], WorkflowTaskCatalog::task('node.run')['adapters']);
    }

    public function test_foreach_uses_frozen_child_runs_and_shared_root_execution_budget(): void
    {
        $definition = $this->definition([['key' => 'loop', 'type' => 'control.foreach', 'payload' => ['items' => [2, 4, 6], 'body' => ['steps' => [
            ['key' => 'item', 'type' => 'data.collect', 'payload' => ['items' => [['$ref' => 'input.item']]]],
        ]]]]]);
        $run = $this->startRun($definition);
        $this->assertSame('completed', $run->status);
        $this->assertSame(4, $run->budget_state['executions']);
        $this->assertSame(3, WorkflowRun::where('root_workflow_run_id', $run->id)->count());
        $this->assertSame([6], $run->steps()->first()->output['data'][2]['item']['data']);
        $this->assertSame(4, DB::table('workflow_execution_records')->where('root_workflow_run_id', $run->id)->count());
    }

    public function test_until_and_join_use_typed_data_without_expression_evaluation(): void
    {
        $definition = $this->definition([
            ['key' => 'until', 'type' => 'control.until', 'payload' => ['condition' => ['path' => 'count.data.0', 'operator' => 'gte', 'value' => 3], 'body' => ['steps' => [
                ['key' => 'count', 'type' => 'data.collect', 'payload' => ['items' => [['$ref' => 'input.iteration']]]],
            ]]]],
            ['key' => 'joined', 'type' => 'control.join', 'depends_on' => ['until']],
        ]);
        $run = $this->startRun($definition);
        $this->assertSame('completed', $run->status);
        $this->assertSame(3, $run->steps()->where('step_key', 'until')->first()->output['iterations']);
        $this->assertSame(5, $run->budget_state['executions']);
    }

    public function test_execution_budget_stops_before_an_extra_loop_body(): void
    {
        $definition = $this->definition([['key' => 'loop', 'type' => 'control.foreach', 'payload' => ['items' => [1, 2, 3], 'body' => ['steps' => [['key' => 'item', 'type' => 'data.collect', 'payload' => ['items' => [['$ref' => 'input.item']]]]]]]]], ['budgets' => ['max_executions' => 2]]);
        $run = $this->startRun($definition);
        $this->assertSame('cancelled', $run->status);
        $this->assertSame(2, $run->budget_state['executions']);
        $this->assertSame('workflow_budget_exhausted', $run->budget_state['stop_reason']);
    }

    public function test_parallel_claims_and_shared_browser_sessions_are_bounded(): void
    {
        Queue::fake();
        $definition = $this->definition([
            ['key' => 'a', 'type' => 'browser.read', 'payload' => ['browser_session_id' => 'one']],
            ['key' => 'b', 'type' => 'browser.read', 'payload' => ['browser_session_id' => 'one']],
            ['key' => 'c', 'type' => 'browser.read', 'payload' => ['browser_session_id' => 'two']],
            ['key' => 'd', 'type' => 'data.collect', 'payload' => ['items' => []]],
        ]);
        $run = $this->startRun($definition);
        $steps = $run->steps()->orderBy('position')->get();
        $budget = app(WorkflowBudgetService::class);
        $this->assertTrue($budget->claim($steps[0]));
        $this->assertFalse($budget->claim($steps[1]));
        $this->assertTrue($budget->claim($steps[2]));
        $this->assertFalse($budget->claim($steps[3]));
        $this->assertFalse($budget->claim($steps[0]));
        $this->assertSame(2, $run->fresh()->budget_state['executions']);
    }

    public function test_catalog_presence_is_not_device_capability_and_waiting_consumes_no_execution(): void
    {
        $definition = $this->definition([['key' => 'read', 'type' => 'browser.read']]);
        $device = Device::create(['user_id' => $definition->user_id, 'device_id' => 'device-v2', 'name' => 'Test', 'status' => 'online']);
        $run = app(WorkflowService::class)->createRun($definition, [], null, false, ['device_id' => $device->device_id]);
        app(WorkflowService::class)->advance($run);
        $this->assertSame('waiting_for_capability', $run->steps()->first()->status);
        $this->assertSame(0, $run->fresh()->budget_state['executions']);
        $capabilities = app(WorkflowDeviceCapabilities::class);
        $capabilities->report($device, ['schema_version' => 1, 'environment_hash' => str_repeat('a', 64), 'tasks' => [['type' => 'browser.read', 'version' => 1, 'adapter' => 'browser.session', 'available' => true]]]);
        $this->assertTrue($capabilities->admission($device->fresh(), 'browser.read', 1)['ready']);
        $this->travel(31)->minutes();
        $this->assertFalse($capabilities->admission($device->fresh(), 'browser.read', 1)['ready']);
    }

    public function test_wait_time_is_excluded_from_active_budget(): void
    {
        $definition = $this->definition([['key' => 'wait', 'type' => 'wait.seconds', 'payload' => ['seconds' => 120]]], ['budgets' => ['active_seconds' => 1]]);
        $run = $this->startRun($definition);
        $this->travel(121)->seconds();
        app(WorkflowService::class)->settleWaitSteps($run);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertLessThan(1000, $run->fresh()->budget_state['active_ms']);
    }

    public function test_invalid_version_and_missing_typed_inputs_are_rejected(): void
    {
        $validator = app(WorkflowDefinitionValidator::class);
        foreach ([['key' => 'x', 'type' => 'data.collect', 'version' => 2, 'payload' => ['items' => []]], ['key' => 'x', 'type' => 'data.collect', 'payload' => ['items' => 'wrong']]] as $step) {
            try {
                $validator->validate(['schema_version' => 2, 'steps' => [$step]]);
                $this->fail('Expected invalid contract rejection.');
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode());
            }
        }
    }
}
