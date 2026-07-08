<?php

namespace App\Services;

use App\Models\LlmRun;
use App\Models\ModelRanking;

/**
 * Aggregates llm_runs into model_rankings per task_type (Masterplan v3 §7/§24).
 * score = 0.6 * success_rate + 0.4 * speed_score
 * speed_score = 1 / (1 + avg_latency_ms / 2000)
 */
class ModelRanker
{
    public function recompute(?string $taskType = null): void
    {
        $rows = LlmRun::query()
            ->selectRaw('task_type, model_id, provider_id, count(*) as sample_count, avg(success) as success_rate, avg(latency_ms) as avg_latency')
            ->when($taskType, fn ($q) => $q->where('task_type', $taskType))
            ->groupBy('task_type', 'model_id', 'provider_id')
            ->get();

        foreach ($rows as $row) {
            $sr = (float) $row->success_rate;
            $lat = (int) round((float) ($row->avg_latency ?? 0));
            $speed = 1 / (1 + $lat / 2000);
            $score = 0.6 * $sr + 0.4 * $speed;

            ModelRanking::updateOrCreate(
                ['task_type' => $row->task_type, 'model_id' => $row->model_id],
                [
                    'provider_id' => $row->provider_id,
                    'sample_count' => (int) $row->sample_count,
                    'success_rate' => round($sr, 4),
                    'avg_latency_ms' => $lat,
                    'score' => round($score, 4),
                ]
            );
        }
    }

    public function bestFor(string $taskType): ?ModelRanking
    {
        return ModelRanking::query()
            ->where('task_type', $taskType)
            ->orderByDesc('score')
            ->orderByDesc('sample_count')
            ->first();
    }
}
