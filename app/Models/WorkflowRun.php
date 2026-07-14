<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    public function steps() { return $this->hasMany(WorkflowStep::class); }
    public function definition() { return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id'); }
    public function artifacts() { return $this->hasMany(WorkflowRunArtifact::class); }
}
