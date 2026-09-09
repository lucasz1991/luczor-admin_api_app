<?php

namespace App\Services;

use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Control state is persistent; each body is an ordinary frozen child WorkflowRun. */
class WorkflowStructuredControl
{
    public function sync(WorkflowStep $candidate): void
    {
        $launch = [];
        $failedChildren = [];
        $failure = null;
        DB::transaction(function () use ($candidate, &$launch, &$failedChildren, &$failure) {
            app(WorkflowBudgetService::class)->root($candidate->run);
            $step = WorkflowStep::query()->lockForUpdate()->findOrFail($candidate->id);
            $parent = $step->run;
            if ($step->status !== 'running' || $parent->status !== 'running') {
                return;
            }
            $payload = $step->resolved_payload ?? $step->payload;
            $state = $step->control_state ?? ['next' => 0, 'children' => [], 'results' => []];
            $root = app(WorkflowBudgetService::class)->root($parent);
            if (WorkflowBoundaryStop::requested($root)) {
                return;
            }
            $limit = min($root->budgets['max_loop_iterations'] ?? 10, $payload['max_iterations'] ?? 10);
            $children = WorkflowRun::whereIn('id', array_values($state['children']))->get()->keyBy('id');
            foreach ($state['children'] as $index => $id) {
                $child = $children->get($id);
                abort_unless($child !== null, 409, 'workflow_control_child_missing');
                if (in_array($child->status, ['failed', 'cancelled'], true)) {
                    $failedChildren = $children->values()->all();
                    $failure = 'workflow_control_child_'.$child->status;

                    return;
                }
                if ($child->status === 'completed' && ! array_key_exists($index, $state['results'])) {
                    $state['results'][$index] = $child->steps()->where('status', 'completed')->get()->mapWithKeys(fn ($s) => [$s->step_key => $s->output])->all();
                }
            }
            $pending = count($state['children']) - count($state['results']);
            $count = match ($step->type) {
                'control.foreach' => count($payload['items']),
                'control.parallel' => count($payload['branches']),
                default => $limit,
            };
            abort_if($step->type === 'control.foreach' && $count > $limit, 422, 'workflow_loop_iteration_budget');
            $done = $state['next'] >= $count && $pending === 0;
            if ($step->type === 'control.until' && $pending === 0 && $state['results'] !== []) {
                $last = $state['results'][array_key_last($state['results'])];
                $done = app(WorkflowDataTasks::class)->condition($last, $payload['condition']);
                abort_if(! $done && $state['next'] >= $limit, 422, 'workflow_loop_iteration_budget');
            }
            if ($done) {
                ksort($state['results'], SORT_NUMERIC);
                $step->update(['control_state' => $state]);
                app(WorkflowService::class)->complete($step, ['outcome' => 'success', 'data' => array_values($state['results']), 'iterations' => $state['next']]);

                return;
            }
            $parallel = $step->type === 'control.parallel' ? ($root->budgets['max_parallel'] ?? 2) : 1;
            while ($pending < $parallel && $state['next'] < $count) {
                $index = $state['next'];
                $snapshot = $parent->definition_snapshot['controls'][$step->step_key][$step->type === 'control.parallel' ? $index : 0] ?? null;
                abort_unless(is_array($snapshot), 409, 'workflow_control_snapshot_missing');
                $input = array_merge($parent->input ?? [], ['index' => $index, 'iteration' => $index + 1, 'previous' => $state['results'] === [] ? [] : $state['results'][array_key_last($state['results'])]]);
                if ($step->type === 'control.foreach') {
                    $input['item'] = $payload['items'][$index];
                }
                $context = array_merge($parent->context['_execution'] ?? [], ['_snapshot' => $snapshot, '_root_workflow_run_id' => $root->id]);
                $child = app(WorkflowService::class)->createRun($parent->definition, $input, $parent->agent_run_id, $parent->sandbox, $context);
                $child->update(['parent_workflow_run_id' => $parent->id, 'parent_workflow_step_id' => $step->id, 'parent_execution_id' => (string) Str::uuid()]);
                $state['children'][$index] = $child->id;
                $state['next']++;
                $pending++;
                $launch[] = $child;
            }
            $step->update(['control_state' => $state]);
        }, 3);
        if ($failure !== null) {
            foreach ($failedChildren as $child) {
                if (! in_array($child->status, ['failed', 'completed', 'cancelled'], true)) {
                    app(WorkflowService::class)->cancel($child);
                }
            }
            foreach ($failedChildren as $child) {
                if (! in_array($child->fresh()->status, ['failed', 'completed', 'cancelled'], true)) {
                    return;
                }
            }
            abort(422, $failure);
        }
        foreach ($launch as $child) {
            app(WorkflowService::class)->advance($child);
        }
    }
}
