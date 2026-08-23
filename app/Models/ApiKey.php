<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasFactory;

    public const ABILITIES = [
        'sync.read',
        'sync.write',
        'settings.read',
        'settings.write',
        'brain.read',
        'brain.write',
        'proxy.use',
        'device.connect',
        'device.jobs.read',
        'device.jobs.write',
        'all',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'token_hash',
        'active',
        'expires_at',
        'last_used_at',
        'abilities',
        'device_id',
        'device_name',
        'meta',
    ];

    protected $casts = [
        'active' => 'boolean',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'abilities' => 'array',
        'meta' => 'array',
    ];

    protected $hidden = [
        'token_hash',
    ];

    public static function mint(array $attributes = []): array
    {
        $plain = Str::random(64);

        $model = static::create(array_merge($attributes, [
            'token_hash' => static::hashToken($plain),
        ]));

        return [
            'model' => $model,
            'plain' => $plain,
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThan($this->expires_at);
    }

    public function hasAbility(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        return in_array('all', $abilities, true) || in_array($ability, $abilities, true);
    }
}
