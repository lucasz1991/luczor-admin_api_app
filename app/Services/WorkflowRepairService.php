<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\WorkflowAutomationGrant;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRepairRevision;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowTestCase;
use App\Models\WorkflowTestEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowRepairService
{
    public function configure(WorkflowDefinition $definition, array $data, string $ownDeviceId): array
    {
        abort_unless(($data['local_approved'] ?? false) === true && ($data['device_id'] ?? null) === $ownDeviceId, 403, 'workflow_repair_local_approval_required');
        abort_unless(Device::where('user_id', $definition->user_id)->where('device_id', $ownDeviceId)->whereNull('revoked_at')->exists(), 403, 'workflow_repair_device_unavailable');
        abort_unless((int) $data['expected_version'] === (int) $definition->version, 409, 'workflow_version_conflict');
        $case = WorkflowTestCase::whereKey($data['test_case_id'])->where('workflow_definition_id', $definition->id)->where('user_id', $definition->user_id)->firstOrFail();
        abort_unless(($case->specification['real_test_authorized'] ?? false) === true, 409, 'workflow_repair_real_test_case_required');
        $grant = app(AutomationGrantService::class)->current($definition);
        $policy = [
            'enabled' => $data['enabled'], 'auto_activate' => $data['auto_activate'] ?? false,
            'allow_script_repair' => $data['allow_script_repair'] ?? false,
            'device_id' => $ownDeviceId, 'test_case_id' => $case->id,
            'assertions_hash' => $case->assertions_hash, 'fixture_hash' => $case->fixture_hash,
            'max_repairs' => min(2, $data['max_repairs'] ?? 2),
            'grant_id' => $grant?->id, 'scope_hash' => $grant?->scope_hash,
            'authorized_at' => now()->toIso8601String(),
        ];
        $definition->update(['repair_policy' => $policy]);

        return $policy;
    }

    public function propose(WorkflowDefinition $definition, WorkflowRun $source, array $candidate, int $expectedVersion): WorkflowRepairRevision
    {
        return DB::transaction(function () use ($definition, $source, $candidate, $expectedVersion) {
            $definition = WorkflowDefinition::query()->lockForUpdate()->findOrFail($definition->id);
            abort_unless($expectedVersion === (int) $definition->version && ! $definition->is_edit_locked, 409, 'workflow_version_conflict_or_locked');
            abort_unless((int) $source->workflow_definition_id === (int) $definition->id && (int) $source->user_id === (int) $definition->user_id, 404);
            abort_unless(in_array($source->status, ['failed', 'cancelled'], true), 409, 'workflow_repair_requires_failed_run');
            abort_unless($source->definition_version === (int) $definition->version, 409, 'workflow_repair_source_revision_stale');
            $policy = $definition->repair_policy ?? [];
            abort_unless(($policy['enabled'] ?? false) === true, 409, 'workflow_repair_not_opted_in');
            $rootSource = $source->root_workflow_run_id ?: $source->id;
            $count = WorkflowRepairRevision::where('source_run_id', $rootSource)->count();
            abort_if($count >= min($policy['max_repairs'] ?? 2, $source->budgets['max_repairs'] ?? 2), 409, 'workflow_repair_budget_exhausted');
            app(WorkflowAuthoringService::class)->validate((int) $definition->user_id, $candidate, $definition->project_id, $definition->id);
            $copy = clone $definition;
            $copy->definition = $candidate;
            $snapshot = app(WorkflowAuthoringService::class)->snapshot($copy, (int) $definition->user_id, $definition->project_id);
            $this->assertScope($definition, $snapshot, $source->definition_snapshot);

            return WorkflowRepairRevision::create([
                'user_id' => $definition->user_id, 'workflow_definition_id' => $definition->id,
                'source_run_id' => $rootSource, 'base_version' => $expectedVersion, 'definition' => $candidate, 'snapshot' => $snapshot,
                'definition_hash' => WorkflowTestService::hash($snapshot), 'code_hash' => WorkflowTestService::codeHash($snapshot),
                'scope' => ['policy' => $policy, 'source_snapshot_hash' => WorkflowTestService::hash($source->definition_snapshot)], 'status' => 'proposed',
            ]);
        });
    }

    /** Exact candidate test grant, recorded separately and never selected by normal automation. */
    public function testGrant(WorkflowRepairRevision $repair, WorkflowDefinition $definition, ?int $evidenceId = null): WorkflowAutomationGrant
    {
        $policy = $definition->repair_policy;
        abort_unless($policy && $policy === ($repair->scope['policy'] ?? null), 409, 'workflow_repair_authorization_changed');
        $base = app(AutomationGrantService::class)->current($definition);
        abort_unless($base && $base->status === 'active' && (int) $base->id === (int) ($policy['grant_id'] ?? 0)
            && hash_equals($base->scope_hash, $policy['scope_hash'] ?? ''), 409, 'workflow_repair_grant_unavailable');
        $existing = WorkflowAutomationGrant::where('repair_revision_id', $repair->id)->where('status', 'testing')->where('test_evidence_id', $evidenceId)->first();
        if ($existing) {
            return $existing;
        }
        $config = $base->config;
        $config['script_hashes'] = $this->scriptHashes($repair->snapshot, $config['script_hashes'] ?? []);
        $grant = new WorkflowAutomationGrant(['config' => $config, 'device_id' => $base->device_id]);
        app(AutomationGrantService::class)->assertGraph($grant, $repair->snapshot);

        return WorkflowAutomationGrant::create([
            'user_id' => $base->user_id, 'project_id' => $base->project_id, 'workflow_definition_id' => $base->workflow_definition_id,
            'device_id' => $base->device_id, 'approved_revision' => $repair->base_version, 'status' => 'testing',
            'config' => $config, 'scope_hash' => AutomationGrantService::configHash($config), 'operation_id' => (string) Str::uuid(),
            'predecessor_grant_id' => $base->id, 'repair_revision_id' => $repair->id,
            'test_evidence_id' => $evidenceId,
        ]);
    }

    public function activate(WorkflowRepairRevision $candidate, bool $automatic = false): WorkflowRepairRevision
    {
        return DB::transaction(function () use ($candidate, $automatic) {
            $definition = WorkflowDefinition::query()->lockForUpdate()->findOrFail($candidate->workflow_definition_id);
            $repair = WorkflowRepairRevision::query()->lockForUpdate()->findOrFail($candidate->id);
            if ($repair->status === 'activated') {
                return $repair;
            }
            $policy = $definition->repair_policy;
            abort_unless($repair->status === 'proposed' && $policy && ($policy['enabled'] ?? false)
                && $policy === ($repair->scope['policy'] ?? null), 409, 'workflow_repair_authorization_changed');
            abort_if($automatic && ! ($policy['auto_activate'] ?? false), 409, 'workflow_repair_autoactivation_not_authorized');
            abort_unless((int) $definition->version === $repair->base_version && ! $definition->is_edit_locked, 409, 'workflow_repair_stale_revision');
            abort_unless(hash_equals($repair->definition_hash, WorkflowTestService::hash($repair->snapshot))
                && hash_equals($repair->code_hash, WorkflowTestService::codeHash($repair->snapshot)), 409, 'workflow_repair_hash_mismatch');
            $copy = clone $definition;
            $copy->definition = $repair->definition;
            $currentSnapshot = app(WorkflowAuthoringService::class)->snapshot($copy, (int) $definition->user_id, $definition->project_id);
            abort_unless(hash_equals($repair->definition_hash, WorkflowTestService::hash($currentSnapshot)), 409, 'workflow_repair_dependencies_changed');
            $device = Device::where('user_id', $definition->user_id)->where('device_id', $policy['device_id'])->whereNull('revoked_at')->firstOrFail();
            $environment = app(WorkflowTestService::class)->environment($device);
            $evidence = null;
            foreach (['definition', 'simulation', 'real'] as $mode) {
                $evidence = WorkflowTestEvidence::where('repair_revision_id', $repair->id)->where('mode', $mode)->where('status', 'passed')
                    ->where('definition_hash', $repair->definition_hash)->where('code_hash', $repair->code_hash)
                    ->where('assertions_hash', $policy['assertions_hash'])->where('fixture_hash', $policy['fixture_hash'])
                    ->where('workflow_test_case_id', $policy['test_case_id'])->where('environment_hash', $environment)->latest('id')->first();
                abort_unless($evidence !== null, 409, 'workflow_repair_test_evidence_missing:'.$mode);
            }
            $source = WorkflowRun::findOrFail($repair->source_run_id);
            $this->assertScope($definition, $repair->snapshot, $source->definition_snapshot);
            $this->assertChangedScriptsExecuted($source->definition_snapshot, $repair->snapshot, $evidence);
            $grant = null;
            if ($policy['grant_id']) {
                $grant = $this->testGrant($repair, $definition, (int) $evidence->id);
            }
            $definition->update(['definition' => $repair->definition, 'version' => $repair->base_version + 1, 'change_summary' => 'Reparatur nach gespeicherten Definition-, Simulations- und Realtests.']);
            if ($grant) {
                $config = $grant->config;
                unset($config['test_binding']);
                $config['approved_revision'] = (int) $definition->version;
                $grant->update(['status' => 'active', 'approved_revision' => $definition->version, 'config' => $config,
                    'scope_hash' => AutomationGrantService::configHash($config), 'test_evidence_id' => $evidence->id]);
                $policy['grant_id'] = $grant->id;
                $policy['scope_hash'] = $grant->scope_hash;
                $definition->update(['repair_policy' => $policy]);
            }
            $repair->update(['status' => 'activated', 'activated_version' => $definition->version]);

            return $repair;
        }, 3);
    }

    private function assertScope(WorkflowDefinition $definition, array $candidate, array $source): void
    {
        $policy = $definition->repair_policy ?? [];
        $old = WorkflowTestService::steps($source);
        $new = WorkflowTestService::steps($candidate);
        // Type/key identities and every permission/cost-bearing field are fixed. Only code and declarative data/conditions may be repaired.
        $oldScope = $this->scope($old);
        abort_unless($oldScope === $this->scope($new), 409, 'workflow_repair_rights_or_cost_scope_changed');
        foreach ($new as $step) {
            if (WorkflowTaskCatalog::isClientTask($step['type'])) {
                $protected = $step['payload'] ?? [];
                unset($protected['code'], $protected['instruction'], $protected['input_bindings']);
                // A fixed reference is not a fixed right: editable data/control inputs can change its eventual provider, path or model.
                abort_if($this->containsBinding($protected), 409, 'workflow_repair_dynamic_scope_requires_review');
            }
        }
        $oldAssertions = array_values(array_filter($old, fn ($step) => ($step['type'] ?? '') === 'test.assert'));
        $newAssertions = array_values(array_filter($new, fn ($step) => ($step['type'] ?? '') === 'test.assert'));
        abort_unless(WorkflowTestService::hash($oldAssertions) === WorkflowTestService::hash($newAssertions), 409, 'workflow_repair_assertions_changed');
        if (! ($policy['allow_script_repair'] ?? false)) {
            abort_unless(WorkflowTestService::codeHash($source) === WorkflowTestService::codeHash($candidate), 409, 'workflow_repair_code_not_authorized');
        }
        $before = WorkflowBudgetService::policy($source['definition']);
        $after = WorkflowBudgetService::policy($candidate['definition']);
        foreach ($before as $key => $limit) {
            abort_if($after[$key] > $limit, 409, 'workflow_repair_budget_expansion');
        }
        $oldBudgets = [];
        foreach (WorkflowSnapshotIdentity::entries($source) as $entry) {
            $oldBudgets[WorkflowTestService::hash($entry['graph_path'])] = ['budgets' => WorkflowBudgetService::policy($entry['definition']),
                'schema_version' => $entry['definition']['schema_version'] ?? 1, 'thinking_tier' => $entry['definition']['thinking_tier'] ?? 'balanced'];
        }
        foreach (WorkflowSnapshotIdentity::entries($candidate) as $entry) {
            $path = WorkflowTestService::hash($entry['graph_path']);
            abort_unless(isset($oldBudgets[$path]), 409, 'workflow_repair_nested_scope_changed');
            abort_unless(($entry['definition']['schema_version'] ?? 1) === $oldBudgets[$path]['schema_version']
                && ($entry['definition']['thinking_tier'] ?? 'balanced') === $oldBudgets[$path]['thinking_tier'], 409, 'workflow_repair_execution_policy_changed');
            foreach (WorkflowBudgetService::policy($entry['definition']) as $key => $limit) {
                abort_if($limit > $oldBudgets[$path]['budgets'][$key], 409, 'workflow_repair_nested_budget_expansion');
            }
        }
    }

    private function scope(array $steps): array
    {
        $scope = [];
        foreach ($steps as $step) {
            $payload = $step['payload'] ?? [];
            if (WorkflowTaskCatalog::isClientTask($step['type'])) {
                unset($payload['code']);
            } elseif (str_starts_with($step['type'], 'data.') || in_array($step['type'], ['condition', 'control.foreach', 'control.until', 'control.parallel'], true)) {
                $payload = array_intersect_key($payload, array_flip(['device_id', 'project_id', 'root_path', 'workflow_definition_id', 'max_iterations']));
            }
            $scope[] = ['definition_id' => $step['_definition_id'], 'graph_path' => $step['_graph_path'], 'key' => $step['key'], 'type' => $step['type'], 'version' => $step['version'] ?? 1, 'approval' => $step['requires_approval'] ?? null, 'payload' => $payload];
        }

        return $scope;
    }

    private function containsBinding(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        if (array_key_exists('$ref', $value)) {
            return true;
        }
        foreach ($value as $item) {
            if ($this->containsBinding($item)) {
                return true;
            }
        }

        return false;
    }

    private function scriptHashes(array $snapshot, array $existing): array
    {
        $seen = [];
        foreach (WorkflowTestService::steps($snapshot) as $step) {
            if (in_array($step['type'], ['node.run', 'python.run'], true)) {
                abort_unless(is_string($step['payload']['code'] ?? null), 409, 'workflow_repair_dynamic_script_not_authorized');
                $scope = $step['_definition_id'].':'.$step['key'];
                $hash = hash('sha256', $step['payload']['code']);
                abort_if(isset($seen[$scope]) && $seen[$scope] !== $hash, 409, 'workflow_repair_ambiguous_script_scope');
                $seen[$scope] = $hash;
                $existing[$scope] = $hash;
            }
        }

        return $existing;
    }

    private function assertChangedScriptsExecuted(array $source, array $candidate, WorkflowTestEvidence $evidence): void
    {
        $old = [];
        foreach (WorkflowTestService::steps($source) as $step) {
            if (is_string($step['payload']['code'] ?? null)) {
                $old[] = [$step['_definition_id'], $step['_graph_path'], $step['type'], $step['key'], hash('sha256', $step['payload']['code'])];
            }
        }
        $runs = WorkflowRun::where('id', $evidence->workflow_run_id)->orWhere('root_workflow_run_id', $evidence->workflow_run_id)->pluck('id');
        foreach (WorkflowTestService::steps($candidate) as $step) {
            if (! is_string($step['payload']['code'] ?? null)) {
                continue;
            }
            $hash = hash('sha256', $step['payload']['code']);
            if (in_array([$step['_definition_id'], $step['_graph_path'], $step['type'], $step['key'], $hash], $old, true)) {
                continue;
            }
            $executions = WorkflowStep::whereIn('workflow_run_id', $runs)->where('step_key', $step['key'])->where('type', $step['type'])->where('status', 'completed')->get();
            $verified = $executions->contains(function ($execution) use ($hash, $step) {
                $run = $execution->run;
                if ((int) $run->workflow_definition_id !== (int) $step['_definition_id']
                    || ($run->definition_snapshot['graph_path'] ?? []) !== $step['_graph_path']
                    || (int) $execution->type_version !== (int) ($step['version'] ?? 1)) {
                    return false;
                }
                $job = DeviceJob::where('public_id', $execution->external_run_id)->where('workflow_execution_id', $execution->execution_id)->where('status', 'completed')->first();

                return $job && is_string($job->payload['params']['code'] ?? null) && hash_equals($hash, hash('sha256', $job->payload['params']['code']));
            });
            abort_unless($verified, 409, 'workflow_repair_changed_script_not_real_tested');
        }
    }
}
