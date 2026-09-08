<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowOperation;
use App\Services\WorkflowAuthoringService;
use App\Services\WorkflowService;
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

    public function test_condition_skips_unchosen_branch_and_join_uses_resolved_predecessor_output(): void
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
}
