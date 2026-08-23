<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowRun extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'project_id', 'workflow_definition_id', 'agent_run_id',
        'parent_workflow_run_id', 'parent_workflow_step_id',
        'current_workflow_step_id', 'status', 'sandbox', 'input', 'output', 'context',
        'started_at', 'finished_at', 'duration_ms',
    ];

    protected $casts = [
        'sandbox' => 'boolean',
        'input' => 'array', 'output' => 'array', 'context' => 'array',
        'started_at' => 'datetime', 'finished_at' => 'datetime', 'duration_ms' => 'integer',
    ];

    /** @return HasMany<WorkflowStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class);
    }

    /** @return BelongsTo<WorkflowDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    /** @return HasMany<WorkflowRunArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(WorkflowRunArtifact::class);
    }
}
