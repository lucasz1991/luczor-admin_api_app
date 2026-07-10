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
        'ttft_ms',
        'tokens_per_second',
        'reasoning_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'tool_call_count',
        'retry_count',
        'cost_total',
        'provider_cost',
        'calculated_cost',
        'cost_source',
        'prompt_template_id',
        'context_strategy_id',
        'network_policy_id',
        'raw_usage',
        'provider_meta',
    ];

    protected $casts = [
        'cost_total' => 'float',
        'provider_cost' => 'float',
        'calculated_cost' => 'float',
        'tokens_per_second' => 'float',
        'raw_usage' => 'array',
        'provider_meta' => 'array',
    ];

    public function llmRun()
    {
        return $this->belongsTo(LlmRun::class);
    }
}
