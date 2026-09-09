<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\DeviceJobService;
use App\Services\WorkflowBoundaryStop;
use App\Services\WorkflowBudgetService;
use App\Services\WorkflowService;
use App\Services\WorkflowStructuredControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowBoundaryStopTest extends TestCase
{
    use RefreshDatabase;

    private function fixtureRun(array $steps): WorkflowRun
    {
        Queue::fake();
        $definition = WorkflowDefinition::create(['user_id' => User::factory()->create()->id, 'name' => 'Boundary fixture', 'version' => 1, 'status' => 'active', 'definition' => ['schema_version' => 2, 'steps' => $steps]]);

        return app(WorkflowService::class)->advance(app(WorkflowService::class)->createRun($definition));
    }

    public function test_running_server_step_finishes_and_its_next_step_never_starts(): void
    {
        $run = $this->fixtureRun([
            ['key' => 'first', 'type' => 'data.collect', 'payload' => ['items' => [1]], 'routes' => ['success' => ['type' => 'step', 'step_key' => 'next']]],
            ['key' => 'next', 'type' => 'data.collect', 'depends_on' => ['first'], 'payload' => ['items' => [2]]],
        ]);
        $step = $run->steps()->where('step_key', 'first')->sole();
        app(WorkflowBudgetService::class)->claim($step);
        $pending = app(WorkflowBoundaryStop::class)->request($run);
        $this->assertSame('running', $pending->status);
        $this->assertSame('pending', $pending->budget_state['boundary_stop']['status']);
        $this->assertSame('running', $step->fresh()->status);
        $this->assertFalse(app(WorkflowBudgetService::class)->claim($run->steps()->where('step_key', 'next')->sole()));
        app(WorkflowService::class)->complete($step->fresh(), ['outcome' => 'success', 'data' => [1]]);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame('completed', $run->fresh()->budget_state['boundary_stop']['status']);
        $this->assertSame('completed', $step->fresh()->status);
        $this->assertSame([1], $step->fresh()->output['data']);
        $this->assertSame(1, $run->fresh()->budget_state['executions']);
        $this->assertSame('cancelled', $run->steps()->where('step_key', 'next')->sole()->status);
    }

    private function job(WorkflowStep $step, Device $device, string $status): DeviceJob
    {
        $job = DeviceJob::create(['public_id' => (string) Str::uuid(), 'user_id' => $step->user_id, 'device_id' => $device->id,
            'workflow_execution_id' => $step->execution_id, 'tool_profile' => 'workflow.task', 'status' => $status, 'risk_level' => 'normal',
            'payload' => [], 'payload_hash' => str_repeat('b', 64), 'started_at' => $status === 'running' ? now() : null, 'expires_at' => now()->addMinutes(5)]);
        $step->update(['external_run_type' => 'device_job', 'external_run_id' => $job->public_id]);

        return $job;
    }

    public function test_queued_device_effect_is_cancelled_while_running_effect_gets_no_abort_request(): void
    {
        $run = $this->fixtureRun([['key' => 'running', 'type' => 'browser.read', 'payload' => ['browser_session_id' => 'one']], ['key' => 'queued', 'type' => 'browser.read', 'payload' => ['browser_session_id' => 'two']]]);
        $device = Device::create(['user_id' => $run->user_id, 'device_id' => 'boundary', 'name' => 'Fixture', 'status' => 'online']);
        $steps = $run->steps()->orderBy('position')->get();
        foreach ($steps as $step) {
            $this->assertTrue(app(WorkflowBudgetService::class)->claim($step));
        }
        $active = $this->job($steps[0], $device, 'running');
        $queued = $this->job($steps[1], $device, 'queued');
        $stop = app(WorkflowBoundaryStop::class);
        $this->assertSame('pending', $stop->request($run)->budget_state['boundary_stop']['status']);
        $this->assertNull($active->fresh()->cancel_requested_at);
        $this->assertSame('running', $active->fresh()->status);
        $this->assertSame('cancelled', $queued->fresh()->status);
        $this->assertSame('cancelled', $steps[1]->fresh()->status);
        $active->update(['status' => 'completed', 'finished_at' => now(), 'result' => ['text' => 'Measured output']]);
        $this->assertSame('pending', $stop->settle($run)->budget_state['boundary_stop']['status']);
        app(WorkflowService::class)->syncDeviceJobSteps($run);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame('Measured output', $steps[0]->fresh()->output['text']);
        $this->assertNull($active->fresh()->cancel_requested_at);
    }

    public function test_stop_of_a_child_targets_root_and_never_launches_the_next_control_iteration(): void
    {
        $run = $this->fixtureRun([['key' => 'loop', 'type' => 'control.foreach', 'payload' => ['items' => [1, 2], 'body' => ['steps' => [['key' => 'work', 'type' => 'data.collect', 'payload' => ['items' => [1]]]]]]]]);
        $control = $run->steps()->sole();
        app(WorkflowBudgetService::class)->claim($control);
        app(WorkflowStructuredControl::class)->sync($control->fresh());
        $child = WorkflowRun::where('parent_workflow_run_id', $run->id)->sole();
        $step = $child->steps()->sole();
        app(WorkflowBudgetService::class)->claim($step);
        $pending = app(WorkflowBoundaryStop::class)->request($child);
        $this->assertSame($run->id, $pending->id);
        app(WorkflowStructuredControl::class)->sync($control->fresh());
        $this->assertSame(1, WorkflowRun::where('parent_workflow_run_id', $run->id)->count());
        app(WorkflowService::class)->complete($step->fresh(), ['outcome' => 'success', 'data' => [1]]);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame('completed', $step->fresh()->status);
        $this->assertSame(1, WorkflowRun::where('parent_workflow_run_id', $run->id)->count());
        $this->assertSame([1], $control->fresh()->control_state['results'][0]['work']['data']);
        $this->assertSame('partial', $control->fresh()->output['outcome']);
    }

    public function test_claimed_but_undispatched_client_cannot_create_a_job_after_stop(): void
    {
        $run = $this->fixtureRun([['key' => 'browser', 'type' => 'browser.read']]);
        $step = $run->steps()->sole();
        app(WorkflowBudgetService::class)->claim($step);
        $device = Device::create(['user_id' => $run->user_id, 'device_id' => 'boundary', 'name' => 'Fixture', 'status' => 'online']);
        $this->assertSame('cancelled', app(WorkflowBoundaryStop::class)->request($run)->status);
        $this->expectExceptionMessage('workflow_boundary_stop_pending');
        app(DeviceJobService::class)->createForWorkflow($step, $device, []);
    }

    public function test_signed_child_job_uses_root_resource_namespace_and_its_own_execution_identity(): void
    {
        $private = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($private, $key);
        config(['luczor.device_jobs.private_key' => $key]);
        $run = $this->fixtureRun([['key' => 'loop', 'type' => 'control.foreach', 'payload' => ['items' => [1], 'body' => ['steps' => [['key' => 'browser', 'type' => 'browser.read']]]]]]);
        $control = $run->steps()->sole();
        app(WorkflowBudgetService::class)->claim($control);
        app(WorkflowStructuredControl::class)->sync($control->fresh());
        $child = WorkflowRun::where('parent_workflow_run_id', $run->id)->sole();
        $step = $child->steps()->sole();
        app(WorkflowBudgetService::class)->claim($step);
        $device = Device::create(['user_id' => $run->user_id, 'device_id' => 'child-browser', 'name' => 'Fixture', 'status' => 'online']);
        $job = app(DeviceJobService::class)->createForWorkflow($step->fresh(), $device, []);
        $this->assertSame($run->public_id, $job->payload['workflow']['resource_run']);
        $this->assertSame($child->public_id, $job->payload['workflow']['run']);
        $this->assertSame($step->execution_id, $job->payload['workflow']['execution_id']);
        $this->assertNotSame($job->payload['workflow']['run'], $job->payload['workflow']['resource_run']);
    }

    public function test_hard_server_timeout_during_boundary_wait_still_requires_the_worker_to_end(): void
    {
        $run = $this->fixtureRun([['key' => 'work', 'type' => 'data.collect', 'payload' => ['items' => [], 'timeout_seconds' => 1]]]);
        $step = $run->steps()->sole();
        app(WorkflowBudgetService::class)->claim($step);
        app(WorkflowBoundaryStop::class)->request($run);
        $this->travel(2)->seconds();
        app(WorkflowService::class)->expireTimedOutSteps($run);
        $this->assertSame('cancelling', $run->fresh()->status);
        $this->assertSame('running', $step->fresh()->status);
        $this->assertSame('pending', $run->fresh()->budget_state['boundary_stop']['status']);
        app(WorkflowService::class)->complete($step->fresh(), ['outcome' => 'success', 'data' => [9]]);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame([9], $step->fresh()->output['data']);
        $this->assertSame('completed', $run->fresh()->budget_state['boundary_stop']['status']);
        $this->assertSame('step_timeout', $run->fresh()->budget_state['boundary_stop']['completion_reason']);
    }

    public function test_stop_api_is_idempotent_owner_scoped_and_show_has_root_budget_without_root_snapshot(): void
    {
        $run = $this->fixtureRun([['key' => 'work', 'type' => 'data.collect', 'payload' => ['items' => []]]]);
        $key = ApiKey::mint(['user_id' => $run->user_id, 'name' => 'Stop', 'abilities' => ['brain.read', 'brain.write'], 'active' => true]);
        $this->withHeader('X-Api-Key', $key['plain']);
        $body = ['operation_id' => (string) Str::uuid()];
        $url = '/api/v1/workflow-runs/'.$run->public_id.'/stop-after-step';
        $first = $this->postJson($url, $body)->assertOk()->assertJsonPath('data.status', 'cancelled')->json('data');
        $this->assertSame($first, $this->postJson($url, $body)->assertOk()->json('data'));
        $budget = $this->getJson('/api/v1/workflow-runs/'.$run->public_id)->assertOk()->json('data.root_budget');
        $this->assertSame($run->id, $budget['id']);
        $this->assertSame('completed', $budget['budget_state']['boundary_stop']['status']);
        $this->assertArrayNotHasKey('definition_snapshot', $budget);
        $foreign = ApiKey::mint(['user_id' => User::factory()->create()->id, 'name' => 'Other', 'abilities' => ['brain.write'], 'active' => true]);
        $this->withHeader('X-Api-Key', $foreign['plain'])->postJson($url, ['operation_id' => (string) Str::uuid()])->assertNotFound();
    }
}
