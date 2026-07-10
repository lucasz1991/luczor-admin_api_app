<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceJobApproval extends Model
{
    protected $fillable = ['device_job_id', 'device_id', 'decision', 'reason', 'decided_at'];
    protected $casts = ['decided_at' => 'datetime'];
}
