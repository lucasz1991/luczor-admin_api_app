<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowTestEvidence;

/** Read-only cohorts from real, externally asserted tests. Never treats model self-ratings as quality. */
class WorkflowAgentEvidence
{
    public function snapshot(WorkflowRun $run, WorkflowStep $step, Device $device): array
    {
        abort_unless((int) $run->user_id === (int) $device->user_id && (int) $step->workflow_run_id === (int) $run->id
            && in_array($step->type, ['agent.single', 'agent.team'], true), 404);
        $currentJob = $this->job($run, $step, $device);
        abort_unless($currentJob && in_array($currentJob->status, ['running', 'completed'], true), 409, 'workflow_agent_evidence_job_unavailable');
        $environment = app(WorkflowTestService::class)->environment($device);
        $plan = $this->planHash($run->definition_snapshot, $step->step_key);
        $params = $this->parameters($currentJob->payload['params']);
        $scope = ['user_id' => (int) $run->user_id, 'project_id' => $run->project_id, 'device_id' => $device->device_id,
            'run' => $run->public_id, 'step_id' => $step->id, 'execution_id' => $step->execution_id, 'plan' => $plan,
            'task' => $step->type, 'task_version' => $step->type_version, 'params' => $params,
            'input' => $run->input ?? [], 'environment' => $environment];
        $groups = [];
        $seen = [];
        $requiredCohort = null;
        if ($currentTestId = $run->context['_execution']['_test_evidence_id'] ?? null) {
            $currentTest = WorkflowTestEvidence::where('user_id', $run->user_id)->where('workflow_run_id', $run->id)->findOrFail($currentTestId);
            abort_unless(WorkflowTestService::hash($currentTest->snapshot['test_case'] ?? []) === $currentTest->fixture_hash
                && WorkflowTestService::hash($currentTest->snapshot['test_case']['assertions'] ?? []) === $currentTest->assertions_hash, 409, 'workflow_agent_test_binding_invalid');
            $requiredCohort = WorkflowTestService::hash(['assertions' => $currentTest->assertions_hash, 'fixture' => $currentTest->fixture_hash]);
        }
        $items = WorkflowTestEvidence::where('user_id', $run->user_id)->where('workflow_definition_id', $run->workflow_definition_id)
            ->where('mode', 'real')->whereIn('status', ['passed', 'failed'])->whereNotNull('finished_at')
            ->where('environment_hash', $environment)->latest('id')->limit(200)->get();
        foreach ($items as $evidence) {
            try {
                $spec = $evidence->snapshot['test_case'] ?? [];
                $snapshot = $evidence->snapshot['workflow'] ?? [];
                $assertions = $spec['assertions'] ?? [];
                if (! is_array($assertions) || ! $this->objective($assertions, $step->step_key)
                    || ($spec['real_test_authorized'] ?? false) !== true || ($spec['device_id'] ?? null) !== $device->device_id
                    || ($evidence->snapshot['device_id'] ?? null) !== $device->device_id
                    || ($evidence->snapshot['device_environment_hash'] ?? null) !== ($device->meta['workflow_capabilities']['environment_hash'] ?? null)
                    || WorkflowTestService::hash($snapshot) !== $evidence->definition_hash
                    || WorkflowTestService::codeHash($snapshot) !== $evidence->code_hash
                    || WorkflowTestService::hash($assertions) !== $evidence->assertions_hash
                    || WorkflowTestService::hash($spec) !== $evidence->fixture_hash
                    || $this->planHash($snapshot, $step->step_key) !== $plan
                    || WorkflowTestService::hash($spec['input'] ?? []) !== WorkflowTestService::hash($run->input ?? [])) {
                    continue;
                }
                $sample = WorkflowRun::where('user_id', $run->user_id)->where('project_id', $run->project_id)->whereKey($evidence->workflow_run_id)->first();
                if (! $sample || $sample->status !== 'completed' || ! $sample->finished_at || $sample->sandbox || $sample->test_mode !== 'real'
                    || $sample->parent_workflow_run_id !== null || $sample->root_workflow_run_id !== null
                    || (int) $sample->workflow_definition_id !== (int) $run->workflow_definition_id
                    || (int) ($sample->context['_execution']['_test_evidence_id'] ?? 0) !== (int) $evidence->id
                    || WorkflowTestService::hash($sample->definition_snapshot) !== $evidence->definition_hash
                    || WorkflowTestService::hash($sample->input ?? []) !== WorkflowTestService::hash($spec['input'] ?? [])) {
                    continue;
                }
                $sampleStep = $sample->steps()->where('step_key', $step->step_key)->where('type', $step->type)->where('type_version', $step->type_version)->where('status', 'completed')->first();
                $job = $sampleStep ? $this->job($sample, $sampleStep, $device) : null;
                if (! $job || $job->status !== 'completed' || ! $job->finished_at || ! is_array($job->result)
                    || $this->parameters($job->payload['params']) !== $params
                    || app(AuditLogger::class)->hash($job->result) !== $job->result_hash
                    || WorkflowTestService::hash($sampleStep->output ?? []) !== WorkflowTestService::hash(array_merge($job->result, ['device_job' => $job->public_id, 'result' => $job->result]))) {
                    continue;
                }
                $identity = $this->model($job);
                if ($identity === null) {
                    continue;
                }
                // Re-evaluate frozen assertions; terminal evidence is not refreshed into a new claim.
                $passed = true;
                foreach ($assertions as $assertion) {
                    $observed = $sample->steps()->where('step_key', $assertion['step_key'])->where('status', 'completed')->first();
                    if (! $observed || (WorkflowTaskCatalog::isClientTask($observed->type) && ! $this->completedJob($sample, $observed, $device))) {
                        continue 2;
                    }
                    try {
                        app(WorkflowDataTasks::class)->assertions($observed->output, [$assertion]);
                    } catch (\Throwable) {
                        $passed = false;
                    }
                }
                if (($evidence->status === 'passed') !== $passed) {
                    continue;
                }
                $group = WorkflowTestService::hash(['assertions' => $evidence->assertions_hash, 'fixture' => $evidence->fixture_hash]);
                if ($requiredCohort !== null && $requiredCohort !== $group) {
                    continue;
                }
                $rowKey = WorkflowTestService::hash($identity);
                if (isset($seen[$group][$sample->id])) {
                    continue;
                }
                $seen[$group][$sample->id] = true;
                $groups[$group][$rowKey] ??= $identity + ['samples' => 0, 'passed' => 0, 'failed' => 0,
                    'latest_test_at' => $evidence->finished_at->toISOString(), 'evidence_ids' => []];
                $row = &$groups[$group][$rowKey];
                $row['samples']++;
                $row[$passed ? 'passed' : 'failed']++;
                $row['latest_test_at'] = max($row['latest_test_at'], $evidence->finished_at->toISOString());
                if (count($row['evidence_ids']) < 20) {
                    $row['evidence_ids'][] = $evidence->id;
                }
                unset($row);
            } catch (\Throwable) {
                // Inconsistent or historical evidence contributes no sample and exposes no private payload.
                continue;
            }
        }
        // Different assertions or fixtures are never pooled. An ambiguous cohort yields no ranking.
        $groupKey = count($groups) === 1 ? array_key_first($groups) : null;
        $scope['cohort'] = $groupKey;
        $rows = $groupKey === null ? [] : array_values($groups[$groupKey]);
        // Do not mix runtime observation with a requested pin, or expose duplicate adapter/model rows.
        $models = [];
        foreach ($rows as $row) {
            $key = WorkflowTestService::hash(['adapter' => $row['adapter'], 'model' => $row['model']]);
            if (! isset($models[$key]) || $row['model_source'] === 'runtime') {
                $models[$key] = $row;
            }
        }
        $rows = array_values($models);
        usort($rows, fn ($a, $b) => [$a['adapter'], $a['model'], $a['model_source']] <=> [$b['adapter'], $b['model'], $b['model_source']]);
        $result = ['version' => 1, 'scope_hash' => WorkflowTestService::hash($scope),
            'device_environment_hash' => $device->meta['workflow_capabilities']['environment_hash'], 'minimum_samples' => 5, 'rows' => $rows];
        $result['revision'] = WorkflowTestService::hash($result);

        return $result;
    }

