<?php

namespace App\Console\Commands;

use App\Models\WorkflowRun;
use App\Services\WorkflowService;
use Illuminate\Console\Command;

class AdvanceWorkflowRuns extends Command
{
    protected $signature = 'luczor:advance-workflows {--limit=250 : Maximum active workflow runs to scan}';
    protected $description = 'Advance due workflow steps and enqueue safe automated steps.';

    public function handle(WorkflowService $workflows): int
    {
        $count = 0;
        WorkflowRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->orderBy('id')
            ->limit(max(1, min(5000, (int) $this->option('limit'))))
            ->each(function (WorkflowRun $run) use ($workflows, &$count) {
                $workflows->syncChildWorkflows($run);    // P14 — settle finished child workflows
                $workflows->expireTimedOutSteps($run->fresh());   // P15 — fail steps stuck past their timeout
                $workflows->advance($run->fresh());
                $count++;
            });
        $this->info("Advanced {$count} workflow run(s).");
        return self::SUCCESS;
    }
}
