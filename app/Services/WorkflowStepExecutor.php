<?php

namespace App\Services;

use App\Models\LlmRun;
use App\Models\Project;
use App\Models\WorkflowStep;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Executes only declarative, server-safe workflow step types. */
class WorkflowStepExecutor
{
    public function __construct(
        private WorkflowService $workflows,
        private ContextController $context,
        private EvaluationService $evaluator,
        private AuditLogger $audit,
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
        try {
            $output = match ($step->type) {
                'context' => $this->context($step),
                'review' => $this->review($step),
                'evaluator' => $this->evaluate($step),
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