    private function parameters(array $params): array
    {
        unset($params['agent'], $params['model'], $params['agent_selection']);

        return $params;
    }

    private function planHash(array $snapshot, string $target): string
    {
        // Revision bookkeeping changes when the selected model changes; original snapshot hashes are checked separately.
        unset($snapshot['version'], $snapshot['revision_id'], $snapshot['name']);
        foreach ($snapshot['definition']['steps'] ?? [] as $index => $step) {
            if ($step['key'] === $target) {
                $snapshot['definition']['steps'][$index]['payload'] = $this->parameters($step['payload'] ?? []);
            }
        }

        return WorkflowTestService::hash($snapshot);
    }

    private function job(WorkflowRun $run, WorkflowStep $step, Device $device): ?DeviceJob
    {
        $job = DeviceJob::where('user_id', $run->user_id)->where('project_id', $run->project_id)->where('device_id', $device->id)
            ->where('public_id', $step->external_run_id)->where('workflow_execution_id', $step->execution_id)->where('tool_profile', 'workflow.task')->first();
        if (! $job || ! is_array($job->payload) || app(AuditLogger::class)->hash($job->payload) !== $job->payload_hash
            || ($job->payload['workflow']['run'] ?? null) !== $run->public_id
            || (int) ($job->payload['workflow']['step_id'] ?? 0) !== (int) $step->id
            || ($job->payload['workflow']['execution_id'] ?? null) !== $step->execution_id
            || ($job->payload['workflow']['device_id'] ?? null) !== $device->device_id
            || ($job->payload['task_key'] ?? null) !== $step->type
            || ($job->payload['task_version'] ?? null) !== $step->type_version
            || ! is_array($job->payload['params'] ?? null)
            || WorkflowTestService::hash($job->payload['params']) !== WorkflowTestService::hash($step->resolved_payload ?? $step->payload ?? [])) {
            return null;
        }

        return $job;
    }

