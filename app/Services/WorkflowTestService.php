<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRepairRevision;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowTestCase;
use App\Models\WorkflowTestEvidence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkflowTestService
{
    public function serialize(WorkflowTestEvidence $evidence): array
    {
        $definition = WorkflowDefinition::findOrFail($evidence->workflow_definition_id);
        $repair = $evidence->repair_revision_id ? WorkflowRepairRevision::find($evidence->repair_revision_id) : null;
        $run = $evidence->workflow_run_id ? WorkflowRun::find($evidence->workflow_run_id) : null;

        return $this->serializeContext($evidence, $definition, $repair, $run);
    }

    public function serializeMany(Collection $evidence): array
    {
        $definitions = WorkflowDefinition::whereIn('id', $evidence->pluck('workflow_definition_id')->unique())->get()->keyBy('id');
        $repairs = WorkflowRepairRevision::whereIn('id', $evidence->pluck('repair_revision_id')->filter()->unique())->get()->keyBy('id');
        $runs = WorkflowRun::whereIn('id', $evidence->pluck('workflow_run_id')->filter()->unique())->get()->keyBy('id');

        return $evidence->map(fn (WorkflowTestEvidence $item) => $this->serializeContext($item, $definitions->get($item->workflow_definition_id), $repairs->get($item->repair_revision_id), $runs->get($item->workflow_run_id)))->all();
    }

    private function serializeContext(WorkflowTestEvidence $evidence, WorkflowDefinition $definition, ?WorkflowRepairRevision $repair, ?WorkflowRun $run): array
    {
        $policy = $definition->repair_policy;

        return array_merge($evidence->toArray(), [
            'run_public_id' => $run?->public_id, 'device_id' => $evidence->snapshot['device_id'] ?? null,
            'device_environment_hash' => $evidence->snapshot['device_environment_hash'] ?? null,
            'definition_version' => $evidence->snapshot['workflow']['version'] ?? null,
            'repair_status' => $repair?->status, 'repair_policy' => $policy,
            'repair_policy_hash' => $policy ? self::hash($policy) : null,
        ]);
    }

    public function createCase(WorkflowDefinition $definition, array $data): WorkflowTestCase
    {
        $spec = $data['specification'];
        abort_unless(strlen(AutomationGrantService::canonicalJson($spec)) <= 150000, 422, 'workflow_fixture_too_large');
        abort_unless(is_array($spec['assertions'] ?? null) && count($spec['assertions']) > 0 && count($spec['assertions']) <= 100, 422, 'workflow_test_assertions_required');
        app(WorkflowDataTasks::class)->validateAssertions($spec['assertions']);
        foreach ($spec['assertions'] as $assertion) {
            abort_unless(is_array($assertion) && is_string($assertion['step_key'] ?? null), 422, 'workflow_test_assertion_step_required');
        }
        abort_unless(is_array($spec['input'] ?? []) && is_array($spec['fixtures'] ?? []), 422, 'workflow_test_fixture_invalid');

        return WorkflowTestCase::create([
            'user_id' => $definition->user_id, 'workflow_definition_id' => $definition->id,
            'name' => $data['name'], 'specification' => $spec,
            'assertions_hash' => self::hash($spec['assertions']), 'fixture_hash' => self::hash($spec),
        ]);
    }

    public function start(WorkflowDefinition $definition, WorkflowTestCase $case, string $mode, ?Device $device, ?WorkflowRepairRevision $repair = null): WorkflowTestEvidence
    {
        abort_unless(in_array($mode, ['definition', 'simulation', 'real'], true), 422, 'workflow_test_mode_invalid');
        abort_unless((int) $case->workflow_definition_id === (int) $definition->id && (int) $case->user_id === (int) $definition->user_id, 404);
        abort_unless(! $repair || ((int) $repair->workflow_definition_id === (int) $definition->id && $repair->status === 'proposed'), 409, 'workflow_repair_unavailable');
        $snapshot = $repair !== null ? $repair->snapshot : app(WorkflowAuthoringService::class)->snapshot($definition, (int) $definition->user_id, $definition->project_id);
        $environment = $this->environment($device);
        $hash = self::hash($snapshot);
        $spec = $case->specification;
        foreach ($spec['assertions'] as $assertion) {
            abort_unless(in_array($assertion['step_key'], array_column($snapshot['definition']['steps'], 'key'), true), 422, 'workflow_test_assertion_step_missing');
        }
        if ($mode === 'real') {
            abort_unless(($spec['real_test_authorized'] ?? false) === true, 409, 'workflow_real_test_authorization_required');
            abort_if($device && ($spec['device_id'] ?? null) !== $device->device_id, 409, 'workflow_real_test_device_mismatch');
            foreach (self::steps($snapshot) as $step) {
                if (WorkflowTaskCatalog::isClientTask($step['type'])) {
                    abort_unless($device && app(WorkflowDeviceCapabilities::class)->admission($device, $step['type'], $step['version'] ?? 1)['ready'], 409, 'workflow_real_test_capability_missing');
                }
            }
        }
        $evidence = WorkflowTestEvidence::create([
            'user_id' => $definition->user_id, 'workflow_definition_id' => $definition->id, 'workflow_test_case_id' => $case->id,
            'repair_revision_id' => $repair?->id, 'mode' => $mode, 'status' => 'running',
            'definition_hash' => $hash, 'code_hash' => self::codeHash($snapshot), 'assertions_hash' => $case->assertions_hash,
            'fixture_hash' => $case->fixture_hash, 'environment_hash' => $environment,
            'snapshot' => ['workflow' => $snapshot, 'test_case' => $spec, 'device_id' => $device?->device_id,
                'device_environment_hash' => $device?->meta['workflow_capabilities']['environment_hash'] ?? null],
        ]);
        if ($mode === 'definition') {
            app(WorkflowDefinitionValidator::class)->validate($snapshot['definition']);
            $evidence->update(['status' => 'passed', 'result' => ['definition_valid' => true], 'finished_at' => now()]);

            return $evidence;
        }
        $context = ['_snapshot' => $snapshot, '_test_mode' => $mode, '_test_evidence_id' => $evidence->id,
            'device_id' => $device?->device_id, 'strict_target' => true];
        $testingGrant = null;
        if ($mode === 'real' && $repair && ($definition->repair_policy['grant_id'] ?? null)) {
            // A copied repair may execute changed code only after an explicit bounded successor grant exists.
            $context['automatic'] = true;
            $testingGrant = app(WorkflowRepairService::class)->testGrant($repair, $definition, (int) $evidence->id);
            $context['grant'] = $testingGrant->toArray();
        }
        $run = app(WorkflowService::class)->createRun($definition, $spec['input'] ?? [], null, $mode === 'simulation', $context);
        $evidence->update(['workflow_run_id' => $run->id]);
        if ($testingGrant) {
            $config = $testingGrant->config;
            $config['test_binding'] = ['run' => $run->public_id, 'test_evidence_id' => $evidence->id, 'repair_revision_id' => $repair->id,
                'definition_hash' => $evidence->definition_hash, 'code_hash' => $evidence->code_hash, 'environment_hash' => $evidence->environment_hash,
                'assertions_hash' => $evidence->assertions_hash, 'fixture_hash' => $evidence->fixture_hash];
            $testingGrant->update(['config' => $config, 'scope_hash' => AutomationGrantService::configHash($config)]);
            $context = $run->context;
            $context['_execution']['grant'] = $testingGrant->toArray();
            $context['_execution']['_test_run_id'] = $run->public_id;
            $run->update(['context' => $context]);
        }
        app(WorkflowService::class)->advance($run);

        return $this->refresh($evidence->fresh());
    }

    public function simulation(WorkflowStep $step): array
    {
        $id = $step->run->context['_execution']['_test_evidence_id'] ?? null;
        $evidence = $id ? WorkflowTestEvidence::find($id) : null;
        abort_unless($evidence && $evidence->mode === 'simulation' && (int) $evidence->user_id === (int) $step->user_id, 409, 'workflow_simulation_evidence_missing');
        $fixtures = $evidence->snapshot['test_case']['fixtures'] ?? [];
        abort_unless(array_key_exists($step->step_key, $fixtures) && is_array($fixtures[$step->step_key]), 422, 'workflow_simulation_fixture_missing:'.$step->step_key);

        return $fixtures[$step->step_key];
    }

    public function refresh(WorkflowTestEvidence $evidence): WorkflowTestEvidence
    {
        return DB::transaction(function () use ($evidence) {
            $evidence = WorkflowTestEvidence::query()->lockForUpdate()->findOrFail($evidence->id);
            if ($evidence->status !== 'running' || ! $evidence->workflow_run_id) {
                return $evidence;
            }
            $run = WorkflowRun::findOrFail($evidence->workflow_run_id);
            if (! in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
                return $evidence;
            }
            $passed = $run->status === 'completed' && hash_equals($evidence->definition_hash, self::hash($run->definition_snapshot));
            $checks = [];
            $error = $passed ? null : 'workflow_test_run_'.$run->status;
            try {
                abort_unless($passed, 422, $error);
                if ($evidence->mode === 'real') {
                    abort_if($run->sandbox || $run->test_mode !== 'real', 422, 'workflow_real_test_was_simulated');
                    $device = $this->device($evidence);
                    abort_unless(hash_equals($evidence->environment_hash, $this->environment($device)), 422, 'workflow_test_environment_changed');
                }
                foreach ($evidence->snapshot['test_case']['assertions'] as $assertion) {
                    $step = $run->steps()->where('step_key', $assertion['step_key'])->where('status', 'completed')->first();
                    abort_unless($step !== null, 422, 'workflow_test_step_not_completed');
                    if ($evidence->mode === 'real' && in_array($assertion['kind'] ?? '', ['file', 'browser'], true)) {
                        abort_unless(str_starts_with($step->type, $assertion['kind'].'.'), 422, 'workflow_test_real_provenance_missing');
                    }
                    if ($evidence->mode === 'real' && WorkflowTaskCatalog::isClientTask($step->type)) {
                        $job = DeviceJob::where('public_id', $step->external_run_id)->where('status', 'completed')->where('workflow_execution_id', $step->execution_id)->first();
                        abort_unless($job !== null, 422, 'workflow_real_device_result_missing');
                    }
                    $checks[] = app(WorkflowDataTasks::class)->assertions($step->output, [$assertion]);
                }
            } catch (\Throwable $failure) {
                $passed = false;
                $error = mb_substr($failure->getMessage(), 0, 300);
            }
            $evidence->update(['status' => $passed ? 'passed' : 'failed', 'finished_at' => now(), 'result' => ['checks' => $checks, 'error' => $error]]);
            if ($passed && $evidence->mode === 'real' && $evidence->repair_revision_id) {
                $repair = WorkflowRepairRevision::findOrFail($evidence->repair_revision_id);
                $definition = WorkflowDefinition::findOrFail($repair->workflow_definition_id);
                if ($definition->repair_policy['auto_activate'] ?? false) {
                    try {
                        app(WorkflowRepairService::class)->activate($repair, true);
                    } catch (\Throwable $failure) {
                        $evidence->update(['result' => array_merge($evidence->result, ['activation_error' => mb_substr($failure->getMessage(), 0, 300)])]);
                    }
                }
            }

            return $evidence;
        });
    }

    public function environment(?Device $device): string
    {
        if ($device) {
            abort_unless(! $device->revoked_at && preg_match('/^[a-f0-9]{64}$/', $device->meta['workflow_capabilities']['environment_hash'] ?? ''), 409, 'workflow_environment_unavailable');
        }

        $sources = [];
        foreach (['WorkflowStepExecutor', 'WorkflowDataTasks', 'WorkflowStructuredControl', 'WorkflowBindings', 'WorkflowSchema', 'WorkflowSnapshotIdentity', 'WorkflowBudgetService', 'WorkflowBoundaryStop', 'WorkflowTaskContracts', 'DeviceToolPolicy', 'DeviceJobService', 'WorkflowDefinitionValidator', 'WorkflowResultNormalizer', 'WorkflowService'] as $service) {
            $sources[$service] = hash_file('sha256', app_path('Services/'.$service.'.php'));
        }

        return self::hash(['server_contract' => 2, 'server_sources' => $sources, 'catalog' => WorkflowTaskCatalog::options(), 'device' => $device?->device_id, 'device_environment' => $device?->meta['workflow_capabilities']['environment_hash'] ?? null]);
    }

    private function device(WorkflowTestEvidence $evidence): ?Device
    {
        $id = $evidence->snapshot['device_id'] ?? null;

        return $id ? Device::where('user_id', $evidence->user_id)->where('device_id', $id)->firstOrFail() : null;
    }

    public static function hash(array $data): string
    {
        return hash('sha256', AutomationGrantService::canonicalJson($data));
    }

    public static function codeHash(array $snapshot): string
    {
        $codes = [];
        foreach (self::steps($snapshot) as $step) {
            if (isset($step['payload']['code'])) {
                $codes[] = ['definition_id' => $step['_definition_id'], 'graph_path' => $step['_graph_path'], 'key' => $step['key'], 'type' => $step['type'], 'version' => $step['version'] ?? 1, 'code' => $step['payload']['code']];
            }
        }

        return self::hash($codes);
    }

    public static function steps(array $snapshot): array
    {
        return WorkflowSnapshotIdentity::steps($snapshot);
    }
}
