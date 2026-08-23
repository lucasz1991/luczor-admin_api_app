<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowStep extends Model
{
    protected $fillable = [
        'workflow_run_id', 'user_id', 'step_key', 'type', 'status', 'position',
        'attempts', 'max_attempts', 'requires_approval', 'depends_on', 'payload',
        'output', 'logs', 'error', 'available_at', 'started_at', 'finished_at', 'duration_ms',
        'external_run_type', 'external_run_id',
    ];

    protected $casts = [
        'requires_approval' => 'boolean', 'depends_on' => 'array', 'payload' => 'array',
        'output' => 'array', 'logs' => 'array', 'duration_ms' => 'integer',
        'available_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];

    public function run()
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    public function artifacts()
    {
        return $this->hasMany(WorkflowRunArtifact::class);
    }
}
