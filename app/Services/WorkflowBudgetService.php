<?php

namespace App\Services;

use App\Models\DeviceJob;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** All descendants claim work against one locked root; controls are not a second scheduler. */
class WorkflowBudgetService
{
    public const DEFAULTS = ['active_seconds' => 2700, 'max_executions' => 200, 'max_loop_iterations' => 10, 'max_parallel' => 2, 'max_repairs' => 2];

    public static function policy(array $definition, array $grant = []): array
    {
        $raw = $definition['budgets'] ?? [];
        abort_unless(is_array($raw) && array_diff(array_keys($raw), array_keys(self::DEFAULTS)) === [], 422, 'workflow_budget_invalid');
        $policy = self::DEFAULTS;
        foreach ($raw as $key => $value) {
            abort_unless(is_int($value) && $value >= ($key === 'max_repairs' ? 0 : 1) && $value <= self::DEFAULTS[$key], 422, 'workflow_budget_out_of_range');
            $policy[$key] = $value;
        }
        if (isset($grant['config']['max_steps'])) {
            $policy['max_executions'] = min($policy['max_executions'], max(1, (int) $grant['config']['max_steps']));
        }

        return $policy;
    }

    public function root(WorkflowRun $run): WorkflowRun
    {
        return WorkflowRun::query()->lockForUpdate()->findOrFail($run->root_workflow_run_id ?: $run->id);
    }

    public function claim(WorkflowStep $candidate): bool
    {
        return DB::transaction(function () use ($candidate) {
            $root = $this->root($candidate->run);
            $step = WorkflowStep::query()->lockForUpdate()->findOrFail($candidate->id);
            $run = $step->run()->firstOrFail();
            if ($root->status !== 'running' || $run->status !== 'running' || $step->status !== 'ready' || WorkflowBoundaryStop::requested($root)) {
                return false;
            }
            if (($root->definition_snapshot['definition']['schema_version'] ?? 1) === 1 && ($run->definition_snapshot['definition']['schema_version'] ?? 1) === 1) {
                return WorkflowStep::whereKey($candidate->id)->where('status', 'ready')->update(['status' => 'running', 'started_at' => now()]) === 1;
            }
            $this->account($root);
            $state = $root->budget_state ?? [];
            $policy = $root->budgets ?? self::DEFAULTS;
            if (($state['executions'] ?? 0) >= $policy['max_executions'] || ($state['active_ms'] ?? 0) >= $policy['active_seconds'] * 1000) {
                $state['stop_reason'] = 'workflow_budget_exhausted';
                $root->update(['budget_state' => $state]);
                app(WorkflowService::class)->cancel($root);

                return false;
            }
            $active = $this->activeSteps($root)->get();
            if (self::occupiesSlot($step->type) && $active->count() >= $policy['max_parallel']) {
                return false;
            }
            $resource = $this->resource($step);
            if ($resource !== null && $active->contains(fn ($other) => $this->resource($other) === $resource)) {
                return false;
            }
            $inserted = DB::table('workflow_execution_records')->insertOrIgnore([
                'root_workflow_run_id' => $root->id, 'workflow_step_id' => $step->id,
                'execution_id' => $step->execution_id, 'attempt' => $step->attempts + 1,
                'status' => 'running', 'started_at' => now(),
            ]);
            if ($inserted === 0) {
                return false;
            }
            $state['executions'] = ($state['executions'] ?? 0) + 1;
            $state['last_accounted_at'] = now()->toISOString();
            $root->update(['budget_state' => $state]);
            $step->update(['status' => 'running', 'started_at' => now()]);

            return true;
        }, 3);
    }

    public function account(WorkflowRun $root): void
    {
        $state = $root->budget_state ?? [];
        $last = $state['last_accounted_at'] ?? null;
        if ($last) {
            $from = Carbon::parse($last)->getTimestampMs();
            $until = now()->getTimestampMs();
            // Union of actual execution intervals: queue/approval/wait time contributes nothing.
            $records = DB::table('workflow_execution_records as records')
                ->join('workflow_steps as steps', 'steps.id', '=', 'records.workflow_step_id')
                ->leftJoin('device_jobs as jobs', 'jobs.workflow_execution_id', '=', 'records.execution_id')
                ->where('records.root_workflow_run_id', $root->id)
                ->where(fn ($query) => $query->whereNull('records.finished_at')->orWhere('records.finished_at', '>=', Carbon::parse($last)))
                ->get(['steps.type', 'records.started_at', 'records.finished_at', 'jobs.id as device_job_id', 'jobs.started_at as device_started_at', 'jobs.finished_at as device_finished_at']);
            $intervals = [];
            foreach ($records as $record) {
                if (! self::occupiesSlot($record->type)) {
                    continue;
                }
                $client = WorkflowTaskCatalog::isClientTask($record->type);
                $start = $client ? $record->device_started_at : $record->started_at;
                $end = $client ? $record->device_finished_at : $record->finished_at;
                if (! $start) {
                    continue;
                }
                $startMs = max($from, Carbon::parse($start)->getTimestampMs());
                $endMs = min($until, $end ? Carbon::parse($end)->getTimestampMs() : $until);
                if ($endMs > $startMs) {
                    $intervals[] = [$startMs, $endMs];
                }
            }
            usort($intervals, fn ($left, $right) => $left[0] <=> $right[0]);
            $cursor = $from;
            $elapsed = 0;
            foreach ($intervals as [$start, $end]) {
                $elapsed += max(0, $end - max($start, $cursor));
                $cursor = max($cursor, $end);
            }
            $state['active_ms'] = ($state['active_ms'] ?? 0) + $elapsed;
        }
        $state['last_accounted_at'] = now()->toISOString();
        $root->update(['budget_state' => $state]);
    }

    public function settle(WorkflowStep $step, string $status, array $result): void
    {
        $root = $this->root($step->run);
        $this->account($root);
        DB::table('workflow_execution_records')->where('execution_id', $step->execution_id)
            ->where('attempt', $step->attempts + 1)->where('status', 'running')
            ->update(['status' => $status, 'finished_at' => now(), 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);
    }

    private function activeSteps(WorkflowRun $root)
    {
        return WorkflowStep::whereIn('workflow_run_id', WorkflowRun::where('root_workflow_run_id', $root->id)->orWhere('id', $root->id)->select('id'))
            ->where(fn ($query) => $query->where('status', 'running')->orWhereIn('external_run_id', DeviceJob::where('status', 'running')->select('public_id')))
            ->whereNotIn('type', ['workflow', 'wait.seconds', 'control.foreach', 'control.until', 'control.parallel']);
    }

    public static function occupiesSlot(string $type): bool
    {
        return ! in_array($type, ['workflow', 'wait.seconds', 'control.foreach', 'control.until', 'control.parallel'], true);
    }

    private function resource(WorkflowStep $step): ?string
    {
        $payload = $step->resolved_payload ?? $step->payload;
        $device = $step->control_state['admitted_device_id'] ?? $step->run->context['_execution']['device_id'] ?? $payload['device_id'] ?? '';
        if (str_starts_with($step->type, 'browser.')) {
            $session = $payload['browser_session_id'] ?? 'default';
            abort_unless(is_string($session), 422, 'workflow_browser_session_unresolved');

            return 'browser:'.$device.':'.$session;
        }
        if ($step->type === 'llm' || str_starts_with($step->type, 'llm.') || str_starts_with($step->type, 'agent.')) {
            return 'inference:'.$device;
        }

        return null;
    }
}
