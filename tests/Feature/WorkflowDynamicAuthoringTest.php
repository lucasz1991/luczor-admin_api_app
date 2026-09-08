<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowOperation;
use App\Models\WorkflowRun;
use App\Services\WorkflowAuthoringService;
use App\Services\WorkflowService;
use App\Services\WorkflowStepExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowDynamicAuthoringTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $user = User::factory()->create();
        $key = ApiKey::mint(['user_id' => $user->id, 'name' => 'Workflow', 'abilities' => ['brain.read', 'brain.write', 'device.connect'], 'active' => true]);
        $this->withHeader('X-Api-Key', $key['plain']);

        return $user;
    }

    private function definition(User $user, array $steps): WorkflowDefinition
    {
        return WorkflowDefinition::create(['user_id' => $user->id, 'name' => (string) Str::uuid(), 'version' => 1, 'status' => 'active', 'definition' => ['steps' => $steps]]);
    }

    public function test_rejected_graph_is_never_written_and_cross_owner_access_is_hidden(): void
    {
        $user = $this->actor();
        $this->postJson('/api/v1/workflows', ['name' => 'Invalid', 'operation_id' => (string) Str::uuid(), 'definition' => ['steps' => [['key' => 'a', 'type' => 'unknown']]]])->assertUnprocessable();
        $this->assertSame(0, WorkflowDefinition::count());
        $this->assertSame(0, WorkflowOperation::count());
        $foreign = $this->definition(User::factory()->create(), [['key' => 'a', 'type' => 'manual']]);
        $this->getJson('/api/v1/workflows/'.$foreign->id)->assertNotFound();
        $this->postJson('/api/v1/workflows', ['name' => 'Foreign child', 'definition' => ['steps' => [['key' => 'x', 'type' => 'workflow', 'payload' => ['workflow_definition_id' => $foreign->id]]]]])->assertNotFound();
        $this->assertSame(0, WorkflowDefinition::where('user_id', $user->id)->count());
    }

    public function test_operation_replay_and_version_conflict_preserve_immutable_revisions(): void
    {
        $this->actor();
        $operation = (string) Str::uuid();
        $body = ['name' => 'Saved', 'operation_id' => $operation, 'definition' => ['steps' => [['key' => 'a', 'type' => 'manual']]]];
        $id = $this->postJson('/api/v1/workflows', $body)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/workflows', $body)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, WorkflowDefinition::count());
        $this->getJson('/api/v1/workflow-operations/'.$operation)->assertOk()->assertJsonPath('data.response.id', $id);
        $this->patchJson('/api/v1/workflows/'.$id, ['name' => 'Changed', 'expected_version' => 1, 'operation_id' => (string) Str::uuid(), 'definition' => ['steps' => [['key' => 'b', 'type' => 'manual']]]])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/workflows/'.$id, ['name' => 'Stale', 'expected_version' => 1, 'operation_id' => (string) Str::uuid(), 'definition' => $body['definition']])->assertConflict();
        $definition = WorkflowDefinition::findOrFail($id);
        $this->assertSame('a', $definition->revisions()->where('version', 1)->firstOrFail()->definition['steps'][0]['key']);
        $this->assertSame('b', $definition->currentRevision->definition['steps'][0]['key']);
        $this->assertSame(2, $definition->revisions()->count());
        $this->postJson('/api/v1/workflows', array_replace($body, ['name' => 'Reused differently']))->assertConflict();
    }

    public function test_run_freezes_nested_definitions_and_new_runs_select_latest(): void
    {
        Queue::fake();
        $user = $this->actor();
        $child = $this->definition($user, [['key' => 'old', 'type' => 'manual']]);
        $parent = $this->definition($user, [['key' => 'child', 'type' => 'workflow', 'payload' => ['workflow_definition_id' => $child->id]]]);
        $service = app(WorkflowService::class);
        $run = $service->createRun($parent);
        $child->update(['definition' => ['steps' => [['key' => 'new', 'type' => 'manual']]]]);
        $service->advance($run);
        $childRun = $service->startChildWorkflow($run->steps()->first());
        $this->assertSame('old', $childRun->steps()->first()->step_key);
        $later = $service->createRun($parent->fresh());
        $this->assertSame('new', $later->definition_snapshot['children']['child']['definition']['steps'][0]['key']);
        $this->assertSame($childRun->id, $service->startChildWorkflow($run->steps()->first())->id);
    }

    public function test_condition_skips_unchosen_branch_and_join_continues(): void
    {
        $user = $this->actor();
        $definition = $this->definition($user, [
            ['key' => 'check', 'type' => 'condition', 'payload' => ['left' => ['$ref' => 'input.enabled'], 'operator' => 'eq', 'right' => true], 'routes' => ['true' => ['type' => 'step', 'step_key' => 'yes'], 'false' => ['type' => 'step', 'step_key' => 'no']]],
            ['key' => 'yes', 'type' => 'task.create', 'depends_on' => ['check'], 'payload' => ['input_bindings' => ['title' => 'input.title']]],
            ['key' => 'no', 'type' => 'task.create', 'depends_on' => ['check'], 'payload' => ['title' => 'Must not run']],
            ['key' => 'join', 'type' => 'manual', 'depends_on' => ['yes', 'no']],
        ]);
        $service = app(WorkflowService::class);
        $run = $service->advance($service->createRun($definition, ['enabled' => true, 'title' => 'Bound title']));
        $this->assertSame(['Bound title'], Task::pluck('title')->all(), json_encode($run->steps()->get(['step_key', 'status', 'error', 'output'])->toArray()));
        $this->assertSame('skipped', $run->steps()->where('step_key', 'no')->first()->status);
        $this->assertSame('ready', $run->steps()->where('step_key', 'join')->first()->status);
        $service->complete($run->steps()->where('step_key', 'join')->first(), []);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_historical_run_dtos_and_recovery_report_the_frozen_definition_version(): void
    {
        $user = $this->actor();
        $definition = $this->definition($user, [['key' => 'old', 'type' => 'manual']]);
        $operationId = (string) Str::uuid();
        $runId = $this->postJson('/api/v1/workflows/'.$definition->id.'/runs', ['operation_id' => $operationId])->assertCreated()->assertJsonPath('data.definition_version', 1)->json('data.public_id');
        $definition->update(['definition' => ['steps' => [['key' => 'new', 'type' => 'manual']]]]);
        $this->assertSame(2, $definition->version);
        $this->getJson('/api/v1/workflow-runs/'.$runId)->assertOk()->assertJsonPath('data.definition_version', 1);
        $this->getJson('/api/v1/workflows/'.$definition->id.'/runs')->assertOk()->assertJsonPath('data.0.definition_version', 1);
        $this->getJson('/api/v1/workflow-operations/'.$operationId)->assertOk()->assertJsonPath('data.response.definition_version', 1);
        $this->postJson('/api/v1/workflows/'.$definition->id.'/runs')->assertCreated()->assertJsonPath('data.definition_version', 2);
        $this->assertNull((new WorkflowRun)->definition_version);
    }

    public function test_bound_offline_device_waits_without_dispatch_or_timeout(): void
    {
        $user = $this->actor();
        Device::create(['user_id' => $user->id, 'device_id' => 'offline', 'name' => 'Offline', 'status' => 'offline']);
        $definition = $this->definition($user, [['key' => 'ai', 'type' => 'llm', 'payload' => ['instruction' => 'Summarize']]]);
        $service = app(WorkflowService::class);
        $run = $service->advance($service->createRun($definition, [], null, false, ['device_id' => 'offline', 'strict_target' => true]));
        $step = $run->steps()->first();
        $this->assertSame('waiting_for_device', $step->status);
        $this->assertNull($step->started_at);
        $this->travel(1)->hours();
        $this->assertSame(0, $service->expireTimedOutSteps($run->fresh()));
        $this->assertSame(0, DeviceJob::count());
        $this->travelBack();
    }

    public function test_executable_approval_dispatches_and_result_ack_is_idempotent(): void
    {
        $user = $this->actor();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        Config::set('luczor.device_jobs.private_key', $privateKey);
        $this->postJson('/api/v1/devices/register', ['client_id' => 'desktop', 'name' => 'Desktop'])->assertCreated();
        $definition = $this->definition($user, [['key' => 'write', 'type' => 'file.write', 'payload' => ['path' => 'example.txt', 'content' => 'x']]]);
        $service = app(WorkflowService::class);
        $run = $service->advance($service->createRun($definition, [], null, false, ['device_id' => 'desktop', 'strict_target' => true]));
        $step = $run->steps()->first();
        $this->assertSame('awaiting_approval', $step->status);
        $service->approve($step, $user->id);
        $this->assertSame('running', $step->fresh()->status);
        $job = DeviceJob::sole();
        $path = '/api/v1/devices/jobs/'.$job->public_id;
        $this->postJson($path.'/approve', ['client_id' => 'desktop', 'approved' => true])->assertOk();
        $this->postJson($path.'/start', ['client_id' => 'desktop'])->assertOk();
        $body = ['client_id' => 'desktop', 'ok' => true, 'result' => ['written' => true]];
        $this->postJson($path.'/complete', $body)->assertOk();
        $this->postJson($path.'/complete', $body)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->postJson($path.'/complete', array_replace($body, ['result' => ['written' => false]]))->assertConflict();
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_operation_response_is_encrypted_in_storage(): void
    {
        $user = $this->actor();
        $id = (string) Str::uuid();
        app(WorkflowAuthoringService::class)->operate($user->id, $id, 'webhook.create', [], fn () => ['webhook_secret' => 'sensitive-fixture']);
        $operation = WorkflowOperation::sole();
        $this->assertStringNotContainsString('sensitive-fixture', $operation->getRawOriginal('response'));
        $this->getJson('/api/v1/workflow-operations/'.$id)->assertOk()->assertJsonPath('data.response.webhook_secret', 'sensitive-fixture');
    }

    public function test_cancel_waits_for_device_ack_and_late_completion_cannot_revive_run(): void
    {
        $user = $this->actor();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        Config::set('luczor.device_jobs.private_key', $privateKey);
        $this->postJson('/api/v1/devices/register', ['client_id' => 'desktop', 'name' => 'Desktop'])->assertCreated();
        $definition = $this->definition($user, [['key' => 'read', 'type' => 'file.read', 'payload' => ['path' => 'example.txt']]]);
        $service = app(WorkflowService::class);
        $run = $service->advance($service->createRun($definition, [], null, false, ['device_id' => 'desktop', 'strict_target' => true]));
        $job = DeviceJob::sole();
        $path = '/api/v1/devices/jobs/'.$job->public_id;
        $this->postJson($path.'/approve', ['client_id' => 'desktop', 'approved' => true])->assertOk();
        $this->postJson($path.'/start', ['client_id' => 'desktop'])->assertOk();
        $this->assertSame('cancelling', $service->cancel($run)->status);
        $this->getJson($path.'/status?client_id=desktop')->assertOk()->assertJsonPath('data.cancel_requested', true);
        $this->postJson($path.'/complete', ['client_id' => 'desktop', 'ok' => true, 'result' => ['content' => 'late']])->assertConflict();
        $this->postJson($path.'/cancel-ack', ['client_id' => 'desktop'])->assertOk();
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertNull($job->fresh()->result);
    }

    public function test_retry_reuses_execution_identity_but_explicit_loop_has_new_identity(): void
    {
        $user = $this->actor();
        $definition = $this->definition($user, [['key' => 'loop', 'type' => 'manual', 'routes' => ['success' => ['type' => 'step', 'step_key' => 'loop', 'max_iterations' => 2]]]]);
        $service = app(WorkflowService::class);
        $run = $service->advance($service->createRun($definition));
        $step = $run->steps()->first();
        $firstId = $step->execution_id;
        $service->fail($step, 'Transient failure');
        $this->assertSame($firstId, $step->fresh()->execution_id);
        $this->travel(3)->seconds();
        $service->advance($run->fresh());
        $service->complete($step->fresh(), []);
        $this->assertNotSame($firstId, $step->fresh()->execution_id);
        $this->assertSame(2, $step->fresh()->execution_sequence);
        $this->travelBack();
    }

    public function test_cycles_and_future_step_bindings_are_rejected_without_writes(): void
    {
        $this->actor();
        foreach ([
            [['key' => 'a', 'type' => 'manual', 'depends_on' => ['b']], ['key' => 'b', 'type' => 'manual', 'depends_on' => ['a']]],
            [['key' => 'a', 'type' => 'manual', 'payload' => ['input_bindings' => ['text' => 'steps.b.text']]], ['key' => 'b', 'type' => 'manual']],
        ] as $steps) {
            $this->postJson('/api/v1/workflows', ['name' => 'Invalid', 'operation_id' => (string) Str::uuid(), 'definition' => ['steps' => $steps]])->assertUnprocessable();
        }
        $this->assertSame(0, WorkflowDefinition::count());
    }

    public function test_revision_details_include_original_definition_and_change_summary(): void
    {
        $this->actor();
        $id = $this->postJson('/api/v1/workflows', ['name' => 'History', 'change_summary' => 'Initial workflow', 'definition' => ['steps' => [['key' => 'first', 'type' => 'manual']]]])->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/workflows/'.$id, ['name' => 'History', 'expected_version' => 1, 'change_summary' => 'New stage', 'definition' => ['steps' => [['key' => 'second', 'type' => 'manual']]]])->assertOk();
        $this->getJson('/api/v1/workflows/'.$id.'/revisions/1')->assertOk()->assertJsonPath('data.definition.steps.0.key', 'first')->assertJsonPath('data.change_summary', 'Initial workflow');
        $this->getJson('/api/v1/workflows/'.$id.'/revisions/2')->assertOk()->assertJsonPath('data.change_summary', 'New stage');
    }

    public function test_nested_sandbox_inherits_simulation_and_server_task_retry_does_not_duplicate(): void
    {
        $user = $this->actor();
        $child = $this->definition($user, [['key' => 'task', 'type' => 'task.create', 'payload' => ['title' => 'One task']]]);
        $parent = $this->definition($user, [['key' => 'child', 'type' => 'workflow', 'payload' => ['workflow_definition_id' => $child->id]]]);
        $service = app(WorkflowService::class);
        $sandbox = $service->advance($service->createRun($parent, [], null, true));
        $this->assertSame(0, Task::count());
        $this->assertTrue(WorkflowRun::where('parent_workflow_run_id', $sandbox->id)->sole()->sandbox);
        $run = $service->advance($service->createRun($child));
        $step = $run->steps()->first();
        $run->refresh()->update(['status' => 'running', 'finished_at' => null]);
        $step->update(['status' => 'ready', 'finished_at' => null]);
        app(WorkflowStepExecutor::class)->execute($step->id);
        $this->assertSame(1, Task::count());
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_bindings_resolve_dotted_step_keys_and_reject_shadowed_non_predecessors(): void
    {
        $user = $this->actor();
        $definition = $this->definition($user, [
            ['key' => 'source.data', 'type' => 'manual'],
            ['key' => 'check', 'type' => 'condition', 'depends_on' => ['source.data'], 'payload' => ['left' => ['$ref' => 'steps.source.data.value'], 'operator' => 'eq', 'right' => 42]],
        ]);
        $service = app(WorkflowService::class);
        $run = $service->advance($service->createRun($definition));
        $service->complete($run->steps()->where('step_key', 'source.data')->first(), ['value' => 42]);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('true', $run->steps()->where('step_key', 'check')->first()->output['outcome']);
        $this->postJson('/api/v1/workflows/validate', ['definition' => ['steps' => [
            ['key' => 'source', 'type' => 'manual'],
            ['key' => 'source.data', 'type' => 'manual'],
            ['key' => 'check', 'type' => 'manual', 'depends_on' => ['source'], 'payload' => ['input_bindings' => ['text' => 'steps.source.data.value']]],
        ]]])->assertUnprocessable();
    }
}
