<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowStep extends Model
{
    protected $attributes = ['type_version' => 1];

    protected $fillable = [
        'workflow_run_id', 'user_id', 'step_key', 'type', 'status', 'position',
        'attempts', 'max_attempts', 'requires_approval', 'depends_on', 'payload',
        'output', 'logs', 'error', 'available_at', 'started_at', 'finished_at', 'duration_ms',
        'external_run_type', 'external_run_id',
        'execution_id', 'execution_sequence', 'approved_at', 'resolved_payload',
        'type_version', 'result_envelope', 'control_state',
    ];

    protected $casts = [
        'requires_approval' => 'boolean', 'depends_on' => 'array', 'payload' => 'array',
        'output' => 'array', 'logs' => 'array', 'duration_ms' => 'integer',
        'available_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime',
        'approved_at' => 'datetime', 'resolved_payload' => 'array', 'execution_sequence' => 'integer',
        'type_version' => 'integer', 'result_envelope' => 'array', 'control_state' => 'array',
    ];

    /** @return BelongsTo<WorkflowRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    /** @return HasMany<WorkflowRunArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(WorkflowRunArtifact::class);
    }
}
