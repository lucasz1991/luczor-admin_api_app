<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlmRun extends Model
{
    protected $fillable = [
        'user_id', 'client_id', 'project_id', 'task_type',
        'model_id', 'provider_id', 'status', 'success',
        'latency_ms', 'input_tokens', 'output_tokens',
    ];

    protected $casts = [
        'success' => 'boolean',
    ];
}
