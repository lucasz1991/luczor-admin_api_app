<?php

namespace App\Jobs;

use App\Models\WorkflowRun;
use App\Services\WorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * SOLL §14 P15 — polls an in-flight workflow run: expires timed-out steps and
 * advances the run (self-perpetuating chain, adapted from AUF MonitorWorkflowStepRunJob).
 */
class MonitorWorkflowStep implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public int $workflowRunId) {}

    public function handle(WorkflowService $workflows): void
    {
        $run = WorkflowRun::find($this->workflowRunId);
        if (! $run || in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }
        $workflows->syncChildWorkflows($run);   // P14 — settle finished child workflows
        $workflows->settleWaitSteps($run->fresh());     // P15b — elapsed wait.seconds
        $workflows->syncDeviceJobSteps($run->fresh());  // P15b — finished device bundles
        $workflows->expireTimedOutSteps($run->fresh());
        $result = $workflows->advance($run->fresh());

        // P15b — self-perpetuating poll while steps wait on time or a device
        // (skipped on the sync driver, where delays run inline; the minute
        // sweeper luczor:advance-workflows covers that case in production too).
        if (config('queue.default') !== 'sync'
            && ! in_array($result->status, ['completed', 'failed', 'cancelled'], true)
            && $result->steps->contains(fn ($step) => $step->status === 'running'
                && ($step->type === 'wait.seconds' || $step->external_run_type === 'device_job'))) {
            $workflows->scheduleMonitor($result, 5);
        }
    }
}
