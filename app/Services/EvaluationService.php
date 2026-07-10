<?php

namespace App\Services;

use App\Models\EvaluationResult;
use App\Models\LlmRun;
use App\Models\LlmRunMetric;
use App\Models\PerformanceProfile;

class EvaluationService
{
    public function __construct(private ModelRanker $ranker)
    {
    }

    /** @param array<string,mixed> $metrics */
    public function recordMetric(LlmRun $run, array $metrics): LlmRunMetric
    {
        $payload = [
            'input_tokens' => $this->nullableInt($metrics['input_tokens'] ?? null),
            'output_tokens' => $this->nullableInt($metrics['output_tokens'] ?? null),
            'context_tokens' => $this->nullableInt($metrics['context_tokens'] ?? null),
            'latency_ms' => $this->nullableInt($metrics['latency_ms'] ?? null),
            'tool_call_count' => (int) ($metrics['tool_call_count'] ?? 0),
            'retry_count' => (int) ($metrics['retry_count'] ?? 0),
            'cost_total' => (float) ($metrics['cost_total'] ?? 0),
            'prompt_template_id' => $metrics['prompt_template_id'] ?? $run->prompt_template_id,
            'context_strategy_id' => $metrics['context_strategy_id'] ?? $run->context_strategy_id,
            'network_policy_id' => $metrics['network_policy_id'] ?? $run->network_policy_id,
            'raw_usage' => $metrics['raw_usage'] ?? null,
        ];

        $run->fill([
            'input_tokens' => $payload['input_tokens'] ?? $run->input_tokens,
            'output_tokens' => $payload['output_tokens'] ?? $run->output_tokens,
            'latency_ms' => $payload['latency_ms'] ?? $run->latency_ms,
            'tool_call_count' => $payload['tool_call_count'],
            'retry_count' => $payload['retry_count'],
            'cost_total' => $payload['cost_total'],
        ])->save();

        return $run->metrics()->create($payload);
    }

    /** @param array<string,mixed> $data */
    public function evaluateRun(LlmRun $run, array $data): EvaluationResult
    {
        $quality = $this->nullableFloat($data['quality_score'] ?? null);
        $successScore = $this->nullableFloat($data['success_score'] ?? null);
        $testPassRate = $this->nullableFloat($data['test_pass_rate'] ?? null);
        $testPassed = array_key_exists('test_passed', $data)
            ? (bool) $data['test_passed']
            : ($testPassRate === null ? null : $testPassRate >= 1.0);

        $quality ??= $testPassRate ?? ($successScore ?? ($run->success ? 1.0 : 0.0));
        $statusInput = $data['status'] ?? null;
        $successScore ??= $statusInput === 'failed' ? 0.0 : ($run->success ? 1.0 : 0.0);

        $contextEfficiency = $this->nullableFloat($data['context_efficiency_score'] ?? null)
            ?? $this->contextEfficiency($quality, (int) ($run->input_tokens ?? 0));

        $status = (string) ($data['status'] ?? ($successScore >= 0.5 ? 'passed' : 'failed'));

        $result = EvaluationResult::create([
            'user_id' => $run->user_id,
            'llm_run_id' => $run->id,
            'agent_run_id' => $data['agent_run_id'] ?? null,
            'project_ref_id' => $run->project_ref_id,
            'evaluator_id' => $data['evaluator_id'] ?? 'luczor.mvp',
            'status' => $status,
            'success_score' => $successScore,
            'quality_score' => $quality,
            'test_pass_rate' => $testPassRate,
            'security_score' => $this->nullableFloat($data['security_score'] ?? null),
            'diff_quality_score' => $this->nullableFloat($data['diff_quality_score'] ?? null),
            'context_efficiency_score' => $contextEfficiency,
            'hallucination_flags' => (int) ($data['hallucination_flags'] ?? 0),
            'user_feedback' => $this->nullableInt($data['user_feedback'] ?? null),
            'notes' => $data['notes'] ?? null,
            'payload' => $data['payload'] ?? null,
        ]);

        $run->fill([
            'success' => $successScore >= 0.5,
            'quality_score' => $quality,
            'test_passed' => $testPassed,
        ])->save();

        PerformanceProfile::create([
            'user_id' => $run->user_id,
            'project_id' => $run->project_ref_id,
            'task_type' => $run->task_type,
            'model_id' => $run->model_id,
            'quality_score' => $quality,
            'security_score' => $result->security_score,
            'test_pass_rate' => $testPassRate,
            'cost_score' => 1 / (1 + max(0, (float) $run->cost_total) / 0.02),
            'hallucination_score' => min(1, (int) $result->hallucination_flags / 5),
            'metrics' => [
                'llm_run_id' => $run->id,
                'evaluation_id' => $result->id,
                'latency_ms' => $run->latency_ms,
                'input_tokens' => $run->input_tokens,
                'output_tokens' => $run->output_tokens,
            ],
        ]);

        $this->ranker->recompute($run->task_type, $run->user_id ? (int) $run->user_id : null);

        return $result;
    }

    private function contextEfficiency(float $quality, int $inputTokens): float
    {
        return round($quality / (1 + max(0, $inputTokens) / 4000), 4);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }
}
