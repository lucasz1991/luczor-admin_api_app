<?php

namespace App\Models;

use App\Casts\DeviceJobData;
use Illuminate\Database\Eloquent\Model;

class DeviceJob extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'project_id', 'device_id', 'agent_run_id',
        'tool_profile', 'status', 'risk_level', 'requires_local_approval',
        'approved_at', 'expires_at', 'payload', 'payload_hash', 'signature',
        'result', 'result_hash', 'error', 'started_at', 'finished_at',
        'workflow_execution_id', 'cancel_requested_at',
        'protocol_version', 'operation_id', 'request_hash', 'source_device_id', 'master_epoch',
        'attempt_id', 'lease_expires_at', 'progress_sequence', 'progress', 'conversation_external_id',
    ];

    protected $casts = [
        'requires_local_approval' => 'boolean', 'approved_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
        'expires_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime',
        'payload' => DeviceJobData::class, 'result' => DeviceJobData::class,
        'protocol_version' => 'integer', 'master_epoch' => 'integer', 'lease_expires_at' => 'datetime',
        'progress_sequence' => 'integer', 'progress' => 'encrypted:array',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function approvals()
    {
        return $this->hasMany(DeviceJobApproval::class);
    }
}
