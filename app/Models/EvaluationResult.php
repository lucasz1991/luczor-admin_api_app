<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationResult extends Model
{
    protected $fillable = [
        'user_id',
        'llm_run_id',
        'agent_run_id',
        'project_ref_id',
        'evaluator_id',
        'status',
        'success_score',
        'quality_score',
        'test_pass_rate',
        'security_score',
        'diff_quality_score',
        'context_efficiency_score',
        'hallucination_flags',
        'user_feedback',
        'notes',
        'payload',
    ];

    protected $casts = [
        'success_score' => 'float',
        'quality_score' => 'float',
        'test_pass_rate' => 'float',
        'security_score' => 'float',
        'diff_quality_score' => 'float',
        'context_efficiency_score' => 'float',
        'payload' => 'array',
    ];

    /** @return BelongsTo<LlmRun, $this> */
    public function llmRun(): BelongsTo
    {
        return $this->belongsTo(LlmRun::class);
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
