<?php

namespace App\Services;

use App\Models\Task;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Models\WorkflowTrigger;
use App\Models\WorkflowTriggerDelivery;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Database inbox and publication outbox. Every accepted unique event remains inspectable. */
class WorkflowEventService
{
    public function record(int $userId, ?int $projectId, string $kind, string $source, string $eventKey, array $payload, array $causation = [], mixed $occurredAt = null): WorkflowEvent
    {
        abort_unless(strlen(AutomationGrantService::canonicalJson($payload)) <= 65536, 422, 'Workflow event payload exceeds 64 KiB.');
        $event = WorkflowEvent::firstOrCreate(
            ['user_id' => $userId, 'source' => $source, 'event_key' => $eventKey],
            ['public_id' => (string) Str::uuid(), 'project_id' => $projectId, 'kind' => $kind, 'payload' => $payload,
                'causation' => $this->normalizeCausation($causation), 'occurred_at' => $occurredAt ? Carbon::parse($occurredAt)->utc() : now()],
        );
        abort_unless($event->kind === $kind && AutomationGrantService::canonicalJson($event->payload) === AutomationGrantService::canonicalJson($payload), 409, 'Event ID was reused with different content.');

        return $event;
    }

    public function recordWorkflowTerminal(WorkflowRun $run): void
    {
        if (! in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }
        $execution = $run->context['_execution'] ?? [];
        $this->record((int) $run->user_id, $run->project_id, 'workflow.completed', 'workflow', $run->public_id.':'.$run->status, [
            'workflow_definition_id' => $run->workflow_definition_id, 'run_id' => $run->public_id,
            'status' => $run->status, 'finished_at' => $run->finished_at?->toISOString(),
        ], $execution);
    }

    public function recordTaskTerminal(Task $task): void
    {
        if ($task->status !== 'done') {
            return;
        }
        $this->record((int) $task->user_id, $task->project_ref_id, 'task.completed', 'task', $task->id.':'.$task->updated_at->format('U.u'), [
            'task_id' => $task->id, 'external_id' => $task->external_id, 'status' => 'done',
            'completed_at' => $task->completed_at?->toISOString(),
        ], $task->workflow_causation ?? []);
    }

    public function publishPending(int $limit = 250): int
    {
        $count = 0;
        foreach (WorkflowEvent::whereNull('published_at')->orderBy('id')->limit($limit)->pluck('id') as $eventId) {
            DB::transaction(function () use ($eventId, &$count) {
                $event = WorkflowEvent::whereKey($eventId)->lockForUpdate()->firstOrFail();
                if ($event->published_at) {
                    return;
                }
                $triggers = WorkflowTrigger::where('user_id', $event->user_id)->where('kind', $event->kind)
                    ->where('enabled', true)->where('project_id', $event->project_id)->get();
                foreach ($triggers as $trigger) {
                    if (! $this->matches($trigger, $event)) {
                        continue;
                    }
                    $chain = $event->causation['causal_trigger_ids'] ?? [];
                    $loop = count($chain) >= 8 || in_array((int) $trigger->id, $chain, true);
                    $pending = WorkflowTriggerDelivery::where('workflow_trigger_id', $trigger->id)->whereIn('status', ['pending', 'waiting'])->count();
                    $status = $loop ? 'suppressed_loop' : ($pending >= 1000 ? 'overflow' : 'pending');
                    $delay = $trigger->kind === 'workspace.file_changed' ? ($trigger->config['debounce_seconds'] ?? 2) : 0;
                    $delivery = WorkflowTriggerDelivery::firstOrCreate([
                        'workflow_trigger_id' => $trigger->id, 'workflow_event_id' => $event->id,
                    ], ['status' => $status, 'available_at' => now()->addSeconds($delay),
                        'last_error' => $loop ? 'Causal loop suppressed.' : ($status === 'overflow' ? 'Backlog limit reached; event retained for explicit replay.' : null)]);
                    if ($status === 'overflow') {
                        $trigger->update(['last_error' => 'Backlog limit reached; retained events require replay.']);
                    }
                    // Coalescing is limited to timer ticks and metadata file bursts. Distinct business events remain FIFO.
                    if ($delivery->wasRecentlyCreated && $status === 'pending' && in_array($trigger->kind, ['schedule', 'workspace.file_changed'], true)) {
                        $older = WorkflowTriggerDelivery::where('workflow_trigger_id', $trigger->id)->where('id', '<', $delivery->id)->whereIn('status', ['pending', 'waiting'])->get();
                        if ($trigger->kind === 'workspace.file_changed' && $older->isNotEmpty()) {
                            $changes = $event->payload['changes'] ?? [];
                            foreach ($older as $previous) {
                                $changes = array_merge(($previous->event_payload ?? $previous->event?->payload)['changes'] ?? [], $changes);
                            }
                            $byPath = [];
                            foreach ($changes as $change) {
                                $byPath[$change['path']] = $change;
                            }
                            $payload = $event->payload;
                            $payload['changes'] = count($byPath) > 128 ? [['path' => '.', 'kind' => 'rescan']] : array_values($byPath);
                            $payload['coalesced'] = true;
                            $delivery->update(['event_payload' => $payload]);
                        }
                        foreach ($older as $previous) {
                            $previous->update(['status' => 'coalesced', 'finished_at' => now(), 'last_error' => 'Coalesced into delivery '.$delivery->id]);
                        }
                    }
                }
                $event->update(['published_at' => now()]);
                $count++;
            });
        }

        return $count;
    }

    public function matches(WorkflowTrigger $trigger, WorkflowEvent $event): bool
    {
        $config = $trigger->config;
        $payload = $event->payload;
        if (isset($payload['_trigger_id']) && (int) $payload['_trigger_id'] !== (int) $trigger->id) {
            return false;
        }

        return match ($trigger->kind) {
            'webhook', 'schedule', 'workspace.file_changed' => (int) ($payload['_trigger_id'] ?? 0) === (int) $trigger->id,
            'task.completed' => ! isset($config['task_id']) || (int) $config['task_id'] === (int) ($payload['task_id'] ?? 0),
            'workflow.completed' => (! isset($config['workflow_definition_id']) || (int) $config['workflow_definition_id'] === (int) ($payload['workflow_definition_id'] ?? 0))
                && in_array($payload['status'] ?? '', $config['statuses'] ?? ['completed'], true),
            'github.push', 'github.pull_request' => (int) ($config['repository_id'] ?? 0) === (int) ($payload['repository_id'] ?? 0)
                && (! isset($config['branch']) || $config['branch'] === ($payload['branch'] ?? null))
                && (! isset($config['actions']) || in_array($payload['action'] ?? '', $config['actions'], true)),
            default => false,
        };
    }

    private function normalizeCausation(array $value): array
    {
        return [
            'root_event_id' => isset($value['root_event_id']) ? (string) $value['root_event_id'] : null,
            'causal_trigger_ids' => array_values(array_unique(array_map('intval', array_slice($value['causal_trigger_ids'] ?? [], 0, 8)))),
            'parent_run_id' => isset($value['run_id']) ? (string) $value['run_id'] : null,
        ];
    }
}
