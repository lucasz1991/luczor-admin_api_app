<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'label',
        'api_key',
        'base_url',
        'active',
        'meta',
        'last_tested_at',
        'last_test_status',
        'last_error_code',
        'request_format',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'api_key' => 'encrypted',
        'active' => 'boolean',
        'meta' => 'array',
        'last_tested_at' => 'datetime',
    ];

    protected $hidden = [
        'api_key',
    ];

    /**
     * Owner of a personal credential; null for global (organization) rows.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<ProviderCredential>  $query
     * @return Builder<ProviderCredential>
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    /**
     * @param  Builder<ProviderCredential>  $query
     * @return Builder<ProviderCredential>
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function isPersonal(): bool
    {
        return $this->user_id !== null;
    }

    public function maskedKey(): string
    {
        $key = (string) $this->api_key;
        if ($key === '') {
            return '';
        }

        return strlen($key) <= 10 ? str_repeat('*', strlen($key)) : substr($key, 0, 4).'...'.substr($key, -4);
    }
}
