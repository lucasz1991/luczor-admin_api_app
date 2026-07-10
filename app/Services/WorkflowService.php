<?php

namespace App\Services;

use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Jobs\ExecuteWorkflowStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Persistent dependency scheduler. It never executes arbitrary shell code. */
class WorkflowService
{
    /** @param array<string,mixed> $definition */
    public function createRun(WorkflowDefinition $definition, array $input = [], ?int $agentRunId = null): WorkflowRun
    {
        $steps = $this->assertDefinition($definition->definition ?? []);

        return DB::transaction(function () use ($definition, $input, $agentRunId, $steps) {
            $run = WorkflowRun::create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $definition->user_id,
                'project_id' => $definition->project_id,
                'workflow_definition_id' => $definition->id,
                'agent_run_id' => $agentRunId,
                'status' => 'queued',
                'input' => $input,
            ]);
            foreach ($steps as $position => $step) {
                $run->steps()->create([
                    'user_id' => $run->user_id,
                    'step_key' => $step['key'],
                    'type' => $step['type'],
                    'position' => $position,
                    'max_attempts' => $step['max_attempts'],
                    'requires_approval' => $step['requires_approval'],
                    'depends_on' => $step['depends_on'],
                    'payload' => $step['payload'],
                    'status' => 'queued',
                    'available_at' => now(),
                ]);
            }

            return $run;
        });
    }

    public function advance(WorkflowRun $run): WorkflowRun
    {
        $readyStepIds = [];
        $result = DB::transaction(function () use ($run, &$readyStepIds) {
            $run = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($run->status, ['cancelled', 'completed', 'failed'], true)) {
                return $run;
            }
            if (! $run->started_at) {
                $run->update(['status' => 'running', 'started_at' => now()]);
            }

            $steps = $run->steps()->orderBy('position')->get();
            $completed = $steps->where('status', 'completed')->pluck('step_key')->all();
            foreach ($steps->where('status', 'queued') as $step) {
                if ($step->available_at?->isFuture()) {
                    continue;
                }
                $dependencies = $step->depends_on ?? [];
                if (array_diff($dependencies, $completed) !== []) {
                    continue;
                }
                $step->update([
                    'status' => $step->requires_approval || $step->type === 'approval' ? 'awaiting_approval' : 'ready',
                ]);
            }

            $fresh = $run->steps()->get();
            $readyStepIds = $fresh
                ->filter(fn (WorkflowStep $step) => $step->status === 'ready' && in_array($step->type, ['context', 'review', 'evaluator'], true))
                ->pluck('id')
                ->all();
            if ($fresh->isNotEmpty() && $fresh->every(fn (WorkflowStep $step) => $step->status === 'completed')) {
                $run->update(['status' => 'completed', 'finished_at' => now()]);
            } elseif ($fresh->contains(fn (WorkflowStep $step) => $step->status === 'failed' && $step->attempts >= $step->max_attempts)) {
                $run->update(['status' => 'failed', 'finished_at' => now()]);
            }

            return $run->fresh(['steps', 'definition']);
        });
        foreach ($readyStepIds as $stepId) {
            ExecuteWorkflowStep::dispatch($stepId);
        }

        return $result;
    }

    public function complete(WorkflowStep $step, array $output = []): WorkflowRun
    {
        abort_unless(in_array($step->status, ['ready', 'running', 'awaiting_approval'], true), 409, 'Workflow step is not ready.');
        $step->update(['status' => 'completed', 'output' => $output, 'finished_at' => now(), 'error' => null]);

        return $this->advance($step->run);
    }

    public function fail(WorkflowStep $step, string $error): WorkflowRun
    {
        abort_unless(in_array($step->status, ['ready', 'running'], true), 409, 'Workflow step is not running.');
        $attempts = $step->attempts + 1;
        $retry = $attempts < $step->max_attempts;
        $step->update([
            'attempts' => $attempts,
            'status' => $retry ? 'queued' : 'failed',
            'error' => $error,
            'available_at' => $retry ? now()->addSeconds(min(60, 2 ** $attempts)) : null,
            'finished_at' => $retry ? null : now(),
        ]);

        return $this->advance($step->run);
    }

    public function cancel(WorkflowRun $run): WorkflowRun
    {
        return DB::transaction(function () use ($run) {
            $run = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
                $run->update(['status' => 'cancelled', 'finished_at' => now()]);
                $run->steps()->whereIn('status', ['queued', 'ready', 'awaiting_approval', 'running'])->update([
                    'status' => 'cancelled', 'finished_at' => now(),
                ]);
            }
            return $run->fresh(['steps', 'definition']);
        });
    }

    /** @return array<int,array{key:string,type:string,depends_on:array<int,string>,requires_approval:bool,max_attempts:int,payload:array<string,mixed>}> */
    public function assertDefinition(array $definition): array
    {
        $steps = $definition['steps'] ?? [];
        abort_unless(is_array($steps) && count($steps) > 0 && count($steps) <= 100, 422, 'A workflow requires between 1 and 100 steps.');
        $keys = [];
        $out = [];
        foreach ($steps as $step) {
            abort_unless(is_array($step), 422, 'Invalid workflow step.');
            $key = trim((string) ($step['key'] ?? ''));
            $type = trim((string) ($step['type'] ?? ''));
            abort_unless($key !== '' && preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $key), 422, 'Invalid workflow step key.');
            abort_unless(in_array($type, ['context', 'llm', 'evaluator', 'review', 'device_job', 'approval', 'manual'], true), 422, 'Invalid workflow step type.');
            abort_unless(! isset($keys[$key]), 422, 'Workflow step keys must be unique.');
            $keys[$key] = true;
            $out[] = [
                'key' => $key,
                'type' => $type,
                'depends_on' => array_values(array_filter((array) ($step['depends_on'] ?? []), 'is_string')),
                'requires_approval' => (bool) ($step['requires_approval'] ?? false),
                'max_attempts' => max(1, min(10, (int) ($step['max_attempts'] ?? 2))),
                'payload' => is_array($step['payload'] ?? null) ? $step['payload'] : [],
            ];
        }
        foreach ($out as $step) {
            foreach ($step['depends_on'] as $dependency) {
                abort_unless(isset($keys[$dependency]) && $dependency !== $step['key'], 422, 'Workflow dependency does not exist.');
            }
        }

        return $out;
    }
}
