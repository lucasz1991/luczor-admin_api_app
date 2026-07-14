<?php

namespace App\Services;

use App\Models\Device;
use App\Models\LlmRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkflowStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Executes only declarative, server-safe workflow step types. */
class WorkflowStepExecutor
{
    public function __construct(
        private WorkflowService $workflows,
        private ContextController $context,
        private EvaluationService $evaluator,
        private AuditLogger $audit,
        private LuczorMemoryService $memory,
        private DeviceJobService $deviceJobs,
    ) {
    }

    public function execute(int $stepId): void
    {
        // Claim atomically so parallel workers cannot run the same step twice.
        $claimed = WorkflowStep::query()->whereKey($stepId)->where('status', 'ready')->update([
            'status' => 'running', 'started_at' => now(),
        ]);
        if ($claimed !== 1) {
            return;
        }

        $step = WorkflowStep::query()->with('run')->findOrFail($stepId);
        // P15 — expose the currently executing step as a run cursor for the UI.
        $step->run?->update(['current_workflow_step_id' => $step->id]);

        // P14 — a nested-workflow step starts a child run and stays 'running'
        // until the child terminates (settled by syncChildWorkflows()).
        if ($step->type === 'workflow') {
            try {
                $this->workflows->startChildWorkflow($step->fresh());
            } catch (Throwable $error) {
                $this->workflows->fail($step->fresh(), mb_substr($error->getMessage(), 0, 8000));
            }

            return;
        }

        // P15b — a wait step parks as 'running'; settleWaitSteps() completes it
        // once the delay elapsed (poked by the monitor chain / minute sweeper).
        if ($step->type === 'wait.seconds') {
            $seconds = max(1, min(3600, (int) ((($step->payload ?? [])['seconds'] ?? null) ?: 5)));
            $this->workflows->scheduleMonitor($step->run, $seconds + 1);

            return;
        }

        // P15b — a client task compiles into a signed device_job bundle and stays
        // 'running' until the device reports; settled by syncDeviceJobSteps().
        if (WorkflowTaskCatalog::isClientTask($step->type)) {
            try {
                $this->startClientTask($step->fresh());
            } catch (Throwable $error) {
                $this->workflows->fail($step->fresh(), mb_substr($error->getMessage(), 0, 8000));
            }

            return;
        }

        try {
            $output = match ($step->type) {
                'context' => $this->context($step),
                'review' => $this->review($step),
                'evaluator' => $this->evaluate($step),
                'memory.remember' => $this->remember($step),
                'memory.recall' => $this->recall($step),
                'task.create' => $this->createTask($step),
                default => throw new \RuntimeException('This workflow step type requires an external approval or device result.'),
            };
            $this->workflows->complete($step->fresh(), $output);
            $this->audit->record([
                'actor_user_id' => $step->user_id,
                'project_id' => $step->run->project_id,
                'event_type' => 'workflow.step_completed',
                'tool' => 'workflow.'.$step->type,
                'risk_level' => 'normal',
                'outcome' => 'completed',
                'payload' => ['workflow_step_id' => $step->id, 'step_key' => $step->step_key],
                'result' => $output,
            ]);
        } catch (Throwable $error) {
            $this->workflows->fail($step->fresh(), mb_substr($error->getMessage(), 0, 8000));
            $this->audit->record([
                'actor_user_id' => $step->user_id,
                'project_id' => $step->run->project_id,
                'event_type' => 'workflow.step_failed',
                'tool' => 'workflow.'.$step->type,
                'risk_level' => 'normal',
                'outcome' => 'failed',
                'payload' => ['workflow_step_id' => $step->id, 'step_key' => $step->step_key],
                'result' => ['error' => mb_substr($error->getMessage(), 0, 8000)],
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function context(WorkflowStep $step): array
    {
        $payload = $step->payload ?? [];
        $project = $step->run->project_id ? Project::find($step->run->project_id) : null;
        $budget = is_array($payload['budget'] ?? null) ? $payload['budget'] : [];
        $result = $this->context->ask([
            'user_id' => $step->user_id,
            'project_id' => $project?->external_id,
            'task_type' => (string) ($payload['task_type'] ?? 'workflow.context'),
            'feature_key' => $payload['feature_key'] ?? null,
            'query' => (string) ($payload['query'] ?? ''),
            'repo_id' => $payload['repo_id'] ?? null,
            'branch' => $payload['branch'] ?? null,
            'commit_sha' => $payload['commit_sha'] ?? null,
            'changed_files' => is_array($payload['changed_files'] ?? null) ? $payload['changed_files'] : [],
            'budget' => [
                'max_input_tokens' => max(100, min(8000, (int) ($budget['max_input_tokens'] ?? 800))),
                'max_items' => max(1, min(20, (int) ($budget['max_items'] ?? 6))),
            ],
        ]);

        return [
            'context_id' => $result['context_id'],
            'estimated_tokens' => $result['budget']['estimated_tokens'] ?? null,
            'source_status' => $result['source_status'] ?? [],
        ];
    }

    /** @return array<string,mixed> */
    private function review(WorkflowStep $step): array
    {
        $required = array_values(array_filter((array) (($step->payload ?? [])['required_output_keys'] ?? []), 'is_string'));
        $dependencies = $step->run->steps()->whereIn('step_key', $step->depends_on ?? [])->get();
        foreach ($required as $key) {
            $hasKey = $dependencies->contains(fn (WorkflowStep $dependency) => array_key_exists($key, $dependency->output ?? []));
            if (! $hasKey) {
                throw new \RuntimeException("Review evidence is missing required output key: {$key}");
            }
        }

        return ['reviewed_steps' => $dependencies->pluck('step_key')->values()->all(), 'required_output_keys' => $required, 'status' => 'evidence_complete'];
    }

    /** P15b — persist a memory link (never global scope from a workflow). @return array<string,mixed> */
    private function remember(WorkflowStep $step): array
    {
        $payload = $step->payload ?? [];
        $content = trim((string) ($payload['content'] ?? ''));
        abort_if($content === '', 422, 'memory.remember requires payload.content.');
        $scope = (string) ($payload['scope'] ?? 'project');
        if (! in_array($scope, ['private', 'project', 'skill', 'agent'], true)) {
            $scope = 'project';
        }
        $project = $step->run->project_id ? Project::find($step->run->project_id) : null;
        $link = $this->memory->remember([
            'user_id' => $step->user_id,
            'content' => mb_substr($content, 0, 8000),
            'scope' => $scope,
            'project_id' => $project?->external_id,
            'project_ref_id' => $project?->id,
            'type' => Str::limit((string) ($payload['type'] ?? 'workflow'), 60, ''),
            'importance' => max(0, min(1, (float) ($payload['importance'] ?? 0.5))),
            'meta' => ['workflow_run' => $step->run->public_id, 'step_key' => $step->step_key],
        ]);

        return ['memory_link_id' => $link->id, 'external_id' => $link->external_id, 'dataset' => $link->dataset];
    }

    /** P15b — recall memories into the step output AND the run's context store. @return array<string,mixed> */
    private function recall(WorkflowStep $step): array
    {
        $payload = $step->payload ?? [];
        $query = trim((string) ($payload['query'] ?? ''));
        $topK = max(1, min(20, (int) ($payload['top_k'] ?? 6)));
        $scope = (string) ($payload['scope'] ?? 'project');
        if (! in_array($scope, ['private', 'project', 'skill', 'agent'], true)) {
            $scope = 'project';
        }
        $project = $step->run->project_id ? Project::find($step->run->project_id) : null;
        $items = $this->memory->recall($query, $scope, [
            'user_id' => $step->user_id,
            'project_id' => $project?->external_id,
        ], $topK);

        // P15 — workflow_runs.context is the run's variable store.
        $run = $step->run;
        $context = is_array($run->context) ? $run->context : [];
        $context[$step->step_key] = ['memories' => $items];
        $run->update(['context' => $context]);

        return ['memories' => $items, 'count' => count($items)];
    }

    /** P15b — create a user task (SOLL §8 tasks table). @return array<string,mixed> */
    private function createTask(WorkflowStep $step): array
    {
        $payload = $step->payload ?? [];
        $title = trim((string) ($payload['title'] ?? ''));
        abort_if($title === '', 422, 'task.create requires payload.title.');
        $task = Task::create([
            'user_id' => $step->user_id,
            'client_id' => 'workflow',
            'external_id' => (string) Str::uuid(),
            'title' => mb_substr($title, 0, 200),
            'description' => mb_substr((string) ($payload['description'] ?? ('Erstellt durch Workflow-Lauf '.$step->run->public_id)), 0, 4000),
            'status' => 'open',
            'priority' => in_array($payload['priority'] ?? 'normal', Task::PRIORITIES, true) ? $payload['priority'] : 'normal',
            'project_ref_id' => $step->run->project_id,
        ]);

        return ['task_id' => $task->id, 'external_id' => $task->external_id, 'title' => $task->title];
    }

    /** P15b — dispatch a client task as a signed device_job bundle. */
    private function startClientTask(WorkflowStep $step): void
    {
        $payload = $step->payload ?? [];
        $params = array_diff_key($payload, array_flip(['routes', 'title', 'list', 'timeout_seconds', 'device_id']));
        $device = $this->resolveDevice($step, trim((string) ($payload['device_id'] ?? '')));
        $job = $this->deviceJobs->createForWorkflow($step, $device, $params);
        $step->update(['external_run_type' => 'device_job', 'external_run_id' => $job->public_id]);
        $this->workflows->scheduleMonitor($step->run, 5);
    }

    /** Explicit payload.device_id, else the owner's most recently seen device. */
    private function resolveDevice(WorkflowStep $step, string $deviceId): Device
    {
        $query = Device::query()->where('user_id', $step->user_id)->whereNull('revoked_at');
        $device = $deviceId !== ''
            ? (clone $query)->where('device_id', $deviceId)->first()
            : $query->orderByRaw("case when status = 'online' then 0 else 1 end")->orderByDesc('last_seen_at')->first();
        abort_unless($device !== null, 422, $deviceId !== ''
            ? 'The requested device is unknown, revoked or not yours.'
            : 'No device is available for this client task.');

        return $device;
    }

    /** @return array<string,mixed> */
    private function evaluate(WorkflowStep $step): array
    {
        $payload = $step->payload ?? [];
        $run = LlmRun::findOrFail((int) ($payload['llm_run_id'] ?? 0));
        abort_unless((int) $run->user_id === (int) $step->user_id, 404, 'LLM run was not found.');
        $evaluation = $payload['evaluation'] ?? null;
        abort_unless(is_array($evaluation) && array_key_exists('test_passed', $evaluation) && array_key_exists('quality_score', $evaluation), 422, 'Evaluator steps require signed test evidence and a quality score.');
        $result = $this->evaluator->evaluateRun($run, $evaluation + [
            'agent_run_id' => $step->run->agent_run_id,
            'evaluator_id' => (string) ($payload['evaluator_id'] ?? 'workflow.evaluator'),
        ]);

        return ['evaluation_id' => $result->id, 'status' => $result->status, 'quality_score' => $result->quality_score, 'test_pass_rate' => $result->test_pass_rate];
    }
}
