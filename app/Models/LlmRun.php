<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LlmRun extends Model
{
    protected $fillable = [
        'request_id',
        'user_id', 'client_id', 'project_id', 'project_ref_id', 'workflow_id', 'task_id', 'session_id',
        'task_type', 'feature_key', 'context_id',
        'model_id', 'selected_by', 'provider_id', 'prompt_template_id', 'context_strategy_id',
        'network_policy_id', 'repo_id', 'branch', 'commit_sha',
        'status', 'finish_reason', 'request_hash', 'response_hash', 'success', 'latency_ms',
        'ttft_ms', 'tokens_per_second', 'input_tokens', 'output_tokens', 'cost_total',
        'provider_cost', 'calculated_cost', 'cost_source', 'quality_score', 'test_passed',
        'tool_call_count', 'retry_count', 'attempt_count',
    ];

    protected $casts = [
        'success' => 'boolean',
        'cost_total' => 'float',
        'quality_score' => 'float',
        'provider_cost' => 'float',
        'calculated_cost' => 'float',
        'tokens_per_second' => 'float',
        'test_passed' => 'boolean',
    ];

    /** @return HasMany<LlmRunMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(LlmRunMetric::class);
    }

    /** @return HasMany<EvaluationResult, $this> */
    public function evaluations(): HasMany
    {
        return $this->hasMany(EvaluationResult::class);
    }

    /** @return HasMany<LlmAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(LlmAttempt::class);
    }

    /** @return HasMany<ToolCall, $this> */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(ToolCall::class);
    }
}
