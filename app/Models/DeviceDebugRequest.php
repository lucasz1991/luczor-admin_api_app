<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DeviceDebugRequest extends Model
{
    protected $fillable = [
        'public_id', 'device_id', 'user_id', 'requested_by', 'status',
        'requested_at', 'claimed_at', 'completed_at', 'error', 'payload', 'meta',
    ];

    protected $casts = [
        'requested_at' => 'datetime', 'claimed_at' => 'datetime', 'completed_at' => 'datetime',
        'payload' => 'encrypted:array', 'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (DeviceDebugRequest $request) {
            $request->public_id ??= (string) Str::uuid();
            $request->requested_at ??= now();
        });
    }

    public function device() { return $this->belongsTo(Device::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
}
