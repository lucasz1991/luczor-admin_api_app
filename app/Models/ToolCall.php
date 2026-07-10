<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ToolCall extends Model
{
    protected $fillable = ['user_id', 'project_id', 'device_job_id', 'workflow_step_id', 'server', 'tool', 'risk_level', 'status', 'input', 'output', 'result_hash'];
    protected $casts = ['input' => 'array', 'output' => 'array'];
}
