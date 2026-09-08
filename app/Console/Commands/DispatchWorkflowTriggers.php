<?php

namespace App\Console\Commands;

use App\Services\WorkflowEventService;
use App\Services\WorkflowTriggerDispatcher;
use App\Services\WorkflowTriggerService;
use Illuminate\Console\Command;

class DispatchWorkflowTriggers extends Command
{
    protected $signature = 'luczor:dispatch-workflow-triggers {--limit=250}';

    protected $description = 'Persist due schedules, recover workflow events, and dispatch bounded automatic workflow starts.';

    public function handle(WorkflowTriggerService $triggers, WorkflowEventService $events, WorkflowTriggerDispatcher $dispatcher): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $scheduled = $triggers->enqueueDue($limit);
        $published = $events->publishPending($limit);
        $started = $dispatcher->dispatchPending($limit);
        $this->line("Scheduled {$scheduled}; published {$published}; started {$started}.");

        return self::SUCCESS;
    }
}
