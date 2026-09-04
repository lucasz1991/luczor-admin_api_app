<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'provider',
        'provider_credential_id',
        'model_id',
        'temperature',
        'max_tokens',
        'purpose',
        'active',
        'sort_order',
        'meta',
        'capabilities',
        'context_window',
    ];

    protected $casts = [
        'temperature' => 'float',
        'max_tokens' => 'integer',
        'active' => 'boolean',
        'sort_order' => 'integer',
        'context_window' => 'integer',
        'meta' => 'array',
        'capabilities' => 'array',
    ];

    /** @return HasMany<ModelUseCaseEntry, $this> */
    public function fallbackEntries(): HasMany
    {
        return $this->hasMany(ModelUseCaseEntry::class);
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class, 'provider_credential_id');
    }
}
