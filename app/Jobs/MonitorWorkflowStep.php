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

    public function __construct(public int $workflowRunId)
    {
    }

    public function handle(WorkflowService $workflows): void
    {
        $run = WorkflowRun::find($this->workflowRunId);
        if (! $run || in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }
        $workflows->syncChildWorkflows($run);   // P14 — settle finished child workflows
        $workflows->expireTimedOutSteps($run->fresh());
        $workflows->advance($run->fresh());
    }
}
