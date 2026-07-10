<?php

namespace App\Jobs;

use App\Services\WorkflowStepExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExecuteWorkflowStep implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public int $workflowStepId)
    {
    }

    public function handle(WorkflowStepExecutor $executor): void
    {
        $executor->execute($this->workflowStepId);
    }
}
