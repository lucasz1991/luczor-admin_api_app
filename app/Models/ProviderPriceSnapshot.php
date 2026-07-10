<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderPriceSnapshot extends Model
{
    protected $fillable = [
        'provider_id', 'model_id', 'currency', 'input_per_million', 'output_per_million',
        'cache_read_per_million', 'cache_write_per_million', 'source',
        'valid_from', 'valid_until', 'meta',
    ];

    protected $casts = [
        'input_per_million' => 'float', 'output_per_million' => 'float',
        'cache_read_per_million' => 'float', 'cache_write_per_million' => 'float',
        'valid_from' => 'datetime', 'valid_until' => 'datetime', 'meta' => 'array',
    ];

    public static function current(string $provider, string $model): ?self
    {
        return static::query()
            ->where('provider_id', $provider)->where('model_id', $model)
            ->where('valid_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->latest('valid_from')->first();
    }
}
