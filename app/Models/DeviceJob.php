<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceJob extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'project_id', 'device_id', 'agent_run_id',
        'tool_profile', 'status', 'risk_level', 'requires_local_approval',
        'approved_at', 'expires_at', 'payload', 'payload_hash', 'signature',
        'result', 'result_hash', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'requires_local_approval' => 'boolean', 'approved_at' => 'datetime',
        'expires_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime',
        'payload' => 'array', 'result' => 'array',
    ];

    public function device() { return $this->belongsTo(Device::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function approvals() { return $this->hasMany(DeviceJobApproval::class); }
}
