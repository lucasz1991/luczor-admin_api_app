<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelRanking extends Model
{
    protected $fillable = [
        'task_type', 'model_id', 'provider_id',
        'sample_count', 'success_rate', 'avg_latency_ms', 'score',
    ];

    protected $casts = [
        'success_rate' => 'float',
        'score' => 'float',
    ];
}
