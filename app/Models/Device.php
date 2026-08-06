<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $fillable = [
        'user_id', 'api_key_id', 'device_id', 'name', 'status', 'public_key',
        'last_seen_at', 'revoked_at', 'metrics', 'meta',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime', 'revoked_at' => 'datetime',
        'metrics' => 'array', 'meta' => 'array',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function apiKey() { return $this->belongsTo(ApiKey::class); }
    public function jobs() { return $this->hasMany(DeviceJob::class); }
    public function targetedNotifications() { return $this->hasMany(AppNotification::class, 'target_device_id'); }
}
