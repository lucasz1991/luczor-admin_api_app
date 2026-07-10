<?php

namespace App\Services;

use App\Models\LlmRun;
use App\Models\ModelRanking;

/**
 * Aggregates llm_runs into model_rankings per task_type.
 */
class ModelRanker
{
    public function recompute(?string $taskType = null, ?int $userId = null): void
    {
        $userSelect = $userId ? 'user_id,' : 'NULL as user_id,';
        $rows = LlmRun::query()
            ->selectRaw($userSelect.'
                task_type,
                model_id,
                provider_id,
                count(*) as sample_count,
                avg(case when success then 1.0 else 0.0 end) as success_rate,
                avg(latency_ms) as avg_latency,
                avg(coalesce(quality_score, case when success then 1.0 else 0.0 end)) as quality_score,
                avg(case
                    when test_passed is null then null
                    when test_passed then 1.0
                    else 0.0
                end) as test_pass_rate,
                avg(cost_total) as avg_cost_total,
                avg(input_tokens) as avg_input_tokens,
                avg(retry_count) as avg_retry_count
            ')
            ->when($taskType, fn ($q) => $q->where('task_type', $taskType))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->groupBy(...($userId
                ? ['user_id', 'task_type', 'model_id', 'provider_id']
                : ['task_type', 'model_id', 'provider_id']))
            ->get();

        foreach ($rows as $row) {
            $sr = (float) $row->success_rate;
            $lat = (int) round((float) ($row->avg_latency ?? 0));
            $quality = (float) ($row->quality_score ?? $sr);
            $test = $row->test_pass_rate === null ? $sr : (float) $row->test_pass_rate;
            $cost = (float) ($row->avg_cost_total ?? 0);
            $inputTokens = (int) round((float) ($row->avg_input_tokens ?? 0));
            $retry = (float) ($row->avg_retry_count ?? 0);

            $speed = 1 / (1 + $lat / 2000);
            $costScore = 1 / (1 + $cost / 0.02);
            $contextEfficiency = $quality / (1 + max(0, $inputTokens) / 4000);
            $retryPenalty = min(0.15, $retry * 0.03);
            $score = 0.30 * $sr
                + 0.20 * $test
                + 0.15 * $quality
                + 0.15 * $speed
                + 0.10 * $costScore
                + 0.10 * $contextEfficiency
                - $retryPenalty;

            ModelRanking::updateOrCreate(
                ['user_id' => $row->user_id, 'task_type' => $row->task_type, 'model_id' => $row->model_id],
                [
                    'provider_id' => $row->provider_id,
                    'sample_count' => (int) $row->sample_count,
                    'success_rate' => round($sr, 4),
                    'test_pass_rate' => round($test, 4),
                    'quality_score' => round($quality, 4),
                    'avg_latency_ms' => $lat,
                    'avg_cost_total' => round($cost, 6),
                    'avg_input_tokens' => $inputTokens,
                    'context_efficiency_score' => round($contextEfficiency, 4),
                    'score' => round(max(0, min(1, $score)), 4),
                ]
            );
        }
    }

    public function bestFor(string $taskType, ?int $userId = null): ?ModelRanking
    {
        return ModelRanking::query()
            ->where('task_type', $taskType)
            ->when($userId, fn ($q) => $q->where('user_id', $userId), fn ($q) => $q->whereNull('user_id'))
            ->orderByDesc('score')
            ->orderByDesc('sample_count')
            ->first();
    }
}
