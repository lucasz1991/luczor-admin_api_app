<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\CoordinatedDeviceJobs;
use App\Services\DeviceLeadership;
use App\Services\ProjectMirror;
use App\Services\WorkflowAuthoringService;
use App\Services\WorkflowDeviceCapabilities;
use App\Services\WorkflowDeviceTarget;
use App\Services\WorkflowService;
use App\Services\WorkflowStepExecutor;
use App\Services\WorkflowTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowCoordinationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $master;

    private Device $worker;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        Config::set('luczor.device_jobs.private_key', $private);
        Config::set('luczor.device_jobs.private_key_file', '');
        $this->owner = User::factory()->create();
        Device::create(['user_id' => $this->owner->id, 'device_id' => 'master', 'name' => 'Master', 'status' => 'online']);
        $this->worker = Device::create(['user_id' => $this->owner->id, 'device_id' => 'worker', 'name' => 'Worker', 'status' => 'online']);
        foreach (Device::where('user_id', $this->owner->id)->get() as $device) {
            app(WorkflowDeviceCapabilities::class)->report($device, ['schema_version' => 1, 'environment_hash' => str_repeat('a', 64), 'tasks' => []]);
        }
        $this->master = ApiKey::mint(['user_id' => $this->owner->id, 'device_id' => 'master', 'name' => 'Master',
            'abilities' => ['device.connect', 'brain.read', 'brain.write', 'device.jobs.read'], 'active' => true])['plain'];
        $this->withHeader('X-Api-Key', $this->master)->postJson('/api/v1/coordination/heartbeat', ['available' => true, 'busy' => false, 'preferred' => true])->assertOk();
    }

    public function test_matrix_freezes_one_revision_has_per_device_evidence_and_is_idempotent(): void
    {
        $definition = $this->definition([['key' => 'collect', 'type' => 'data.collect', 'payload' => ['items' => [2]]]]);
        $case = app(WorkflowTestService::class)->createCase($definition, ['name' => 'Output', 'specification' => [
            'assertions' => [['step_key' => 'collect', 'kind' => 'value', 'path' => 'data.0', 'operator' => 'eq', 'value' => 2]]]]);
        $body = ['operation_id' => (string) Str::uuid(), 'master_epoch' => 1, 'expected_version' => 1, 'test_case_id' => $case->id,
            'mode' => 'definition', 'device_ids' => ['master', 'worker']];
        $url = '/api/v1/workflows/'.$definition->id.'/test-matrices';
        $matrix = $this->postJson($url, $body)->assertOk()->assertJsonPath('data.status', 'passed')->assertJsonCount(2, 'data.tests')->json('data');
        $this->assertSame(['master', 'worker'], array_column($matrix['tests'], 'device_id'));
        $this->assertSame([1, 1], array_column($matrix['tests'], 'definition_version'));
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.matrix_id', $matrix['matrix_id']);
        $this->assertDatabaseCount('workflow_test_evidence', 2);
        $body['operation_id'] = (string) Str::uuid();
        $body['expected_version'] = 2;
        $this->postJson($url, $body)->assertConflict();
        $body['expected_version'] = 1;
        $body['device_ids'] = ['unknown'];
        $this->postJson($url, $body)->assertNotFound();
        $this->assertDatabaseCount('workflow_test_evidence', 2);
    }

    public function test_target_map_is_frozen_and_client_task_creates_fenced_v2_job_for_selected_device(): void
    {
        app(WorkflowDeviceCapabilities::class)->report($this->worker, ['schema_version' => 1, 'environment_hash' => str_repeat('a', 64),
            'tasks' => [['type' => 'browser.read', 'version' => 1, 'adapter' => 'browser.session', 'available' => true]]]);
        $definition = $this->definition([['key' => 'inspect', 'type' => 'browser.read', 'payload' => ['browser_session_id' => 'test-browser']]]);
        $url = '/api/v1/workflows/'.$definition->id.'/runs';
        $body = ['operation_id' => (string) Str::uuid(), 'master_epoch' => 1, 'device_id' => 'master', 'device_targets' => ['inspect' => 'worker']];
        $result = $this->postJson($url, $body)->assertCreated()->json('data');
        app(WorkflowStepExecutor::class)->execute($result['steps'][0]['id']);
        $job = DeviceJob::firstOrFail();
        $this->assertSame(2, $job->protocol_version);
        $this->assertSame(1, $job->master_epoch);
        $this->assertSame($this->worker->id, $job->device_id);
        $this->assertFalse($job->requires_local_approval);
        $this->assertStringNotContainsString('test-browser', $job->getRawOriginal('payload'));
        $this->postJson($url, $body)->assertCreated();
        $this->assertDatabaseCount('device_jobs', 1);
        $attempt = ['attempt_id' => (string) Str::uuid(), 'master_epoch' => 1];
        $jobs = app(CoordinatedDeviceJobs::class);
        $jobs->mutate($this->worker, $job->public_id, 'claim', $attempt);
        // Failover requests a stop, but cannot pretend the old native execution already ended.
        $job->update(['status' => 'cancelling', 'cancel_requested_at' => now()]);
        $run = WorkflowRun::findOrFail($result['id']);
        app(WorkflowService::class)->cancel($run);
        $this->assertSame('cancelling', $run->fresh()->status);
        $jobs->mutate($this->worker, $job->public_id, 'cancel-ack', $attempt);
        app(WorkflowService::class)->settleCancellation($run->fresh());
        $this->assertSame('cancelled', $run->fresh()->status);
        $body['operation_id'] = (string) Str::uuid();
        $body['device_targets'] = ['unknown_step' => 'worker'];
        $this->postJson($url, $body)->assertUnprocessable();
        $body['device_targets'] = ['inspect' => 'foreign'];
        $this->postJson($url, $body)->assertUnprocessable();
    }

    public function test_explicit_step_payload_target_precedes_global_default_when_no_root_target_map_exists(): void
    {
        $definition = $this->definition([['key' => 'inspect', 'type' => 'browser.read', 'payload' => ['device_id' => 'worker']]]);
        $run = app(WorkflowService::class)->createRun($definition, [], null, false, ['device_id' => 'master']);
        $this->assertSame('worker', WorkflowDeviceTarget::forStep($run->steps()->firstOrFail()));
    }

    public function test_real_matrix_requires_and_freezes_identical_project_mirror_and_separate_run_identity(): void
    {
        $project = Project::create(['user_id' => $this->owner->id, 'external_id' => 'same-project', 'name' => 'Same source']);
        $definition = $this->definition([['key' => 'collect', 'type' => 'data.collect', 'payload' => ['items' => [2]]]]);
        $definition->update(['project_id' => $project->id]);
        $case = app(WorkflowTestService::class)->createCase($definition, ['name' => 'Real', 'specification' => [
            'real_test_authorized' => true, 'assertions' => [['step_key' => 'collect', 'kind' => 'value', 'path' => 'data.0', 'operator' => 'eq', 'value' => 2]]]]);
        $body = ['operation_id' => (string) Str::uuid(), 'master_epoch' => 1, 'expected_version' => 1, 'test_case_id' => $case->id,
            'mode' => 'real', 'device_ids' => ['master', 'worker']];
        $url = '/api/v1/workflows/'.$definition->id.'/test-matrices';
        $this->postJson($url, $body)->assertConflict();
        $mirror = app(ProjectMirror::class);
        $device = Device::where('device_id', 'master')->firstOrFail();
        $lease = $mirror->lease($device, $project, ['master_epoch' => 1, 'expected_revision' => 0]);
        $manifest = $mirror->create($device, $project, ['operation_id' => (string) Str::uuid(), 'master_epoch' => 1,
            'lease_id' => $lease['lease_id'], 'base_revision' => 0, 'entries' => []]);
        $matrix = $this->postJson($url, $body)->assertOk()->assertJsonPath('data.mirror_manifest_id', $manifest['manifest_id'])
            ->assertJsonPath('data.mirror_revision', 1)->json('data');
        $runs = DB::table('workflow_runs')->get();
        $this->assertCount(2, $runs);
        $this->assertNotSame($runs[0]->public_id, $runs[1]->public_id);
        foreach ($runs as $run) {
            $context = json_decode($run->context, true);
            $this->assertSame($manifest['manifest_id'], $context['_execution']['mirror_manifest_id']);
            $this->assertSame(1, $context['_execution']['mirror_revision']);
        }
    }

    public function test_persisted_device_selectors_use_frozen_definition_and_do_not_fall_back_without_capability(): void
    {
        $definition = $this->definition([['key' => 'inspect', 'type' => 'browser.read', 'device_target' => ['kind' => 'capability'], 'payload' => []]]);
        $service = app(WorkflowService::class);
        $run = $service->createRun($definition, [], null, false, ['device_id' => 'master']);
        $service->advance($run);
        $step = $run->steps()->firstOrFail();
        app(WorkflowStepExecutor::class)->execute($step->id);
        $this->assertSame('waiting_for_capability', $step->fresh()->status);
        $this->assertDatabaseCount('device_jobs', 0);
        app(WorkflowDeviceCapabilities::class)->report($this->worker, ['schema_version' => 1, 'environment_hash' => str_repeat('a', 64),
            'tasks' => [['type' => 'browser.read', 'version' => 1, 'adapter' => 'browser.session', 'available' => true]]]);
        app(DeviceLeadership::class)->status($this->worker, ['available' => true, 'busy' => false]);
        // Editing a live definition cannot reroute its frozen run.
        $definition->update(['version' => 2, 'definition' => ['schema_version' => 2, 'steps' => [['key' => 'inspect', 'type' => 'browser.read',
            'device_target' => ['kind' => 'specific', 'device_id' => 'master'], 'payload' => []]]]]);
        $service->advance($run->fresh());
        app(WorkflowStepExecutor::class)->execute($step->id);
        $this->assertSame('worker', $step->fresh()->control_state['admitted_device_id']);
        $this->assertSame($this->worker->id, DeviceJob::sole()->device_id);
        app(WorkflowDeviceCapabilities::class)->report($this->worker, ['schema_version' => 1, 'environment_hash' => str_repeat('b', 64), 'tasks' => []]);
        $this->assertSame('worker', WorkflowDeviceTarget::forStep($step->fresh()));
        $this->assertSame(1, $run->fresh()->definition_version);
    }

    public function test_saved_specific_targets_reject_foreign_accounts_and_run_overrides_are_explicit(): void
    {
        Device::create(['user_id' => User::factory()->create()->id, 'device_id' => 'foreign', 'name' => 'Foreign']);
        $authoring = app(WorkflowAuthoringService::class);
        $steps = [['key' => 'inspect', 'type' => 'browser.read', 'device_target' => ['kind' => 'specific', 'device_id' => 'worker'], 'payload' => []]];
        $saved = $authoring->save($this->owner->id, ['operation_id' => (string) Str::uuid(), 'name' => 'Saved selector', 'definition' => ['schema_version' => 2, 'steps' => $steps]]);
        $definition = WorkflowDefinition::findOrFail($saved['id']);
        $run = app(WorkflowService::class)->createRun($definition, [], null, false, ['device_id' => 'master', 'device_targets' => ['inspect' => 'master']]);
        $this->assertSame('master', WorkflowDeviceTarget::forStep($run->steps()->firstOrFail()));
        $steps[0]['device_target']['device_id'] = 'foreign';
        $this->expectException(HttpException::class);
        $authoring->save($this->owner->id, ['operation_id' => (string) Str::uuid(), 'name' => 'Foreign selector', 'definition' => ['schema_version' => 2, 'steps' => $steps]]);
    }

    public function test_current_and_coordinator_selectors_are_distinct_and_runtime_bindings_cannot_replace_them(): void
    {
        $master = Device::where('device_id', 'master')->firstOrFail();
        $definition = $this->definition([
            ['key' => 'local', 'type' => 'browser.read', 'device_target' => ['kind' => 'current'], 'payload' => []],
            ['key' => 'lead', 'type' => 'browser.read', 'device_target' => ['kind' => 'coordinator'], 'payload' => []],
        ]);
        $run = app(WorkflowService::class)->createRun($definition, [], null, false, ['device_id' => 'worker', 'coordination_source_device_id' => $master->id]);
        $this->assertSame('worker', WorkflowDeviceTarget::forStep($run->steps()->where('step_key', 'local')->firstOrFail()));
        $this->assertSame('master', WorkflowDeviceTarget::forStep($run->steps()->where('step_key', 'lead')->firstOrFail()));
        $this->expectException(HttpException::class);
        app(WorkflowAuthoringService::class)->validate($this->owner->id, ['schema_version' => 2, 'steps' => [[
            'key' => 'bad', 'type' => 'browser.read', 'device_target' => ['kind' => 'specific', 'device_id' => ['$ref' => 'input.target']], 'payload' => [],
        ]]]);
    }

    public function test_workflow_handoff_keeps_attempt_and_revision_and_dispatches_only_next_step_under_new_epoch(): void
    {
        app(WorkflowDeviceCapabilities::class)->report($this->worker, ['schema_version' => 1, 'environment_hash' => str_repeat('a', 64),
            'tasks' => [['type' => 'browser.read', 'version' => 1, 'adapter' => 'browser.session', 'available' => true]]]);
        $definition = $this->definition([
            ['key' => 'first', 'type' => 'browser.read', 'device_target' => ['kind' => 'specific', 'device_id' => 'worker'], 'payload' => []],
            ['key' => 'second', 'type' => 'browser.read', 'depends_on' => ['first'], 'device_target' => ['kind' => 'specific', 'device_id' => 'worker'], 'payload' => []],
        ]);
        $result = $this->postJson('/api/v1/workflows/'.$definition->id.'/runs', ['operation_id' => (string) Str::uuid(), 'master_epoch' => 1, 'device_id' => 'master'])->assertCreated()->json('data');
        app(WorkflowStepExecutor::class)->execute(WorkflowRun::findOrFail($result['id'])->steps()->where('step_key', 'first')->firstOrFail()->id);
        $job = DeviceJob::sole();
        $attempt = ['attempt_id' => (string) Str::uuid(), 'master_epoch' => 1];
        app(CoordinatedDeviceJobs::class)->mutate($this->worker, $job->public_id, 'claim', $attempt);
        $this->travel(46)->seconds();
        app(DeviceLeadership::class)->status($this->worker, ['available' => true, 'busy' => true]);
        $run = WorkflowRun::findOrFail($result['id']);
        $this->assertSame(2, $run->context['_execution']['coordination_epoch']);
        $this->assertSame(1, $run->definition_version);
        app(CoordinatedDeviceJobs::class)->mutate($this->worker, $job->public_id, 'complete', $attempt + ['ok' => true, 'result' => ['status' => 'success', 'data' => ['text' => 'Observed once']]]);
        app(WorkflowService::class)->syncDeviceJobSteps($run);
        $this->assertSame('completed', $run->steps()->where('step_key', 'first')->firstOrFail()->status);
        app(WorkflowStepExecutor::class)->execute($run->steps()->where('step_key', 'second')->firstOrFail()->id);
        $jobs = DeviceJob::orderBy('id')->get();
        $this->assertCount(2, $jobs);
        $this->assertSame($attempt['attempt_id'], $jobs[0]->attempt_id);
        $this->assertSame(1, $jobs[0]->master_epoch);
        $this->assertSame(2, $jobs[1]->master_epoch);
        $this->assertSame($this->worker->id, $jobs[1]->source_device_id);
    }

    private function definition(array $steps): WorkflowDefinition
    {
        return WorkflowDefinition::create(['user_id' => $this->owner->id, 'name' => 'Shared workflow', 'version' => 1,
            'status' => 'active', 'definition' => ['schema_version' => 2, 'steps' => $steps]]);
    }
}
