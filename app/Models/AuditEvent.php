<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    protected $fillable = [
        'event_id', 'actor_user_id', 'device_id', 'project_id', 'device_job_id', 'tool_call_id',
        'event_type', 'tool', 'policy', 'approval', 'risk_level', 'outcome',
        'payload_hash', 'result_hash', 'payload',
    ];

    protected $casts = ['payload' => 'array'];
}
