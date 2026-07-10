<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ToolCall extends Model
{
    protected $fillable = ['user_id', 'project_id', 'device_job_id', 'workflow_step_id', 'llm_run_id', 'server', 'tool', 'risk_level', 'status', 'duration_ms', 'input', 'output', 'error', 'started_at', 'finished_at', 'result_hash'];
    protected $casts = ['input' => 'array', 'output' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
}
