<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\User;
use App\Models\WorkflowAutomationGrant;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\AutomationGrantService;
use App\Services\WorkflowDeviceCapabilities;
use App\Services\WorkflowRepairService;
use App\Services\WorkflowService;
use App\Services\WorkflowTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowRepairGrantSuccessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_changed_code_gets_an_exact_test_only_grant_then_an_authorized_successor(): void
    {
        $private = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($private, $key);
        Config::set('luczor.device_jobs.private_key', $key);
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_id' => 'repair-device', 'name' => 'Fixture', 'status' => 'online']);
        app(WorkflowDeviceCapabilities::class)->report($device, ['schema_version' => 1, 'environment_hash' => str_repeat('c', 64), 'tasks' => [
            ['type' => 'node.run', 'version' => 1, 'adapter' => 'windows.user.node', 'available' => true],
        ]]);
        $oldCode = 'console.log(JSON.stringify({value: 1}))';
        $newCode = 'console.log(JSON.stringify({value: 2}))';
        $definition = WorkflowDefinition::create(['user_id' => $user->id, 'name' => 'Script repair', 'version' => 1, 'status' => 'active', 'definition' => ['schema_version' => 2, 'steps' => [
            ['key' => 'script', 'type' => 'node.run', 'max_attempts' => 1, 'payload' => ['code' => $oldCode]],
        ]]]);
        $grants = app(AutomationGrantService::class);
        $base = $grants->configure($definition, ['operation_id' => (string) Str::uuid(), 'device_id' => $device->device_id, 'project_external_id' => null,
            'status' => 'active', 'approved_revision' => 1, 'local_approved' => true, 'root_path' => 'C:/fixture', 'allowed_tasks' => ['node.run'],
            'allowed_input_sources' => [], 'allowed_output_keys' => ['script'], 'script_hashes' => ['script' => hash('sha256', $oldCode)]], $user->id, $device->device_id);
        $workflows = app(WorkflowService::class);
        $source = $workflows->createRun($definition, [], null, false, ['device_id' => $device->device_id, 'automatic' => true]);
        $workflows->advance($source);
        $job = DeviceJob::sole();
        $job->update(['status' => 'failed', 'error' => 'Synthetic adapter failure']);
        $workflows->syncDeviceJobSteps($source);
        $this->assertSame('failed', $source->fresh()->status);
        $tests = app(WorkflowTestService::class);
        $case = $tests->createCase($definition, ['name' => 'Output contract', 'specification' => ['real_test_authorized' => true, 'device_id' => $device->device_id,
            'fixtures' => ['script' => ['data' => ['value' => 2]]], 'assertions' => [['step_key' => 'script', 'path' => 'data.value', 'operator' => 'eq', 'value' => 2]]]]);
        $repairs = app(WorkflowRepairService::class);
        $repairs->configure($definition, ['enabled' => true, 'auto_activate' => false, 'allow_script_repair' => true, 'test_case_id' => $case->id,
            'expected_version' => 1, 'local_approved' => true, 'device_id' => $device->device_id], $device->device_id);
        $candidate = $definition->definition;
        $candidate['steps'][0]['payload']['code'] = $newCode;
        $repair = $repairs->propose($definition->fresh(), $source->fresh(), $candidate, 1);
        foreach (['definition', 'simulation'] as $mode) {
            $this->assertSame('passed', $tests->start($definition->fresh(), $case, $mode, $device->fresh(), $repair)->status);
        }
        $real = $tests->start($definition->fresh(), $case, 'real', $device->fresh(), $repair);
        $this->assertSame('running', $real->status);
        $testing = WorkflowAutomationGrant::where('status', 'testing')->sole();
        $this->assertSame($base->id, $grants->current($definition)->id);
        $this->assertSame(hash('sha256', $newCode), $testing->config['script_hashes'][$definition->id.':script']);
        $this->assertSame(hash('sha256', $oldCode), $testing->config['script_hashes']['script']);
        $this->assertSame($real->id, $testing->config['test_binding']['test_evidence_id']);
        $forged = clone $source;
        $forged->context = ['_execution' => ['automatic' => true, 'grant' => $testing->toArray(), '_test_run_id' => $testing->config['test_binding']['run']]];
        try {
            $grants->authorizeTask($forged, 'node.run', ['code' => $newCode]);
            $this->fail('A testing grant escaped its real test run.');
        } catch (HttpException $error) {
            $this->assertSame('workflow_testing_grant_binding_invalid', $error->getMessage());
        }
        $realRun = WorkflowRun::findOrFail($real->workflow_run_id);
        $realStep = $realRun->steps()->sole();
        $realJob = DeviceJob::where('workflow_execution_id', $realStep->execution_id)->sole();
        $this->assertSame('real', $realJob->payload['workflow']['test_mode']);
        $this->assertSame($realRun->public_id, $realJob->payload['workflow']['test_run']);
        $this->assertSame($testing->config['test_binding'], $realJob->payload['workflow']['test_binding']);
        $realJob->update(['status' => 'completed', 'started_at' => now(), 'finished_at' => now(), 'result' => ['data' => ['value' => 2]]]);
        $workflows->syncDeviceJobSteps($realRun);
        $this->assertSame('passed', $real->fresh()->status);
        $this->assertSame('proposed', $repair->fresh()->status);
        $snapshot = $realRun->definition_snapshot;
        $realRun->update(['definition_snapshot' => array_merge($snapshot, ['graph_path' => ['workflow:wrong-occurrence']])]);
        try {
            $repairs->activate($repair);
            $this->fail('Code from a different frozen graph occurrence was accepted.');
        } catch (HttpException $error) {
            $this->assertSame('workflow_repair_changed_script_not_real_tested', $error->getMessage());
        }
        $realRun->update(['definition_snapshot' => $snapshot]);
        $repairs->activate($repair);
        $this->assertSame('activated', $repair->fresh()->status);
        $current = $grants->current($definition);
        $this->assertSame($testing->id, $current->id);
        $this->assertSame('active', $current->status);
        $this->assertSame($base->id, $current->predecessor_grant_id);
        $this->assertArrayNotHasKey('test_binding', $current->config);
        $this->assertSame(hash('sha256', $oldCode), $base->fresh()->config['script_hashes']['script']);
        $this->assertSame(2, $definition->fresh()->version);
        $this->assertSame($base->id, $grants->authorizeTask($source, 'node.run', ['code' => $oldCode])['id']);
        $latest = $current;
        for ($index = 0; $index < 4; $index++) {
            $successor = $latest->replicate();
            $successor->predecessor_grant_id = $latest->id;
            $successor->operation_id = (string) Str::uuid();
            $successor->save();
            $latest = $successor;
        }
        $this->assertSame($base->id, $grants->authorizeTask($source, 'node.run', ['code' => $oldCode])['id']);
        $current->update(['status' => 'revoked']);
        $this->expectExceptionMessage('Automation approval was revoked or replaced.');
        $grants->authorizeTask($source, 'node.run', ['code' => $oldCode]);
    }
}
