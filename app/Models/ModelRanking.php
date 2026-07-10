<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelRanking extends Model
{
    protected $fillable = [
        'user_id',
        'task_type', 'model_id', 'provider_id',
        'sample_count', 'success_rate', 'test_pass_rate', 'quality_score',
        'avg_latency_ms', 'avg_cost_total', 'avg_input_tokens',
        'context_efficiency_score', 'score',
    ];

    protected $casts = [
        'success_rate' => 'float',
        'test_pass_rate' => 'float',
        'quality_score' => 'float',
        'avg_cost_total' => 'float',
        'context_efficiency_score' => 'float',
        'score' => 'float',
    ];
}
