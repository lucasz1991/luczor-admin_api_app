<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ApiKey, $this> */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /** @return HasMany<DeviceJob, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(DeviceJob::class);
    }

    /** @return HasMany<AppNotification, $this> */
    public function targetedNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class, 'target_device_id');
    }
}
