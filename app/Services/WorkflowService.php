<?php

namespace App\Services;

use App\Jobs\ExecuteWorkflowStep;
use App\Jobs\MonitorWorkflowStep;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\Project;
use App\Models\Setting;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunArtifact;
use App\Models\WorkflowStep;
use App\Models\WorkflowTestEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Persistent dependency scheduler. It never executes arbitrary shell code. */
class WorkflowService
{
    private WorkflowDefinitionValidator $definitionValidator;

    private WorkflowArtifactService $artifactService;

    private WorkflowRunNotifier $runNotifier;

    public function __construct(
        ?WorkflowDefinitionValidator $definitionValidator = null,
        ?WorkflowArtifactService $artifactService = null,
        ?WorkflowRunNotifier $runNotifier = null,
    ) {
        $this->definitionValidator = $definitionValidator ?? new WorkflowDefinitionValidator;
        $this->artifactService = $artifactService ?? new WorkflowArtifactService;
        $this->runNotifier = $runNotifier ?? new WorkflowRunNotifier;
    }

    /** @param array<string,mixed> $definition */
    public function createRun(WorkflowDefinition $definition, array $input = [], ?int $agentRunId = null, bool $sandbox = false, array $executionContext = []): WorkflowRun
    {
        abort_if(strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 200000, 422, 'Workflow input is too large.');
        $sandbox = $sandbox || Setting::getValue('sandbox_enabled', false) === true;

        return DB::transaction(function () use ($definition, $input, $agentRunId, $sandbox, $executionContext) {
            $definition = WorkflowDefinition::query()->lockForUpdate()->findOrFail($definition->id);
            $authoring = app(WorkflowAuthoringService::class);
            $projectId = isset($executionContext['project_id'])
                ? $authoring->projectId((int) $definition->user_id, $executionContext['project_id']) : $definition->project_id;
            abort_if($definition->project_id !== null && (int) $definition->project_id !== $projectId, 422, 'Workflow project cannot be changed at execution.');
            $isChild = isset($executionContext['_snapshot']);
            $snapshot = $executionContext['_snapshot'] ?? $authoring->snapshot($definition, (int) $definition->user_id, $projectId);
            unset($executionContext['_snapshot']);
            $steps = $this->assertDefinition($snapshot['definition']);
            if (isset($snapshot['definition']['input_schema'])) {
                WorkflowSchema::validate($input, $snapshot['definition']['input_schema'], '$.input');
            }
            $executionContext['project_id'] = $projectId ? Project::findOrFail($projectId)->external_id : null;
            $executionContext['root_workflow_definition_id'] ??= $definition->id;
            $executionContext['root_workflow_revision'] ??= $snapshot['version'];
            if (! empty($executionContext['device_id'])) {
                abort_unless(Device::where('user_id', $definition->user_id)->where('device_id', $executionContext['device_id'])->whereNull('revoked_at')->exists(), 422, 'The selected device is invalid.');
            }
            if (($executionContext['automatic'] ?? false) && ! $sandbox && ! $isChild) {
                $executionContext['grant'] = app(AutomationGrantService::class)->authorizeRun($definition, $snapshot, $executionContext);
            }
            if (! empty($executionContext['grant'])) {
                abort_if(strlen(json_encode($input, JSON_THROW_ON_ERROR)) > ($executionContext['grant']['config']['max_input_bytes'] ?? 65536), 409, 'Automation input exceeds the approved input budget.');
            }
            $run = WorkflowRun::create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $definition->user_id,
                'project_id' => $projectId,
                'workflow_definition_id' => $definition->id,
                'agent_run_id' => $agentRunId,
                'status' => 'queued',
                'sandbox' => $sandbox,
                'input' => $input,
                'workflow_revision_id' => $snapshot['revision_id'],
                'definition_snapshot' => $snapshot,
                'context' => ['_execution' => $executionContext],
                'root_workflow_run_id' => $executionContext['_root_workflow_run_id'] ?? null,
                'budgets' => WorkflowBudgetService::policy($snapshot['definition'], $executionContext['grant'] ?? []),
                'budget_state' => ['executions' => 0, 'active_ms' => 0],
                'test_mode' => $executionContext['_test_mode'] ?? null,
            ]);
            foreach ($steps as $position => $step) {
                $run->steps()->create([
                    'user_id' => $run->user_id,
                    'step_key' => $step['key'],
                    'type' => $step['type'],
                    'type_version' => $step['version'],
                    'position' => $position,
                    'max_attempts' => $step['max_attempts'],
                    'requires_approval' => $step['requires_approval'],
                    'depends_on' => $step['depends_on'],
                    'payload' => $step['payload'],
                    'status' => 'queued',
                    'available_at' => now(),
                    'execution_id' => (string) Str::uuid(),
                ]);
            }

            return $run;
        });
    }

    public function advance(WorkflowRun $run): WorkflowRun
    {
        $readyStepIds = [];
        $result = DB::transaction(function () use ($run, &$readyStepIds) {
            app(WorkflowBudgetService::class)->root($run);
            $run = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($run->status, ['cancelled', 'cancelling', 'completed', 'failed'], true)) {
                return $run;
            }
            if (! $run->started_at) {
                $run->update(['status' => 'running', 'started_at' => now()]);
            }
            $budget = app(WorkflowBudgetService::class);
            $root = $budget->root($run);
            $budget->account($root);
            if (WorkflowBoundaryStop::requested($root)) {
                app(WorkflowBoundaryStop::class)->settle($root);

                return $run->fresh(['steps', 'definition']);
            }
            if (($root->budget_state['active_ms'] ?? 0) >= ($root->budgets['active_seconds'] ?? 2700) * 1000) {
                $state = $root->budget_state;
                $state['stop_reason'] = 'workflow_active_budget_exhausted';
                $root->update(['budget_state' => $state]);
                $this->cancel($root);

                return $run->fresh(['steps']);
            }

            $steps = $run->steps()->orderBy('position')->get();
            foreach ($steps->where('status', 'waiting_for_device') as $waiting) {
                $deviceId = WorkflowDeviceTarget::forStep($waiting);
                $query = Device::where('user_id', $run->user_id)->whereNull('revoked_at')->whereIn('status', ['online', 'busy']);
                if ($deviceId) {
                    $query->where('device_id', $deviceId);
                }
                if ($deviceId && $query->exists()) {
                    $waiting->update(['status' => 'queued', 'error' => null, 'available_at' => now()]);
                }
            }
            foreach ($steps->where('status', 'waiting_for_capability') as $waiting) {
                $device = Device::where('user_id', $run->user_id)->where('device_id', WorkflowDeviceTarget::forStep($waiting))->first();
                if ($device && app(WorkflowDeviceCapabilities::class)->admission($device, $waiting->type, $waiting->type_version)['ready']) {
                    $waiting->update(['status' => 'queued', 'error' => null, 'available_at' => now()]);
                }
            }
            $terminal = $steps->whereIn('status', ['completed', 'skipped'])->pluck('step_key')->all();
            $completed = $steps->where('status', 'completed')->pluck('step_key')->all();
            foreach ($steps->where('status', 'queued') as $step) {
                if ($step->available_at?->isFuture()) {
                    continue;
                }
                $dependencies = $step->depends_on ?? [];
                if (array_diff($dependencies, $terminal) !== []) {
                    continue;
                }
                if ($dependencies !== [] && array_intersect($dependencies, $completed) === []) {
                    $step->update(['status' => 'skipped', 'finished_at' => now()]);
                    $terminal[] = $step->step_key;

                    continue;
                }
                $standingGrant = ($run->context['_execution']['automatic'] ?? false) && ! empty($run->context['_execution']['grant']);
                $step->update([
                    'status' => (($step->requires_approval && ! $step->approved_at && ! $standingGrant && $run->test_mode !== 'simulation') || $step->type === 'approval') ? 'awaiting_approval' : 'ready',
                ]);
            }

            $fresh = $run->steps()->get();
            // P15b — every auto-dispatchable catalog type (server branches and
            // client/device bundles) is handed to the executor; manual/
            // approval/device_job stay externally completed.
            $readyStepIds = $fresh
                ->filter(fn (WorkflowStep $step) => $step->status === 'ready' && WorkflowTaskCatalog::isAutoDispatch($step->type))
                ->pluck('id')
                ->all();
            if ($fresh->isNotEmpty() && $fresh->every(fn (WorkflowStep $step) => in_array($step->status, ['completed', 'skipped'], true))) {
                $run->update(['status' => 'completed', 'finished_at' => now(), 'duration_ms' => $this->runDurationMs($run)]);
            } elseif ($fresh->contains(fn (WorkflowStep $step) => $step->status === 'failed')) {
                if (($root->definition_snapshot['definition']['schema_version'] ?? 1) === 2) {
                    $this->cancel($run, 'failed');
                } else {
                    $run->update(['status' => 'failed', 'finished_at' => now(), 'duration_ms' => $this->runDurationMs($run)]);
                }
            }

            return $run->fresh(['steps', 'definition']);
        });
        foreach ($readyStepIds as $stepId) {
            ExecuteWorkflowStep::dispatch($stepId)->afterCommit();
        }

        // P14 — when a child run terminates, poke the parent run so its nested
        // step is completed/failed (decoupled via the queue, no nested locks).
        if (in_array($result->status, ['completed', 'failed', 'cancelled'], true) && $result->parent_workflow_run_id) {
            if ($parent = WorkflowRun::find($result->parent_workflow_run_id)) {
                $this->scheduleMonitor($parent, 1);
            }
        }
        $this->notifyTerminalRun($result);

        return $result;
    }

    public function complete(WorkflowStep $step, array $output = []): WorkflowRun
    {
        return DB::transaction(function () use ($step, $output) {
            app(WorkflowBudgetService::class)->root($step->run);
            $run = WorkflowRun::query()->lockForUpdate()->findOrFail($step->workflow_run_id);
            $step = WorkflowStep::query()->lockForUpdate()->findOrFail($step->id);
            if ($run->status === 'cancelling' && $step->status === 'running' && (WorkflowTaskCatalog::task($step->type)['runner'] ?? '') === 'server') {
                app(WorkflowBudgetService::class)->settle($step, 'cancelled', $output);
                $step->update(['status' => 'cancelled', 'output' => $output, 'finished_at' => now()]);
                $this->settleCancellation($run);

                return $run->fresh(['steps']);
            }
            if ($step->status === 'completed') {
                abort_unless($step->output === $output, 409, 'Conflicting workflow result.');

                return $step->run->fresh(['steps']);
            }
            abort_if(in_array($run->status, ['cancelled', 'cancelling', 'completed', 'failed'], true), 409, 'Workflow is no longer running.');
            abort_unless(in_array($step->status, ['ready', 'running', 'awaiting_approval'], true), 409, 'Workflow step is not ready.');
            if (($run->definition_snapshot['definition']['schema_version'] ?? 1) === 2) {
                $outcome = WorkflowResultNormalizer::outcome($output, 'success');
                if (in_array($outcome, ['failed', 'timeout', 'cancelled'], true)
                    || ($outcome === 'partial' && ! isset($step->payload['routes']['partial']))) {
                    return $this->fail($step, 'workflow_task_result_'.$outcome, $outcome);
                }
                WorkflowSchema::validate($output, WorkflowTaskCatalog::task($step->type)['output_schema']);
                $schema = ($step->resolved_payload ?? $step->payload)['output_schema'] ?? null;
                if (is_array($schema)) {
                    WorkflowSchema::validate($output['data'] ?? $output, $schema);
                }
            }
            app(WorkflowBudgetService::class)->settle($step, 'completed', $output);
            $step->update([
                'status' => 'completed', 'output' => $output, 'finished_at' => now(), 'error' => null,
                'duration_ms' => $this->stepDurationMs($step),
                'result_envelope' => ['type' => $step->type, 'version' => $step->type_version, 'execution_id' => $step->execution_id, 'outcome' => WorkflowResultNormalizer::outcome($output, 'success'), 'data' => $output],
            ]);

            // P13 — outcome-based routing (branch / loop / terminate) if declared.
            $routed = WorkflowBoundaryStop::requested(app(WorkflowBudgetService::class)->root($step->run))
                ? null : $this->applyRoutes($step->fresh(), WorkflowResultNormalizer::outcome($output, 'success'));

            $result = $routed ?? $this->advance($step->run);
            $this->notifyTerminalRun($result);

            return $result;
        }, 3);
    }

    public function approve(WorkflowStep $step, int $userId): WorkflowRun
    {
        return DB::transaction(function () use ($step, $userId) {
            app(WorkflowBudgetService::class)->root($step->run);
            $step = WorkflowStep::query()->lockForUpdate()->findOrFail($step->id);
            abort_unless((int) $step->user_id === $userId, 404);
            abort_unless($step->run->status === 'running', 409, 'Workflow is not running.');
            if ($step->approved_at) {
                return $step->run->fresh(['steps']);
            }
            abort_unless($step->status === 'awaiting_approval', 409, 'Step is not awaiting approval.');
            $step->update(['approved_at' => now()]);
            if (in_array($step->type, ['approval', 'manual'], true)) {
                return $this->complete($step, ['approved' => true, 'approved_by' => $userId]);
            }
            $step->update(['status' => 'ready']);

            return $this->advance($step->run);
        });
    }

    public function fail(WorkflowStep $step, string $error, string $outcome = 'failed'): WorkflowRun
    {
        return DB::transaction(function () use ($step, $error, $outcome) {
            app(WorkflowBudgetService::class)->root($step->run);
            WorkflowRun::query()->lockForUpdate()->findOrFail($step->workflow_run_id);
            $step = WorkflowStep::query()->lockForUpdate()->findOrFail($step->id);
            if ($step->run->status === 'cancelling' && $step->status === 'running' && (WorkflowTaskCatalog::task($step->type)['runner'] ?? '') === 'server') {
                return $this->complete($step, ['error' => $error, 'outcome' => 'cancelled']);
            }
            if (in_array($step->run->status, ['cancelled', 'cancelling', 'completed', 'failed'], true)) {
                return $step->run;
            }
            abort_unless(in_array($step->status, ['ready', 'running'], true), 409, 'Workflow step is not running.');
            if ($step->external_run_type === 'device_job' && $step->external_run_id) {
                $job = DeviceJob::where('public_id', $step->external_run_id)->first();
                if ($job && ! in_array($job->status, ['completed', 'failed', 'rejected', 'cancelled'], true)) {
                    $root = app(WorkflowBudgetService::class)->root($step->run);
                    $state = $root->budget_state ?? [];
                    $state['stop_reason'] = $error;
                    $root->update(['budget_state' => $state]);

                    return $this->cancel($root);
                }
            }
            $attempts = $step->attempts + 1;
            app(WorkflowBudgetService::class)->settle($step, $outcome, ['error' => mb_substr($error, 0, 8000)]);
            $retry = $attempts < $step->max_attempts && ! ($step->external_run_type === 'device_job' && $step->external_run_id);
            $stopping = WorkflowBoundaryStop::requested(app(WorkflowBudgetService::class)->root($step->run));
            $retry = $retry && ! $stopping;
            $step->update([
                'attempts' => $attempts,
                'status' => $retry ? 'queued' : 'failed',
                'error' => $error,
                'available_at' => $retry ? now()->addSeconds(min(60, 2 ** $attempts)) : null,
                'finished_at' => $retry ? null : now(),
                'duration_ms' => $retry ? null : $this->stepDurationMs($step),
            ]);

            // P13 — on final failure, an on-error route (e.g. to a cleanup step) wins
            // over the default "one failed step fails the run" behaviour.
            if (! $retry && ! $stopping) {
                $routed = $this->applyRoutes($step->fresh(), $outcome);
                if ($routed) {
                    $this->notifyTerminalRun($routed);

                    return $routed;
                }
            }

            return $this->advance($step->run);
        }, 3);
    }

    /** Schedule a delayed poll of an in-flight run (timeout expiry + advance). */
    public function scheduleMonitor(WorkflowRun $run, int $seconds = 10): void
    {
        MonitorWorkflowStep::dispatch($run->id)->delay(now()->addSeconds(max(1, $seconds)))->afterCommit();
    }

    /**
     * SOLL §14 P14 — start a child WorkflowRun for a nested-workflow step, with a
     * cycle guard. The parent step stays 'running' until the child terminates
     * (see syncChildWorkflows()).
     */
    public function startChildWorkflow(WorkflowStep $parentStep): WorkflowRun
    {
        return DB::transaction(function () use ($parentStep) {
            app(WorkflowBudgetService::class)->root($parentStep->run);
            $parentStep = WorkflowStep::query()->lockForUpdate()->findOrFail($parentStep->id);
            $parentRun = $parentStep->run;
            abort_unless($parentRun->status === 'running', 409, 'Parent workflow is not running.');
            if ($child = WorkflowRun::where('parent_execution_id', $parentStep->execution_id)->first()) {
                return $child;
            }
            abort_if(WorkflowBoundaryStop::requested(app(WorkflowBudgetService::class)->root($parentRun)), 409, 'workflow_boundary_stop_pending');
            $payload = $parentStep->resolved_payload ?? $parentStep->payload;
            $childDef = WorkflowDefinition::findOrFail((int) ($payload['workflow_definition_id'] ?? 0));
            $snapshot = $parentRun->definition_snapshot['children'][$parentStep->step_key]
                ?? app(WorkflowAuthoringService::class)->snapshot($childDef, (int) $parentRun->user_id, $parentRun->project_id, [$parentRun->workflow_definition_id]);
            $context = $parentRun->context['_execution'] ?? [];
            $context['_snapshot'] = $snapshot;
            $context['_root_workflow_run_id'] = $parentRun->root_workflow_run_id ?: $parentRun->id;
            $parentStep->update(['status' => 'running', 'started_at' => $parentStep->started_at ?? now()]);
            $child = $this->createRun($childDef, is_array($payload['input'] ?? null) ? $payload['input'] : [], $parentRun->agent_run_id, $parentRun->sandbox, $context);
            $child->update(['parent_workflow_run_id' => $parentRun->id, 'parent_workflow_step_id' => $parentStep->id, 'parent_execution_id' => $parentStep->execution_id]);
            $this->advance($child);

            return $child->fresh();
        });
    }

    /**
     * Complete/fail parent nested-workflow steps whose child run has terminated.
     * Called from the monitor/advance chain.
     */
    public function syncChildWorkflows(WorkflowRun $parentRun): int
    {
        $synced = 0;
        foreach ($parentRun->steps()->whereIn('type', ['control.foreach', 'control.until', 'control.parallel'])->where('status', 'running')->get() as $control) {
            try {
                app(WorkflowStructuredControl::class)->sync($control);
            } catch (\Throwable $error) {
                $this->fail($control->fresh(), mb_substr($error->getMessage(), 0, 8000));
            }
            $synced++;
        }
        $steps = $parentRun->steps()->where('type', 'workflow')->where('status', 'running')->get();
        foreach ($steps as $step) {
            $child = WorkflowRun::where('parent_workflow_step_id', $step->id)->latest('id')->first();
            if (! $child) {
                continue;
            }
            if ($child->status === 'completed') {
                $this->complete($step->fresh(), ['outcome' => 'success', 'child_run' => $child->public_id, 'output' => $child->output]);
                $synced++;
            } elseif (in_array($child->status, ['failed', 'cancelled'], true)) {
                $this->fail($step->fresh(), 'child_workflow_'.$child->status);
                $synced++;
            }
        }

        return $synced;
    }

    private function stepDurationMs(WorkflowStep $step): ?int
    {
        return $step->started_at ? (int) $step->started_at->diffInMilliseconds(now()) : null;
    }

    private function runDurationMs(WorkflowRun $run): ?int
    {
        return $run->started_at ? (int) $run->started_at->diffInMilliseconds(now()) : null;
    }

    /**
     * SOLL §14 P15b — settle 'running' client-task steps against their device
     * job: completed → complete with the device result, failed/rejected → fail,
     * expired without a terminal status → timeout.
     */
    public function syncDeviceJobSteps(WorkflowRun $run): int
    {
        $synced = 0;
        $steps = $run->steps()
            ->where('status', 'running')
            ->where('external_run_type', 'device_job')
            ->whereNotNull('external_run_id')
            ->get();
        foreach ($steps as $step) {
            $job = DeviceJob::query()->where('public_id', $step->external_run_id)->first();
            if (! $job) {
                $this->fail($step->fresh(), 'device_job_missing');
                $synced++;
            } elseif ($job->status === 'completed') {
                $result = $job->result ?? [];
                try {
                    $this->complete($step->fresh(), array_merge($result, ['device_job' => $job->public_id, 'result' => $result]));
                } catch (\Throwable $error) {
                    $this->fail($step->fresh(), mb_substr($error->getMessage(), 0, 8000));
                }
                $synced++;
            } elseif (in_array($job->status, ['failed', 'rejected', 'cancelled'], true)) {
                $this->fail($step->fresh(), mb_substr('device_job_'.$job->status.($job->error ? ': '.$job->error : ''), 0, 8000));
                $synced++;
            } elseif ($job->expires_at?->isPast()) {
                $this->fail($step->fresh(), 'device_job_expired', 'timeout');
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * SOLL §14 P15b — complete 'running' wait.seconds steps whose delay elapsed.
     * The executor parks them as running and schedules a monitor poll.
     */
    public function settleWaitSteps(WorkflowRun $run): int
    {
        $settled = 0;
        foreach ($run->steps()->where('type', 'wait.seconds')->where('status', 'running')->get() as $step) {
            $seconds = max(1, min(3600, (int) (($step->resolved_payload['seconds'] ?? $step->payload['seconds'] ?? null) ?: 5)));
            if ($step->started_at && $step->started_at->copy()->addSeconds($seconds)->isPast()) {
                $this->complete($step, ['waited_seconds' => $seconds]);
                $settled++;
            }
        }

        return $settled;
    }

    /**
     * SOLL §14 P15 — fail steps that have been 'running' past their timeout.
     * Timeout = payload.timeout_seconds, else the task catalog default.
     */
    public function expireTimedOutSteps(WorkflowRun $run): int
    {
        $expired = 0;
        foreach ($run->steps()->where('status', 'running')->get() as $step) {
            if (in_array($step->type, ['workflow', 'control.foreach', 'control.until', 'control.parallel'], true) && ! isset($step->payload['timeout_seconds'])) {
                continue;
            }
            $timeout = (int) ($step->payload['timeout_seconds']
                ?? WorkflowTaskCatalog::task($step->type)['timeout_seconds']
                ?? 300);
            if ($step->started_at && $step->started_at->copy()->addSeconds($timeout)->isPast()) {
                if ((WorkflowTaskCatalog::task($step->type)['runner'] ?? '') === 'server' && WorkflowTaskCatalog::isAutoDispatch($step->type) && WorkflowBudgetService::occupiesSlot($step->type)) {
                    // A timed out PHP worker can still be inside an effect; only its eventual callback confirms its end.
                    DB::transaction(function () use ($step) {
                        $root = app(WorkflowBudgetService::class)->root($step->run);
                        $state = $root->budget_state ?? [];
                        $state['stop_reason'] = 'step_timeout';
                        $root->update(['budget_state' => $state]);
                        $this->cancel($root);
                    });
                } else {
                    $this->fail($step, 'step_timeout', 'timeout');
                }
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * SOLL §14 P15 — record a step artifact (screenshot/DOM/log/json) with
     * secret-masked metadata for run visualization.
     *
     * @param  array<string,mixed>  $data
     */
    public function recordArtifact(WorkflowStep $step, array $data): WorkflowRunArtifact
    {
        return $this->artifactService->record($step, $data);
    }

    /**
     * Recursively redact secret-ish keys before persisting to logs/artifacts.
     *
     * @param  array<mixed,mixed>  $data
     * @return array<mixed,mixed>
     */
    public function maskSecrets(array $data): array
    {
        return $this->artifactService->maskSecrets($data);
    }

    /**
     * Apply a step's outcome route. Returns the run when it handled the outcome
     * (terminate / branch / loop), or null to fall back to the depends_on DAG.
     */
    private function applyRoutes(WorkflowStep $step, string $outcome): ?WorkflowRun
    {
        $routes = $step->payload['routes'] ?? [];
        if (! is_array($routes) || $routes === []) {
            return null;
        }
        $route = $routes[$outcome] ?? $routes['default'] ?? null;
        if (! is_array($route)) {
            return null;
        }

        $run = $step->run;
        $type = $route['type'] ?? '';

        // A handled on-error route (branch/terminate-ok) consumes the failure so
        // advance() no longer fails the whole run over this step.
        if ($step->status === 'failed' && $type !== 'fail') {
            $step->update(['status' => 'skipped']);
        }

        if ($type === 'end' || $type === 'fail') {
            if (($run->definition_snapshot['definition']['schema_version'] ?? 1) === 2) {
                return $this->cancel($run, $type === 'end' ? 'completed' : 'failed');
            }
            $run->update(['status' => $type === 'end' ? 'completed' : 'failed', 'finished_at' => now(), 'duration_ms' => $this->runDurationMs($run)]);

            return $run->fresh(['steps', 'definition']);
        }

        if ($type !== 'step') {
            return null;
        }

        $targetKey = (string) ($route['step_key'] ?? '');
        if ($step->type === 'condition' && in_array($outcome, ['true', 'false'], true)) {
            $other = $routes[$outcome === 'true' ? 'false' : 'true'] ?? null;
            if (($other['type'] ?? '') === 'step' && $other['step_key'] !== $targetKey) {
                $selected = $this->descendants($run, $targetKey);
                $unselected = array_diff($this->descendants($run, $other['step_key']), $selected);
                $run->steps()->whereIn('step_key', $unselected)->whereIn('status', ['queued', 'ready'])->update(['status' => 'skipped', 'finished_at' => now()]);
            }
        }
        $limit = max(1, (int) ($route['max_iterations'] ?? 2));
        $root = app(WorkflowBudgetService::class)->root($run);
        if (($root->definition_snapshot['definition']['schema_version'] ?? 1) === 2) {
            $limit = min($limit, $root->budgets['max_loop_iterations'] ?? 10);
        }
        $output = is_array($run->output) ? $run->output : [];
        $hits = is_array($output['_route_hits'] ?? null) ? $output['_route_hits'] : [];
        $counter = (int) ($hits[$targetKey] ?? 0) + 1;

        // Loop guard: a bounded number of jumps to the same target, then fail.
        if ($counter > $limit) {
            $output['_route_error'] = 'loop_limit_exceeded:'.$targetKey;
            if (($root->definition_snapshot['definition']['schema_version'] ?? 1) === 2) {
                $run->update(['output' => $output]);

                return $this->cancel($run, 'failed');
            }
            $run->update(['status' => 'failed', 'finished_at' => now(), 'output' => $output, 'duration_ms' => $this->runDurationMs($run)]);

            return $run->fresh(['steps', 'definition']);
        }

        $hits[$targetKey] = $counter;
        $output['_route_hits'] = $hits;
        $run->update(['output' => $output]);

        $target = $run->steps()->where('step_key', $targetKey)->first();
        if ($target) {
            $revisit = in_array($target->status, ['completed', 'skipped', 'failed'], true);
            if ($revisit) {
                $visits = $run->steps()->whereIn('step_key', $this->descendants($run, $targetKey))->get();
                abort_if($visits->contains(fn ($visit) => in_array($visit->status, ['running', 'waiting_for_device', 'awaiting_approval'], true)), 409, 'A loop cannot restart an unfinished parallel step.');
                foreach ($visits->whereIn('status', ['completed', 'skipped', 'failed']) as $visit) {
                    $visit->update(['status' => 'queued', 'attempts' => 0, 'output' => null, 'error' => null,
                        'control_state' => null, 'result_envelope' => null,
                        'available_at' => now(), 'started_at' => null, 'finished_at' => null,
                        'execution_id' => (string) Str::uuid(), 'execution_sequence' => $visit->execution_sequence + 1,
                        'resolved_payload' => null, 'approved_at' => null, 'external_run_type' => null, 'external_run_id' => null]);
                }
            } else {
                $target->update([
                    'status' => 'queued', 'attempts' => 0, 'output' => null, 'error' => null,
                    'available_at' => now(), 'started_at' => null, 'finished_at' => null,
                ]);
            }
        }

        return $this->advance($run);
    }

    public function cancel(WorkflowRun $run, string $terminalStatus = 'cancelled'): WorkflowRun
    {
        abort_unless(in_array($terminalStatus, ['completed', 'failed', 'cancelled'], true), 422, 'Invalid workflow terminal status.');
        $result = DB::transaction(function () use ($run, $terminalStatus) {
            $root = app(WorkflowBudgetService::class)->root($run);
            app(WorkflowBudgetService::class)->account($root);
            $run = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
                foreach (WorkflowRun::where('parent_workflow_run_id', $run->id)->get() as $child) {
                    $this->cancel($child);
                }
                $jobIds = $run->steps()->where('external_run_type', 'device_job')->pluck('external_run_id');
                DeviceJob::whereIn('public_id', $jobIds)->whereIn('status', ['queued', 'approval_required'])->update(['status' => 'cancelled', 'cancel_requested_at' => now(), 'finished_at' => now()]);
                DeviceJob::whereIn('public_id', $jobIds)->whereIn('status', ['running', 'cancelling'])->update(['cancel_requested_at' => now()]);
                $pendingServerIds = $run->steps()->where('status', 'running')->get()->filter(fn ($step) => (WorkflowTaskCatalog::task($step->type)['runner'] ?? '') === 'server' && WorkflowBudgetService::occupiesSlot($step->type))->pluck('id')->all();
                $pending = $pendingServerIds !== [] || DeviceJob::whereIn('public_id', $jobIds)->whereIn('status', ['running', 'cancelling'])->exists()
                    || WorkflowRun::where('parent_workflow_run_id', $run->id)->where('status', 'cancelling')->exists();
                $output = $run->output ?? [];
                $output['_terminal_after_stop'] = $terminalStatus;
                $run->update(['status' => $pending ? 'cancelling' : $terminalStatus, 'finished_at' => $pending ? null : now(), 'output' => $output]);
                $settled = $run->steps()->whereNotIn('id', $pendingServerIds)->whereIn('status', ['queued', 'ready', 'awaiting_approval', 'waiting_for_device', 'waiting_for_capability', 'running'])->get();
                foreach ($settled as $step) {
                    $job = $step->external_run_type === 'device_job' ? DeviceJob::where('public_id', $step->external_run_id)->first() : null;
                    if (! $job || ! in_array($job->status, ['running', 'cancelling'], true)) {
                        app(WorkflowBudgetService::class)->settle($step, 'cancelled', []);
                    }
                }
                $run->steps()->whereIn('id', $settled->pluck('id'))->update([
                    'status' => 'cancelled', 'finished_at' => now(),
                ]);
                if (! $pending) {
                    DB::table('workflow_execution_records')->whereIn('workflow_step_id', $run->steps()->pluck('id'))->where('status', 'running')->update(['status' => 'cancelled', 'finished_at' => now()]);
                    if ((int) $run->id === (int) $root->id && WorkflowBoundaryStop::requested($root)) {
                        $state = $root->fresh()->budget_state;
                        $state['boundary_stop']['status'] = 'completed';
                        $state['boundary_stop']['finished_at'] ??= now()->toISOString();
                        $state['boundary_stop']['completion_reason'] = $state['stop_reason'] ?? 'cancelled';
                        $root->update(['budget_state' => $state]);
                    }
                }
            }

            return $run->fresh(['steps', 'definition']);
        });
        $this->notifyTerminalRun($result);

        return $result;
    }

    private function notifyTerminalRun(WorkflowRun $run): void
    {
        $this->runNotifier->notifyTerminal($run);
        if (in_array($run->status, ['completed', 'failed', 'cancelled'], true) && class_exists(WorkflowEventService::class)) {
            app(WorkflowEventService::class)->recordWorkflowTerminal($run);
            if ($run->test_mode && ! $run->parent_workflow_run_id) {
                $evidence = WorkflowTestEvidence::where('workflow_run_id', $run->id)->first();
                if ($evidence) {
                    app(WorkflowTestService::class)->refresh($evidence);
                }
            }
        }
    }

    /** Reconcile a cancellation after the bound device acknowledges stopping. */
    public function settleCancellation(WorkflowRun $run): void
    {
        if ($run->status !== 'cancelling') {
            return;
        }
        $this->cancel($run, $run->output['_terminal_after_stop'] ?? 'cancelled');
        if ($run->parent_workflow_run_id && ($parent = WorkflowRun::find($run->parent_workflow_run_id))) {
            $this->settleCancellation($parent);
        }
    }

    private function descendants(WorkflowRun $run, string $key): array
    {
        $keys = [$key];
        $steps = $run->steps()->get();
        do {
            $count = count($keys);
            foreach ($steps as $candidate) {
                if (array_intersect($candidate->depends_on ?? [], $keys) !== [] && ! in_array($candidate->step_key, $keys, true)) {
                    $keys[] = $candidate->step_key;
                }
            }
        } while ($count !== count($keys));

        return $keys;
    }

    /** @return array<int,array{key:string,type:string,version:int,depends_on:array<int,string>,requires_approval:bool,max_attempts:int,payload:array<string,mixed>}> */
    public function assertDefinition(array $definition): array
    {
        return $this->definitionValidator->validate($definition);
    }
}
