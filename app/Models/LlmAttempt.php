<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlmAttempt extends Model
{
    protected $fillable = [
        'llm_run_id', 'model_profile_id', 'provider_credential_id', 'price_snapshot_id',
        'attempt_no', 'provider_id', 'model_id', 'upstream_generation_id', 'status',
        'http_status', 'finish_reason', 'error_type', 'error_message', 'connect_ms',
        'ttft_ms', 'total_ms', 'input_tokens', 'output_tokens', 'reasoning_tokens',
        'cache_read_tokens', 'cache_write_tokens', 'tokens_per_second', 'provider_cost',
        'calculated_cost', 'effective_cost', 'cost_source', 'request_hash', 'response_hash',
        'routing_meta', 'raw_usage', 'started_at', 'first_token_at', 'finished_at',
    ];

    protected $casts = [
        'provider_cost' => 'float', 'calculated_cost' => 'float', 'effective_cost' => 'float',
        'tokens_per_second' => 'float', 'routing_meta' => 'array', 'raw_usage' => 'array',
        'started_at' => 'datetime', 'first_token_at' => 'datetime', 'finished_at' => 'datetime',
    ];

    public function run() { return $this->belongsTo(LlmRun::class, 'llm_run_id'); }
}
