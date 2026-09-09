<?php

namespace App\Services;

use App\Models\DeviceJob;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Illuminate\Support\Facades\DB;

/** Stops future dispatch while preserving the real completion of already running effects. */
class WorkflowBoundaryStop
{
    public static function requested(WorkflowRun $root): bool
    {
        return isset($root->budget_state['boundary_stop']['requested_at']);
    }

    public function request(WorkflowRun $run): WorkflowRun
    {
        return DB::transaction(function () use ($run) {
            $root = app(WorkflowBudgetService::class)->root($run);
            if (in_array($root->status, ['completed', 'failed', 'cancelled'], true)) {
                return $root->fresh(['steps']);
            }
            abort_unless($root->status === 'running', 409, 'workflow_boundary_stop_unavailable');
            if (! self::requested($root)) {
                $state = $root->budget_state ?? [];
                $state['boundary_stop'] = ['status' => 'pending', 'requested_at' => now()->toISOString()];
                $root->update(['budget_state' => $state]);
            }

            return $this->settle($root);
        }, 3);
    }

    public function settle(WorkflowRun $run): WorkflowRun
    {
        return DB::transaction(function () use ($run) {
            $root = app(WorkflowBudgetService::class)->root($run);
            if (! self::requested($root) || $root->status !== 'running') {
                return $root->fresh(['steps']);
            }
            $runIds = WorkflowRun::where('id', $root->id)->orWhere('root_workflow_run_id', $root->id)->pluck('id');
            $steps = WorkflowStep::whereIn('workflow_run_id', $runIds)->get();
            $executionIds = $steps->pluck('execution_id');
            // The queued->running transaction owns the same job row: whichever commits first determines whether the effect has started.
            DeviceJob::whereIn('workflow_execution_id', $executionIds)->whereIn('status', ['queued', 'approval_required'])
                ->update(['status' => 'cancelled', 'cancel_requested_at' => now(), 'finished_at' => now()]);
            foreach ($steps as $step) {
                $waiting = in_array($step->status, ['queued', 'ready', 'awaiting_approval', 'waiting_for_device', 'waiting_for_capability'], true);
                if ($step->status === 'running' && WorkflowTaskCatalog::isClientTask($step->type)) {
                    $job = DeviceJob::where('workflow_execution_id', $step->execution_id)->first();
                    $waiting = ! $job || $job->status === 'cancelled';
                }
                if ($waiting) {
                    app(WorkflowBudgetService::class)->settle($step, 'cancelled', []);
                    $step->update(['status' => 'cancelled', 'finished_at' => now()]);
                }
            }
            $activeJobs = DeviceJob::whereIn('workflow_execution_id', $executionIds)->where('status', 'running')->exists();
            $activeSteps = WorkflowStep::whereIn('workflow_run_id', $runIds)->where('status', 'running')->get()
                ->contains(fn ($step) => WorkflowBudgetService::occupiesSlot($step->type));
            if ($activeJobs || $activeSteps) {
                return $root->fresh(['steps']);
            }
            app(WorkflowBudgetService::class)->account($root);
            $state = $root->fresh()->budget_state;
            $state['boundary_stop']['status'] = 'completed';
            $state['boundary_stop']['finished_at'] = now()->toISOString();
            $state['stop_reason'] = 'workflow_boundary_stop';
            $root->update(['budget_state' => $state]);

            return app(WorkflowService::class)->cancel($root);
        }, 3);
    }
}
