<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelRanking extends Model
{
    protected $fillable = [
        'user_id',
        'task_type', 'model_id', 'model_profile_id', 'provider_id',
        'sample_count', 'success_rate', 'test_pass_rate', 'quality_score',
        'avg_latency_ms', 'avg_cost_total', 'avg_input_tokens',
        'avg_ttft_ms', 'avg_tokens_per_second', 'cost_per_success',
        'context_efficiency_score', 'fallback_rate', 'score',
    ];

    protected $casts = [
        'success_rate' => 'float',
        'test_pass_rate' => 'float',
        'quality_score' => 'float',
        'avg_cost_total' => 'float',
        'avg_tokens_per_second' => 'float',
        'cost_per_success' => 'float',
        'fallback_rate' => 'float',
        'context_efficiency_score' => 'float',
        'score' => 'float',
    ];
}
