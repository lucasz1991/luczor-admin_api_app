<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowRun extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'project_id', 'workflow_definition_id', 'agent_run_id',
        'status', 'input', 'output', 'started_at', 'finished_at',
    ];
    protected $casts = ['input' => 'array', 'output' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    public function steps() { return $this->hasMany(WorkflowStep::class); }
    public function definition() { return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id'); }
}
