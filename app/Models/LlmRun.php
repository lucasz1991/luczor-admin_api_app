<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlmRun extends Model
{
    protected $fillable = [
        'user_id', 'client_id', 'project_id', 'project_ref_id', 'workflow_id', 'task_id', 'session_id',
        'task_type', 'feature_key', 'context_id',
        'model_id', 'provider_id', 'prompt_template_id', 'context_strategy_id',
        'network_policy_id', 'repo_id', 'branch', 'commit_sha',
        'status', 'success', 'latency_ms', 'input_tokens', 'output_tokens',
        'cost_total', 'quality_score', 'test_passed', 'tool_call_count', 'retry_count',
    ];

    protected $casts = [
        'success' => 'boolean',
        'cost_total' => 'float',
        'quality_score' => 'float',
        'test_passed' => 'boolean',
    ];

    public function metrics()
    {
        return $this->hasMany(LlmRunMetric::class);
    }

    public function evaluations()
    {
        return $this->hasMany(EvaluationResult::class);
    }
}
