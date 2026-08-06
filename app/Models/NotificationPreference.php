<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    public const GLOBAL_CATEGORY = '__all__';

    public const CATEGORIES = [
        'general',
        'agent',
        'workflow',
        'device',
        'security',
    ];

    protected $fillable = [
        'user_id',
        'category',
        'push_enabled',
    ];

    protected $casts = [
        'push_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
