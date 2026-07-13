<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** SOLL §14 P15 — per-step run artifact (screenshot/DOM/log/json) for visualization. */
class WorkflowRunArtifact extends Model
{
    protected $fillable = [
        'workflow_run_id', 'workflow_step_id', 'step_key', 'phase', 'artifact_type',
        'label', 'current_url', 'storage_disk', 'storage_path', 'status', 'error_message', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    public function run()
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    public function step()
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }
}