    private function completedJob(WorkflowRun $run, WorkflowStep $step, Device $device): bool
    {
        $job = $this->job($run, $step, $device);

        return $job && $job->status === 'completed' && $job->finished_at && is_array($job->result)
            && app(AuditLogger::class)->hash($job->result) === $job->result_hash
            && WorkflowTestService::hash($step->output ?? []) === WorkflowTestService::hash(array_merge($job->result, ['device_job' => $job->public_id, 'result' => $job->result]));
    }

    private function model(DeviceJob $job): ?array
    {
        $output = $job->result;
        $adapter = $output['agent'] ?? null;
        $source = $output['model_confirmation'] ?? null;
        $model = $source === 'pinned_request' ? ($output['requested_model'] ?? null) : ($output['model'] ?? null);
        if (! in_array($adapter, ['local', 'codex', 'claude'], true) || ! in_array($source, ['runtime', 'pinned_request'], true)
            || ! is_string($model) || trim($model) === '' || strlen($model) > 200
            || ($adapter === 'local' && ($source !== 'runtime' || ($output['inference_target'] ?? null) !== 'local_llama_cpp'))
            || ($source === 'pinned_request' && ($model !== ($job->payload['params']['model'] ?? null)
                || ($job->payload['params']['agent_selection'] ?? null) !== 'override'
                || $adapter !== ($job->payload['params']['agent'] ?? null)))) {
            return null;
        }

        return ['adapter' => $adapter, 'model' => $model, 'model_source' => $source];
    }

    private function objective(array $assertions, string $key): bool
    {
        foreach ($assertions as $assertion) {
            $path = $assertion['path'] ?? '';
            if (($assertion['step_key'] ?? null) === $key && in_array($assertion['kind'] ?? 'value', ['value', 'result'], true)
                && ($assertion['operator'] ?? 'eq') === 'eq' && is_string($path)
                && ($path === 'text' || str_starts_with($path, 'data.'))
                && ! preg_match('/(?:score|rating|confidence|quality|(?:^|\.)(?:ok|passed|success|status|outcome|checks|code|exit_code|model|duration_ms|tool_successes|tool_failures)(?:\.|$))/i', $path)
                && ((is_string($assertion['value'] ?? null) && trim($assertion['value']) !== '') || is_int($assertion['value'] ?? null))) {
                return true;
            }
        }

        return false;
    }
}
