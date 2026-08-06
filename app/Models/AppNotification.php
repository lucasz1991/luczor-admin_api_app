<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppNotification extends Model
{
    protected $fillable = [
        'user_id',
        'target_device_id',
        'notification_id',
        'category',
        'title',
        'body',
        'action_url',
        'data',
        'priority',
        'read_at',
        'expires_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targetDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'target_device_id');
    }

    public function scopeVisibleToDevice(Builder $query, Device $device): Builder
    {
        return $query
            ->where('user_id', $device->user_id)
            ->where(function (Builder $query) use ($device) {
                $query->whereNull('target_device_id')
                    ->orWhere('target_device_id', $device->id);
            });
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /** @return array<string, mixed> */
    public function toPushPayload(): array
    {
        return [
            'id' => $this->notification_id,
            'sequence' => (int) $this->getKey(),
            'category' => $this->category,
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->action_url,
            'data' => $this->data ?? [],
            'priority' => $this->priority,
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
        ];
    }
}
