<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRepairRevision;
use App\Services\WorkflowDeviceCapabilities;
use App\Services\WorkflowRepairService;
use App\Services\WorkflowService;
use App\Services\WorkflowTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowTestEvidenceAndRepairTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_id' => 'test-device', 'name' => 'Fixture', 'status' => 'online']);
        app(WorkflowDeviceCapabilities::class)->report($device, ['schema_version' => 1, 'environment_hash' => str_repeat('a', 64), 'tasks' => []]);
        $definition = WorkflowDefinition::create(['user_id' => $user->id, 'name' => 'Repair candidate', 'version' => 1, 'status' => 'active', 'definition' => ['schema_version' => 2, 'steps' => [
            ['key' => 'select', 'type' => 'data.filter', 'max_attempts' => 1, 'payload' => ['items' => [1, 2], 'condition' => ['operator' => 'invalid', 'value' => 2]]],
        ]]]);
        $tests = app(WorkflowTestService::class);
        $case = $tests->createCase($definition, ['name' => 'Expected value', 'specification' => ['input' => [], 'fixtures' => [], 'real_test_authorized' => true, 'device_id' => $device->device_id,
            'assertions' => [['step_key' => 'select', 'kind' => 'value', 'path' => 'data.0', 'operator' => 'eq', 'value' => 2]]]]);
        $run = app(WorkflowService::class)->advance(app(WorkflowService::class)->createRun($definition))->fresh();
        $this->assertSame('failed', $run->status);
        $repairs = app(WorkflowRepairService::class);
        $repairs->configure($definition, ['enabled' => true, 'auto_activate' => true, 'max_repairs' => 2, 'test_case_id' => $case->id,
            'expected_version' => 1, 'device_id' => $device->device_id, 'local_approved' => true], $device->device_id);
        $candidate = $definition->definition;
        $candidate['steps'][0]['payload']['condition']['operator'] = 'eq';
        $repair = $repairs->propose($definition->fresh(), $run, $candidate, 1);

        return [$definition->fresh(), $device->fresh(), $case, $repair, $run];
    }

    public function test_three_modes_store_exact_evidence_and_only_real_pass_autoactivates(): void
    {
        [$definition, $device, $case, $repair, $source] = $this->fixture();
        $tests = app(WorkflowTestService::class);
        $definitionTest = $tests->start($definition, $case, 'definition', $device, $repair);
        $this->assertSame('passed', $definitionTest->status);
        $this->assertNull($definitionTest->workflow_run_id);
        $simulation = $tests->start($definition, $case, 'simulation', $device, $repair);
        $this->assertSame('passed', $simulation->status);
        $this->assertSame(1, $definition->fresh()->version);
        $real = $tests->start($definition, $case, 'real', $device, $repair);
        $this->assertSame('passed', $real->status);
        $this->assertSame($definitionTest->definition_hash, $real->definition_hash);
        $this->assertSame($simulation->environment_hash, $real->environment_hash);
        $this->assertSame('activated', $repair->fresh()->status);
        $this->assertSame(2, $definition->fresh()->version);
        $this->assertSame('invalid', $source->fresh()->definition_snapshot['definition']['steps'][0]['payload']['condition']['operator']);
        $this->assertSame('eq', $definition->fresh()->definition['steps'][0]['payload']['condition']['operator']);
    }

    public function test_changed_environment_blocks_activation_without_relabeling_test_success(): void
    {
        [$definition, $device, $case, $repair] = $this->fixture();
        $policy = $definition->repair_policy;
        $policy['auto_activate'] = false;
        $definition->update(['repair_policy' => $policy]);
        $scope = $repair->scope;
        $scope['policy'] = $policy;
        $repair->update(['scope' => $scope]);
        $tests = app(WorkflowTestService::class);
        foreach (['definition', 'simulation', 'real'] as $mode) {
            $this->assertSame('passed', $tests->start($definition, $case, $mode, $device, $repair)->status);
        }
        app(WorkflowDeviceCapabilities::class)->report($device, ['schema_version' => 1, 'environment_hash' => str_repeat('b', 64), 'tasks' => []]);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('workflow_repair_test_evidence_missing');
        app(WorkflowRepairService::class)->activate($repair);
    }

    public function test_device_environment_is_frozen_and_list_returns_the_same_evidence_metadata(): void
    {
        [$definition, $device, $case, $repair] = $this->fixture();
        $tests = app(WorkflowTestService::class);
        $evidence = $tests->start($definition, $case, 'simulation', $device, $repair);
        app(WorkflowDeviceCapabilities::class)->report($device, ['schema_version' => 1, 'environment_hash' => str_repeat('b', 64), 'tasks' => []]);
        $key = ApiKey::mint(['user_id' => $definition->user_id, 'name' => 'Evidence', 'abilities' => ['brain.read'], 'active' => true]);
        $this->withHeader('X-Api-Key', $key['plain']);
        $detail = $this->getJson('/api/v1/workflow-tests/'.$evidence->id)->assertOk()->json('data');
        $list = $this->getJson('/api/v1/workflows/'.$definition->id.'/tests')->assertOk()->json('data.0');
        $this->assertSame(str_repeat('a', 64), $detail['device_environment_hash']);
        $this->assertSame($detail['device_environment_hash'], $detail['snapshot']['device_environment_hash']);
        foreach (['id', 'run_public_id', 'device_id', 'device_environment_hash', 'definition_version', 'repair_status', 'repair_policy_hash'] as $field) {
            $this->assertSame($detail[$field], $list[$field]);
        }
        $this->assertNotNull($list['run_public_id']);
    }

    public function test_repair_cannot_expand_a_nested_control_body_budget(): void
    {
        [$definition, $device, $case, $repair, $source] = $this->fixture();
        $nested = ['schema_version' => 2, 'steps' => [['key' => 'select', 'type' => 'data.filter', 'max_attempts' => 1, 'payload' => ['items' => [1], 'condition' => ['operator' => 'invalid', 'value' => 1]]]], 'budgets' => ['max_executions' => 1]];
        $definition->update(['definition' => ['schema_version' => 2, 'steps' => [['key' => 'body', 'type' => 'control.foreach', 'max_attempts' => 1, 'payload' => ['items' => [1], 'body' => $nested]]]]]);
        $source = app(WorkflowService::class)->advance(app(WorkflowService::class)->createRun($definition))->fresh();
        $this->assertSame('failed', $source->status);
        $candidate = $definition->definition;
        $candidate['steps'][0]['payload']['body']['budgets']['max_executions'] = 2;
        $this->expectExceptionMessage('workflow_repair_nested_budget_expansion');
        app(WorkflowRepairService::class)->propose($definition, $source, $candidate, (int) $definition->version);
    }

    public function test_repair_cannot_downgrade_schema_or_expand_inherited_thinking_policy(): void
    {
        [$definition, $device, $case, $repair, $source] = $this->fixture();
        $legacy = ['schema_version' => 2, 'steps' => [['key' => 'condition', 'type' => 'condition', 'max_attempts' => 1, 'payload' => ['left' => '1', 'right' => '1']]]];
        $definition->update(['definition' => $legacy]);
        $source = app(WorkflowService::class)->createRun($definition);
        $source->update(['status' => 'failed']);
        foreach ([['schema_version', 1], ['thinking_tier', 'ultra']] as [$field, $value]) {
            $candidate = $definition->definition;
            $candidate[$field] = $value;
            try {
                app(WorkflowRepairService::class)->propose($definition, $source, $candidate, (int) $definition->version);
                $this->fail('Repair changed execution policy.');
            } catch (HttpException $failure) {
                $this->assertSame('workflow_repair_execution_policy_changed', $failure->getMessage());
            }
        }
    }

    public function test_repair_policy_does_not_opt_in_existing_workflows_and_limits_copies(): void
    {
        [$definition, $device, $case, $repair, $source] = $this->fixture();
        $service = app(WorkflowRepairService::class);
        $service->propose($definition, $source, $repair->definition, 1);
        try {
            $service->propose($definition, $source, $repair->definition, 1);
            $this->fail('Third repair was accepted.');
        } catch (HttpException $error) {
            $this->assertSame('workflow_repair_budget_exhausted', $error->getMessage());
        }
        $this->assertSame(2, WorkflowRepairRevision::count());
        $definition->update(['repair_policy' => null]);
        $this->expectExceptionMessage('workflow_repair_not_opted_in');
        $service->propose($definition, $source, $repair->definition, 1);
    }

    public function test_unchanged_binding_cannot_change_provider_through_repaired_data(): void
    {
        [$definition] = $this->fixture();
        $definition->update(['definition' => ['schema_version' => 2, 'steps' => [
            ['key' => 'config', 'type' => 'data.collect', 'payload' => ['items' => ['local']]],
            ['key' => 'answer', 'type' => 'llm.text', 'depends_on' => ['config'], 'payload' => ['instruction' => 'Return a value', 'inference' => ['$ref' => 'steps.config.data.0']]],
        ]]]);
        $source = app(WorkflowService::class)->createRun($definition);
        $source->update(['status' => 'failed']);
        $candidate = $definition->definition;
        $candidate['steps'][0]['payload']['items'] = ['external'];
        $this->expectExceptionMessage('workflow_repair_dynamic_scope_requires_review');
        app(WorkflowRepairService::class)->propose($definition, $source, $candidate, (int) $definition->version);
    }

    public function test_changed_permissions_assertions_and_code_cannot_bypass_authorized_scope(): void
    {
        [$definition, $device, $case, $repair, $source] = $this->fixture();
        $candidate = $repair->definition;
        $candidate['steps'][0]['payload']['device_id'] = 'another';
        $this->expectExceptionMessage('workflow_repair_rights_or_cost_scope_changed');
        app(WorkflowRepairService::class)->propose($definition, $source, $candidate, 1);
    }

    public function test_simulated_device_effects_require_fixtures_and_never_create_device_jobs(): void
    {
        $user = User::factory()->create();
        $definition = WorkflowDefinition::create(['user_id' => $user->id, 'name' => 'Simulation', 'version' => 1, 'status' => 'active', 'definition' => ['schema_version' => 2, 'steps' => [
            ['key' => 'script', 'type' => 'node.run', 'max_attempts' => 1, 'payload' => ['code' => 'throw new Error("must not execute")']],
        ]]]);
        $tests = app(WorkflowTestService::class);
        $spec = ['fixtures' => ['script' => ['data' => ['ok' => true]]], 'assertions' => [['step_key' => 'script', 'path' => 'data.ok', 'operator' => 'eq', 'value' => true]]];
        $case = $tests->createCase($definition, ['name' => 'Fake script', 'specification' => $spec]);
        $this->assertSame('passed', $tests->start($definition, $case, 'simulation', null)->status);
        $this->assertDatabaseCount('device_jobs', 0);
        $spec['fixtures'] = [];
        $missing = $tests->createCase($definition, ['name' => 'Missing script fixture', 'specification' => $spec]);
        $this->assertSame('failed', $tests->start($definition, $missing, 'simulation', null)->status);
        $this->assertDatabaseCount('device_jobs', 0);
    }

    public function test_test_api_operations_are_idempotent_and_cross_owner_access_is_hidden(): void
    {
        [$definition, $device] = $this->fixture();
        $key = ApiKey::mint(['user_id' => $definition->user_id, 'name' => 'Tests', 'abilities' => ['brain.read', 'brain.write'], 'active' => true]);
        $this->withHeader('X-Api-Key', $key['plain']);
        $body = ['operation_id' => (string) Str::uuid(), 'name' => 'Created once', 'specification' => ['assertions' => [['step_key' => 'select', 'path' => 'data.0', 'value' => 2]]]];
        $id = $this->postJson('/api/v1/workflows/'.$definition->id.'/test-cases', $body)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/workflows/'.$definition->id.'/test-cases', $body)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/workflows/'.$definition->id.'/test-cases', array_merge($body, ['name' => 'Conflicting']))->assertConflict();
        $foreign = ApiKey::mint(['user_id' => User::factory()->create()->id, 'name' => 'Other', 'abilities' => ['brain.read'], 'active' => true]);
        $this->withHeader('X-Api-Key', $foreign['plain'])->getJson('/api/v1/workflows/'.$definition->id.'/test-cases')->assertNotFound();
    }
}
