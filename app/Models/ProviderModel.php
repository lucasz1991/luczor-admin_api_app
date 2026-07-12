<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderModel extends Model
{
    protected $fillable = [
        'provider_credential_id', 'model_id', 'display_name',
        'capabilities', 'context_window', 'max_output_tokens',
        'pricing', 'provider_status', 'synced_at',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'pricing' => 'array',
        'context_window' => 'integer',
        'max_output_tokens' => 'integer',
        'synced_at' => 'datetime',
    ];

    public function credential()
    {
        return $this->belongsTo(ProviderCredential::class, 'provider_credential_id');
    }
}
