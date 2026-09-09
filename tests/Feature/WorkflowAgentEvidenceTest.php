<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowTestEvidence;
use App\Services\AuditLogger;
use App\Services\WorkflowAgentEvidence;
use App\Services\WorkflowService;
use App\Services\WorkflowTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowAgentEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_id' => 'evidence-device', 'name' => 'Test', 'status' => 'online',
            'meta' => ['workflow_capabilities' => ['environment_hash' => str_repeat('a', 64)]]]);
        $definition = WorkflowDefinition::create(['user_id' => $user->id, 'name' => 'Objective test', 'version' => 1, 'status' => 'active',
            'definition' => ['schema_version' => 2, 'thinking_tier' => 'balanced', 'steps' => [['key' => 'agent', 'type' => 'agent.single', 'payload' => ['instruction' => 'Return the fixed answer', 'agent_selection' => 'override', 'agent' => 'claude', 'model' => 'test-model']]]]]);
        $run = $this->makeRun($definition, $device);

        return [$definition, $device, $run];
    }

    private function makeRun(WorkflowDefinition $definition, Device $device, ?array $result = null): WorkflowRun
    {
        $run = app(WorkflowService::class)->createRun($definition, ['case' => 'same'], null, false, ['device_id' => $device->device_id]);
        $step = $run->steps()->sole();
        $payload = ['task_key' => $step->type, 'task_version' => $step->type_version, 'params' => $step->payload,
            'workflow' => ['run' => $run->public_id, 'step_id' => $step->id, 'execution_id' => $step->execution_id, 'device_id' => $device->device_id]];
        $job = DeviceJob::create(['public_id' => (string) Str::uuid(), 'user_id' => $run->user_id, 'project_id' => null, 'device_id' => $device->id,
            'workflow_execution_id' => $step->execution_id, 'tool_profile' => 'workflow.task', 'status' => $result ? 'completed' : 'running', 'risk_level' => 'normal',
            'payload' => $payload, 'payload_hash' => app(AuditLogger::class)->hash($payload), 'result' => $result,
            'result_hash' => $result ? app(AuditLogger::class)->hash($result) : null, 'finished_at' => $result ? now() : null]);
        $step->update(['status' => $result ? 'completed' : 'running', 'resolved_payload' => $step->payload, 'external_run_type' => 'device_job', 'external_run_id' => $job->public_id,
            'output' => $result ? array_merge($result, ['device_job' => $job->public_id, 'result' => $result]) : null]);
        $run->update(['status' => $result ? 'completed' : 'running', 'test_mode' => $result ? 'real' : null, 'finished_at' => $result ? now() : null]);

        return $run->fresh();
    }

    private function sample(WorkflowDefinition $definition, Device $device, bool $passed = true, array $extra = [], ?array $assertion = null): WorkflowTestEvidence
    {
        $result = array_replace(['ok' => true, 'agent' => 'claude', 'model' => 'test-model', 'requested_model' => 'test-model', 'model_confirmation' => 'runtime', 'text' => $passed ? 'EXPECTED' : 'WRONG'], $extra);
        $run = $this->makeRun($definition, $device, $result);
        $spec = ['input' => ['case' => 'same'], 'fixtures' => [], 'real_test_authorized' => true, 'device_id' => $device->device_id,
            'assertions' => [$assertion ?? ['step_key' => 'agent', 'kind' => 'value', 'path' => 'text', 'operator' => 'eq', 'value' => 'EXPECTED']]];
        $case = app(WorkflowTestService::class)->createCase($definition, ['name' => 'Fact test', 'specification' => $spec]);
        $snapshot = $run->definition_snapshot;
        $evidence = WorkflowTestEvidence::create(['user_id' => $run->user_id, 'workflow_definition_id' => $definition->id, 'workflow_test_case_id' => $case->id,
            'workflow_run_id' => $run->id, 'mode' => 'real', 'status' => $passed ? 'passed' : 'failed', 'finished_at' => now(),
            'definition_hash' => WorkflowTestService::hash($snapshot), 'code_hash' => WorkflowTestService::codeHash($snapshot),
            'assertions_hash' => $case->assertions_hash, 'fixture_hash' => $case->fixture_hash, 'environment_hash' => app(WorkflowTestService::class)->environment($device),
            'snapshot' => ['workflow' => $snapshot, 'test_case' => $spec, 'device_id' => $device->device_id, 'device_environment_hash' => $device->meta['workflow_capabilities']['environment_hash']]]);
        $context = $run->context;
        $context['_execution']['_test_evidence_id'] = $evidence->id;
        $run->update(['context' => $context]);

        return $evidence;
    }

    public function test_read_only_cohort_counts_real_pass_and_failure_without_invented_scores(): void
    {
        [$definition, $device, $run] = $this->fixture();
        for ($i = 0; $i < 5; $i++) {
            $this->sample($definition, $device, $i !== 4);
        }
        $service = app(WorkflowAgentEvidence::class);
        $response = $service->snapshot($run, $run->steps()->sole(), $device);
        $this->assertSame(5, $response['minimum_samples']);
        $this->assertCount(1, $response['rows']);
        $this->assertSame(5, $response['rows'][0]['samples']);
        $this->assertSame(4, $response['rows'][0]['passed']);
        $this->assertSame(1, $response['rows'][0]['failed']);
        $this->assertArrayNotHasKey('score', $response['rows'][0]);
        $this->assertSame($response, $service->snapshot($run, $run->steps()->sole(), $device));
        $this->assertDatabaseCount('workflow_test_evidence', 5);
    }

    public function test_simulated_tampered_unconfirmed_or_self_rated_results_do_not_contribute(): void
    {
        [$definition, $device, $run] = $this->fixture();
        $this->sample($definition, $device)->update(['mode' => 'simulation']);
        $this->sample($definition, $device)->update(['definition_hash' => str_repeat('f', 64)]);
        $this->sample($definition, $device)->update(['fixture_hash' => str_repeat('f', 64)]);
        $this->sample($definition, $device, true, ['model_confirmation' => 'unconfirmed']);
        $this->sample($definition, $device, true, [], ['step_key' => 'agent', 'kind' => 'value', 'path' => 'ok', 'operator' => 'eq', 'value' => true]);
        $this->sample($definition, $device, true, ['data' => ['quality' => 100]], ['step_key' => 'agent', 'kind' => 'value', 'path' => 'data.quality', 'operator' => 'eq', 'value' => 100]);
        $this->assertSame([], app(WorkflowAgentEvidence::class)->snapshot($run, $run->steps()->sole(), $device)['rows']);
    }

    public function test_local_runtime_identity_counts_only_with_matching_confirmed_local_target(): void
    {
        [$definition, $device, $run] = $this->fixture();
        $body = $definition->definition;
        $body['steps'][0]['payload']['agent_selection'] = 'auto';
        unset($body['steps'][0]['payload']['agent'], $body['steps'][0]['payload']['model']);
        $definition->update(['definition' => $body, 'version' => 2]);
        $local = ['agent' => 'local', 'model' => 'signed-local-release', 'requested_model' => null,
            'model_confirmation' => 'runtime', 'inference_target' => 'local_llama_cpp'];
        for ($i = 0; $i < 5; $i++) {
            $this->sample($definition, $device, $i !== 4, $local);
        }
        foreach ([['model' => null], ['model_confirmation' => 'unconfirmed'], ['inference_target' => null], ['inference_target' => 'laravel_proxy']] as $invalid) {
            $this->sample($definition, $device, true, array_replace($local, $invalid));
        }
        $rows = app(WorkflowAgentEvidence::class)->snapshot($run, $run->steps()->sole(), $device)['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame('local', $rows[0]['adapter']);
        $this->assertSame('signed-local-release', $rows[0]['model']);
        $this->assertSame('runtime', $rows[0]['model_source']);
        $this->assertSame(5, $rows[0]['samples']);
        $this->assertSame(4, $rows[0]['passed']);
        $this->assertSame(1, $rows[0]['failed']);
    }

    public function test_local_request_pin_cannot_substitute_for_runtime_identity(): void
    {
        [$definition, $device, $run] = $this->fixture();
        $body = $definition->definition;
        $body['steps'][0]['payload']['agent'] = 'local';
        $definition->update(['definition' => $body, 'version' => 2]);
        $this->sample($definition, $device, true, ['agent' => 'local', 'model' => null,
            'model_confirmation' => 'pinned_request', 'inference_target' => 'local_llama_cpp']);
        $this->assertSame([], app(WorkflowAgentEvidence::class)->snapshot($run, $run->steps()->sole(), $device)['rows']);
    }

    public function test_only_model_selection_fields_are_excluded_from_the_plan_comparison(): void
    {
        [$definition, $device, $run] = $this->fixture();
        $body = $definition->definition;
        $body['steps'][0]['payload']['model'] = 'second-model';
        $definition->update(['definition' => $body, 'version' => 2]);
        $this->sample($definition, $device, true, ['model' => 'second-model', 'requested_model' => 'second-model']);
        $body['thinking_tier'] = 'ultra';
        $definition->update(['definition' => $body, 'version' => 3]);
        $this->sample($definition, $device, true, ['model' => 'third-model']);
        $rows = app(WorkflowAgentEvidence::class)->snapshot($run, $run->steps()->sole(), $device)['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame('second-model', $rows[0]['model']);
        $this->assertSame(1, $rows[0]['samples']);
    }

    public function test_pinned_request_is_explicit_and_bound_to_the_actual_job_request(): void
    {
        [$definition, $device, $run] = $this->fixture();
        $this->sample($definition, $device, true, ['model' => null, 'model_confirmation' => 'pinned_request']);
        $this->sample($definition, $device, true, ['model' => null, 'model_confirmation' => 'pinned_request', 'requested_model' => 'invented']);
        $rows = app(WorkflowAgentEvidence::class)->snapshot($run, $run->steps()->sole(), $device)['rows'];
        $this->assertSame(1, $rows[0]['samples']);
        $this->assertSame('pinned_request', $rows[0]['model_source']);
    }

    public function test_changed_device_environment_and_ambiguous_assertions_do_not_pool_samples(): void
    {
        [$definition, $device, $run] = $this->fixture();
        $this->sample($definition, $device);
        $this->sample($definition, $device, true, ['text' => 'OTHER'], ['step_key' => 'agent', 'kind' => 'value', 'path' => 'text', 'operator' => 'eq', 'value' => 'OTHER']);
        $this->assertSame([], app(WorkflowAgentEvidence::class)->snapshot($run, $run->steps()->sole(), $device)['rows']);
        $device->update(['meta' => ['workflow_capabilities' => ['environment_hash' => str_repeat('b', 64)]]]);
        $this->assertSame([], app(WorkflowAgentEvidence::class)->snapshot($run, $run->steps()->sole(), $device->fresh())['rows']);
    }

    public function test_api_is_bound_to_user_device_and_exact_step(): void
    {
        [$definition, $device, $run] = $this->fixture();
        $key = ApiKey::mint(['user_id' => $definition->user_id, 'name' => 'Evidence read', 'device_id' => $device->device_id, 'abilities' => ['brain.read'], 'active' => true]);
        $uri = '/api/v1/workflow-runs/'.$run->public_id.'/steps/'.$run->steps()->sole()->id.'/agent-evidence';
        $this->withToken($key['plain'])->getJson($uri)->assertOk()->assertJsonPath('data.minimum_samples', 5);
        $otherKey = ApiKey::mint(['user_id' => User::factory()->create()->id, 'name' => 'Other', 'device_id' => $device->device_id, 'abilities' => ['brain.read'], 'active' => true]);
        $this->withToken($otherKey['plain'])->getJson($uri)->assertNotFound();
        $wrongDevice = Device::create(['user_id' => $definition->user_id, 'device_id' => 'other-device', 'name' => 'Other', 'status' => 'online']);
        $wrongKey = ApiKey::mint(['user_id' => $definition->user_id, 'name' => 'Other device', 'device_id' => $wrongDevice->device_id, 'abilities' => ['brain.read'], 'active' => true]);
        $this->withToken($wrongKey['plain'])->getJson($uri)->assertStatus(409);
    }

    public function test_changed_device_results_or_assertion_hashes_cannot_be_reused_as_quality(): void
    {
        [$definition, $device, $run] = $this->fixture();
        $changed = $this->sample($definition, $device);
        $sampleRun = WorkflowRun::findOrFail($changed->workflow_run_id);
        DeviceJob::where('workflow_execution_id', $sampleRun->steps()->sole()->execution_id)->update(['result_hash' => str_repeat('f', 64)]);
        $this->sample($definition, $device)->update(['assertions_hash' => str_repeat('f', 64)]);
        $this->assertSame([], app(WorkflowAgentEvidence::class)->snapshot($run, $run->steps()->sole(), $device)['rows']);
    }

    public function test_pinned_adapter_must_match_the_job_and_runtime_evidence_wins_without_double_counting(): void
    {
        [$definition, $device, $run] = $this->fixture();
        $this->sample($definition, $device, true, ['model_confirmation' => 'pinned_request', 'agent' => 'codex']);
        $this->sample($definition, $device, true, ['model_confirmation' => 'pinned_request']);
        $this->sample($definition, $device);
        $rows = app(WorkflowAgentEvidence::class)->snapshot($run, $run->steps()->sole(), $device)['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame('claude', $rows[0]['adapter']);
        $this->assertSame('runtime', $rows[0]['model_source']);
        $this->assertSame(1, $rows[0]['samples']);
    }
}
