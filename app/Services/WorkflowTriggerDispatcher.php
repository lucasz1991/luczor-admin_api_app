<?php

namespace App\Services;

use App\Models\Project;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowTrigger;
use App\Models\WorkflowTriggerDelivery;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkflowTriggerDispatcher
{
    public function dispatchPending(int $limit = 250): int
    {
        $this->settleRuns($limit);
        $count = 0;
        $ids = WorkflowTriggerDelivery::whereIn('status', ['pending', 'waiting'])->where('available_at', '<=', now())
            ->whereHas('trigger', fn ($query) => $query->where('enabled', true))->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            try {
                $run = DB::transaction(function () use ($id) {
                    $delivery = WorkflowTriggerDelivery::whereKey($id)->lockForUpdate()->firstOrFail();
                    if (! in_array($delivery->status, ['pending', 'waiting'], true) || $delivery->workflow_run_id) {
                        return null;
                    }
                    $trigger = $delivery->trigger;
                    if (! $trigger || ! $trigger->enabled) {
                        return null;
                    }
                    $definition = WorkflowDefinition::whereKey($trigger->workflow_definition_id)->lockForUpdate()->firstOrFail();
                    // Match authoring's definition-before-trigger lock order, then recheck after selection.
                    $trigger = WorkflowTrigger::whereKey($trigger->id)->lockForUpdate()->first();
                    if (! $trigger || ! $trigger->enabled) {
                        return null;
                    }
                    if ($definition->status !== 'active') {
                        $delivery->update(['status' => 'waiting', 'last_error' => 'Workflow is inactive.', 'available_at' => now()->addMinute()]);

                        return null;
                    }
                    // Serialize automatic starts across all subscriptions of the workflow.
                    $triggerIds = WorkflowTrigger::withTrashed()->where('workflow_definition_id', $definition->id)->pluck('id');
                    $enabledTriggerIds = WorkflowTrigger::where('workflow_definition_id', $definition->id)->where('enabled', true)->pluck('id');
                    $earlier = WorkflowTriggerDelivery::whereIn('workflow_trigger_id', $enabledTriggerIds)->where('id', '<', $delivery->id)->whereIn('status', ['pending', 'waiting'])->exists();
                    $active = WorkflowTriggerDelivery::whereIn('workflow_trigger_id', $triggerIds)->where('status', 'running')->exists();
                    if ($earlier || $active) {
                        return null;
                    }
                    $event = $delivery->event;
                    $grant = app(AutomationGrantService::class)->current($definition);
                    $project = $definition->project_id ? Project::find($definition->project_id) : null;
                    $chain = array_merge($event->causation['causal_trigger_ids'] ?? [], [(int) $trigger->id]);
                    $context = [
                        'automatic' => true, 'trigger_id' => $trigger->id, 'event_id' => $event->public_id,
                        'root_event_id' => $event->causation['root_event_id'] ?? $event->public_id,
                        'causal_trigger_ids' => $chain, 'device_id' => $trigger->config['device_id'] ?? $grant?->device_id,
                        'project_id' => $project?->external_id, 'root_path' => $grant?->config['root_path'] ?? null,
                        'event' => ['id' => $event->public_id, 'kind' => $event->kind, 'payload' => $delivery->event_payload ?? $event->payload, 'occurred_at' => $event->occurred_at->toISOString()],
                        'operation_id' => 'workflow-delivery:'.$delivery->id,
                    ];
                    $run = app(WorkflowService::class)->createRun($definition, $trigger->input ?? [], null, false, $context);
                    $delivery->update(['workflow_run_id' => $run->id, 'status' => 'running', 'started_at' => now(), 'attempts' => $delivery->attempts + 1, 'last_error' => null]);

                    return $run;
                });
                if ($run) {
                    // Durable admission precedes queue dispatch; recovery advances a queued run after a crash here.
                    app(WorkflowService::class)->advance($run);
                    $count++;
                }
            } catch (Throwable $error) {
                WorkflowTriggerDelivery::whereKey($id)->whereNull('workflow_run_id')->update([
                    'status' => 'waiting', 'last_error' => mb_substr($error->getMessage(), 0, 1000), 'available_at' => now()->addMinute(),
                ]);
            }
        }

        return $count;
    }

    private function settleRuns(int $limit): void
    {
        foreach (WorkflowTriggerDelivery::where('status', 'running')->whereNotNull('workflow_run_id')->limit($limit)->get() as $delivery) {
            $run = WorkflowRun::find($delivery->workflow_run_id);
            if (! $run) {
                $delivery->update(['status' => 'failed', 'last_error' => 'Workflow run is missing.', 'finished_at' => now()]);
            } elseif (in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
                $delivery->update(['status' => $run->status, 'finished_at' => $run->finished_at ?? now()]);
            } elseif ($run->status === 'queued') {
                app(WorkflowService::class)->advance($run);
            }
        }
    }
}
