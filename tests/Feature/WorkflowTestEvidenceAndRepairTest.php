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
