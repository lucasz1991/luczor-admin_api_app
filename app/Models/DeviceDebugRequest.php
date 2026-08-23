<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
