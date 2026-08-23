<?php

namespace App\Services;

use App\Jobs\ExecuteWorkflowStep;
use App\Jobs\MonitorWorkflowStep;
use App\Models\DeviceJob;
use App\Models\Setting;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunArtifact;
use App\Models\WorkflowStep;
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
    public function createRun(WorkflowDefinition $definition, array $input = [], ?int $agentRunId = null, bool $sandbox = false): WorkflowRun
    {
        $steps = $this->assertDefinition($definition->definition ?? []);
        // P27 — a server-wide sandbox setting forces every run into sandbox mode;
        // an explicit per-run flag can only add to that, never override it off.
        $sandbox = $sandbox || Setting::getValue('sandbox_enabled', false) === true;

        return DB::transaction(function () use ($definition, $input, $agentRunId, $steps, $sandbox) {
            $run = WorkflowRun::create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $definition->user_id,
                'project_id' => $definition->project_id,
                'workflow_definition_id' => $definition->id,
                'agent_run_id' => $agentRunId,
                'status' => 'queued',
                'sandbox' => $sandbox,
                'input' => $input,
            ]);
            foreach ($steps as $position => $step) {
                $run->steps()->create([
                    'user_id' => $run->user_id,
                    'step_key' => $step['key'],
                    'type' => $step['type'],
                    'position' => $position,
                    'max_attempts' => $step['max_attempts'],
                    'requires_approval' => $step['requires_approval'],
                    'depends_on' => $step['depends_on'],
                    'payload' => $step['payload'],
                    'status' => 'queued',
                    'available_at' => now(),
                ]);
            }

            return $run;
        });
    }

    public function advance(WorkflowRun $run): WorkflowRun
    {
        $readyStepIds = [];
        $result = DB::transaction(function () use ($run, &$readyStepIds) {
            $run = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($run->status, ['cancelled', 'completed', 'failed'], true)) {
                return $run;
            }
            if (! $run->started_at) {
                $run->update(['status' => 'running', 'started_at' => now()]);
            }

            $steps = $run->steps()->orderBy('position')->get();
            $completed = $steps->where('status', 'completed')->pluck('step_key')->all();
            foreach ($steps->where('status', 'queued') as $step) {
                if ($step->available_at?->isFuture()) {
                    continue;
                }
                $dependencies = $step->depends_on ?? [];
                if (array_diff($dependencies, $completed) !== []) {
                    continue;
                }
                $step->update([
                    'status' => $step->requires_approval || $step->type === 'approval' ? 'awaiting_approval' : 'ready',
                ]);
            }

            $fresh = $run->steps()->get();
            // P15b — every auto-dispatchable catalog type (server branches and
            // client/device bundles) is handed to the executor; llm/manual/
            // approval/device_job stay externally completed.
            $readyStepIds = $fresh
                ->filter(fn (WorkflowStep $step) => $step->status === 'ready' && WorkflowTaskCatalog::isAutoDispatch($step->type))
                ->pluck('id')
                ->all();
            if ($fresh->isNotEmpty() && $fresh->every(fn (WorkflowStep $step) => $step->status === 'completed')) {
                $run->update(['status' => 'completed', 'finished_at' => now(), 'duration_ms' => $this->runDurationMs($run)]);
            } elseif ($fresh->contains(fn (WorkflowStep $step) => $step->status === 'failed' && $step->attempts >= $step->max_attempts)) {
                $run->update(['status' => 'failed', 'finished_at' => now(), 'duration_ms' => $this->runDurationMs($run)]);
            }

            return $run->fresh(['steps', 'definition']);
        });
        foreach ($readyStepIds as $stepId) {
            ExecuteWorkflowStep::dispatch($stepId);
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
        abort_unless(in_array($step->status, ['ready', 'running', 'awaiting_approval'], true), 409, 'Workflow step is not ready.');
        $step->update([
            'status' => 'completed', 'output' => $output, 'finished_at' => now(), 'error' => null,
            'duration_ms' => $this->stepDurationMs($step),
        ]);

        // P13 — outcome-based routing (branch / loop / terminate) if declared.
        $routed = $this->applyRoutes($step->fresh(), WorkflowResultNormalizer::outcome($output, 'success'));

        $result = $routed ?? $this->advance($step->run);
        $this->notifyTerminalRun($result);

        return $result;
    }

    public function fail(WorkflowStep $step, string $error, string $outcome = 'failed'): WorkflowRun
    {
        abort_unless(in_array($step->status, ['ready', 'running'], true), 409, 'Workflow step is not running.');
        $attempts = $step->attempts + 1;
        $retry = $attempts < $step->max_attempts;
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
        if (! $retry) {
            $routed = $this->applyRoutes($step->fresh(), $outcome);
            if ($routed) {
                $this->notifyTerminalRun($routed);

                return $routed;
            }
        }

        return $this->advance($step->run);
    }

    /** Schedule a delayed poll of an in-flight run (timeout expiry + advance). */
    public function scheduleMonitor(WorkflowRun $run, int $seconds = 10): void
    {
        MonitorWorkflowStep::dispatch($run->id)->delay(now()->addSeconds(max(1, $seconds)));
    }

    /**
     * SOLL §14 P14 — start a child WorkflowRun for a nested-workflow step, with a
     * cycle guard. The parent step stays 'running' until the child terminates
     * (see syncChildWorkflows()).
     */
    public function startChildWorkflow(WorkflowStep $parentStep): WorkflowRun
    {
        $childId = (int) ($parentStep->payload['workflow_definition_id'] ?? 0);
        $childDef = WorkflowDefinition::findOrFail($childId);
        $parentRun = $parentStep->run;
        $parentDef = $parentRun->definition;

        abort_if(
            $parentDef && ($childDef->id === $parentDef->id || $childDef->includesDefinition($parentDef->id)),
            422,
            'Nested workflow cycle detected.'
        );

        if ($parentStep->status !== 'running') {
            $parentStep->update(['status' => 'running', 'started_at' => $parentStep->started_at ?? now()]);
        }

        $input = is_array($parentStep->payload['input'] ?? null) ? $parentStep->payload['input'] : [];
        $child = $this->createRun($childDef, $input, $parentRun->agent_run_id);
        $child->update(['parent_workflow_run_id' => $parentRun->id, 'parent_workflow_step_id' => $parentStep->id]);
        $this->advance($child->fresh());

        return $child->fresh();
    }

    /**
     * Complete/fail parent nested-workflow steps whose child run has terminated.
     * Called from the monitor/advance chain.
     */
    public function syncChildWorkflows(WorkflowRun $parentRun): int
    {
        $synced = 0;
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
                $this->complete($step->fresh(), ['device_job' => $job->public_id, 'result' => $job->result ?? []]);
                $synced++;
            } elseif (in_array($job->status, ['failed', 'rejected'], true)) {
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
            $seconds = max(1, min(3600, (int) (($step->payload['seconds'] ?? null) ?: 5)));
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
            $timeout = (int) ($step->payload['timeout_seconds']
                ?? WorkflowTaskCatalog::task($step->type)['timeout_seconds']
                ?? 300);
            if ($step->started_at && $step->started_at->copy()->addSeconds($timeout)->isPast()) {
                $this->fail($step, 'step_timeout', 'timeout');
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
            $run->update(['status' => $type === 'end' ? 'completed' : 'failed', 'finished_at' => now(), 'duration_ms' => $this->runDurationMs($run)]);

            return $run->fresh(['steps', 'definition']);
        }

        if ($type !== 'step') {
            return null;
        }

        $targetKey = (string) ($route['step_key'] ?? '');
        $limit = max(1, (int) ($route['max_iterations'] ?? 2));
        $output = is_array($run->output) ? $run->output : [];
        $hits = is_array($output['_route_hits'] ?? null) ? $output['_route_hits'] : [];
        $counter = (int) ($hits[$targetKey] ?? 0) + 1;

        // Loop guard: a bounded number of jumps to the same target, then fail.
        if ($counter > $limit) {
            $output['_route_error'] = 'loop_limit_exceeded:'.$targetKey;
            $run->update(['status' => 'failed', 'finished_at' => now(), 'output' => $output, 'duration_ms' => $this->runDurationMs($run)]);

            return $run->fresh(['steps', 'definition']);
        }

        $hits[$targetKey] = $counter;
        $output['_route_hits'] = $hits;
        $run->update(['output' => $output]);

        $target = $run->steps()->where('step_key', $targetKey)->first();
        if ($target) {
            $target->update([
                'status' => 'queued', 'attempts' => 0, 'output' => null, 'error' => null,
                'available_at' => now(), 'started_at' => null, 'finished_at' => null,
            ]);
        }

        return $this->advance($run);
    }

    public function cancel(WorkflowRun $run): WorkflowRun
    {
        $result = DB::transaction(function () use ($run) {
            $run = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
                $run->update(['status' => 'cancelled', 'finished_at' => now()]);
                $run->steps()->whereIn('status', ['queued', 'ready', 'awaiting_approval', 'running'])->update([
                    'status' => 'cancelled', 'finished_at' => now(),
                ]);
            }

            return $run->fresh(['steps', 'definition']);
        });
        $this->notifyTerminalRun($result);

        return $result;
    }

    private function notifyTerminalRun(WorkflowRun $run): void
    {
        $this->runNotifier->notifyTerminal($run);
    }

    /** @return array<int,array{key:string,type:string,depends_on:array<int,string>,requires_approval:bool,max_attempts:int,payload:array<string,mixed>}> */
    public function assertDefinition(array $definition): array
    {
        return $this->definitionValidator->validate($definition);
    }
}
