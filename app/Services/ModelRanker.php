<?php

namespace App\Services;

use App\Models\LlmAttempt;
use App\Models\ModelRanking;

/** Data-driven ranking per task type, based on every provider attempt. */
class ModelRanker
{
    public function recompute(?string $taskType = null, ?int $userId = null): void
    {
        $userSelect = $userId ? 'llm_runs.user_id as user_id,' : 'NULL as user_id,';
        $query = LlmAttempt::query()
            ->join('llm_runs', 'llm_runs.id', '=', 'llm_attempts.llm_run_id')
            ->selectRaw($userSelect." llm_runs.task_type, llm_attempts.model_id, llm_attempts.provider_id,
                count(*) as sample_count,
                avg(case when llm_attempts.status = 'completed' then 1.0 else 0.0 end) as success_rate,
                avg(llm_attempts.total_ms) as avg_latency,
                avg(llm_attempts.ttft_ms) as avg_ttft,
                avg(llm_attempts.tokens_per_second) as avg_tps,
                avg(llm_attempts.effective_cost) as avg_cost,
                sum(llm_attempts.effective_cost) / nullif(sum(case when llm_attempts.status = 'completed' then 1 else 0 end), 0) as cost_per_success,
                avg(llm_attempts.input_tokens) as avg_input_tokens,
                avg(case when llm_attempts.attempt_no > 1 then 1.0 else 0.0 end) as fallback_rate,
                avg(case when llm_attempts.status = 'completed' then coalesce(llm_runs.quality_score, 0.5) else 0.0 end) as quality_score,
                avg(case when llm_attempts.status = 'completed' and llm_runs.test_passed = 1 then 1.0 when llm_attempts.status = 'completed' and llm_runs.test_passed = 0 then 0.0 else null end) as test_pass_rate")
            ->when($taskType, fn ($q) => $q->where('llm_runs.task_type', $taskType))
            ->when($userId, fn ($q) => $q->where('llm_runs.user_id', $userId))
            ->groupBy(...($userId
                ? ['llm_runs.user_id', 'llm_runs.task_type', 'llm_attempts.model_id', 'llm_attempts.provider_id']
                : ['llm_runs.task_type', 'llm_attempts.model_id', 'llm_attempts.provider_id']));

        foreach ($query->get() as $row) {
            $success = (float) $row->success_rate;
            $quality = (float) ($row->quality_score ?? 0);
            $tests = $row->test_pass_rate === null ? $success : (float) $row->test_pass_rate;
            $latency = (int) round((float) ($row->avg_latency ?? 0));
            $ttft = (int) round((float) ($row->avg_ttft ?? 0));
            $tps = (float) ($row->avg_tps ?? 0);
            $costKnown = $row->avg_cost !== null;
            $cost = $costKnown ? (float) $row->avg_cost : 0.0;
            $inputTokens = (int) round((float) ($row->avg_input_tokens ?? 0));
            $fallback = (float) ($row->fallback_rate ?? 0);
            $speed = 0.6 * (1 / (1 + $latency / 3000)) + 0.4 * (1 / (1 + $ttft / 1500));
            $throughput = min(1, $tps / 80);
            $costScore = $costKnown ? 1 / (1 + $cost / 0.02) : 0.5;
            $contextEfficiency = $quality / (1 + $inputTokens / 4000);
            $score = 0.25 * $success + 0.18 * $tests + 0.17 * $quality + 0.12 * $speed
                + 0.08 * $throughput + 0.10 * $costScore + 0.10 * $contextEfficiency - 0.08 * $fallback;

            ModelRanking::updateOrCreate(
                ['user_id' => $row->user_id, 'task_type' => $row->task_type, 'model_id' => $row->model_id],
                ['provider_id' => $row->provider_id, 'sample_count' => (int) $row->sample_count,
                    'success_rate' => round($success, 4), 'test_pass_rate' => round($tests, 4),
                    'quality_score' => round($quality, 4), 'avg_latency_ms' => $latency, 'avg_ttft_ms' => $ttft,
                    'avg_tokens_per_second' => round($tps, 4), 'avg_cost_total' => $costKnown ? round($cost, 8) : null,
                    'cost_per_success' => $row->cost_per_success === null ? null : round((float) $row->cost_per_success, 8),
                    'avg_input_tokens' => $inputTokens, 'context_efficiency_score' => round($contextEfficiency, 4),
                    'fallback_rate' => round($fallback, 4), 'score' => round(max(0, min(1, $score)), 4)]
            );
        }
    }

    public function bestFor(string $taskType, ?int $userId = null): ?ModelRanking
    {
        return ModelRanking::query()->where('task_type', $taskType)
            ->when($userId, fn ($q) => $q->where('user_id', $userId), fn ($q) => $q->whereNull('user_id'))
            ->orderByDesc('score')->orderByDesc('sample_count')->first();
    }
}
