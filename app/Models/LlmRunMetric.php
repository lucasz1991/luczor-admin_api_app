<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlmRunMetric extends Model
{
    protected $fillable = [
        'llm_run_id',
        'input_tokens',
        'output_tokens',
        'context_tokens',
        'latency_ms',
        'tool_call_count',
        'retry_count',
        'cost_total',
        'prompt_template_id',
        'context_strategy_id',
        'network_policy_id',
        'raw_usage',
    ];

    protected $casts = [
        'cost_total' => 'float',
        'raw_usage' => 'array',
    ];

    public function llmRun()
    {
        return $this->belongsTo(LlmRun::class);
    }
}
