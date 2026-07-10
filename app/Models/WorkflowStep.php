<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowStep extends Model
{
    protected $fillable = [
        'workflow_run_id', 'user_id', 'step_key', 'type', 'status', 'position',
        'attempts', 'max_attempts', 'requires_approval', 'depends_on', 'payload',
        'output', 'error', 'available_at', 'started_at', 'finished_at',
    ];
    protected $casts = [
        'requires_approval' => 'boolean', 'depends_on' => 'array', 'payload' => 'array',
        'output' => 'array', 'available_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];
    public function run() { return $this->belongsTo(WorkflowRun::class, 'workflow_run_id'); }
}
