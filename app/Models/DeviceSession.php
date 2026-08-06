<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSession extends Model
{
    protected $fillable = ['device_id', 'token_hash', 'nonce', 'channel_id', 'expires_at', 'last_seen_at', 'ip_address', 'meta'];

    protected $casts = ['expires_at' => 'datetime', 'last_seen_at' => 'datetime', 'meta' => 'array'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
