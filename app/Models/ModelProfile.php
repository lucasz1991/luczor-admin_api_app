<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    public function fallbackEntries()
    {
        return $this->hasMany(ModelUseCaseEntry::class);
    }

    public function credential()
    {
        return $this->belongsTo(ProviderCredential::class, 'provider_credential_id');
    }
}
