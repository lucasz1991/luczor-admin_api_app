<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'label',
        'api_key',
        'base_url',
        'active',
        'meta',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'active' => 'boolean',
        'meta' => 'array',
    ];

    protected $hidden = [
        'api_key',
    ];

    public function maskedKey(): string
    {
        $key = (string) $this->api_key;
        if ($key === '') {
            return '';
        }

        return strlen($key) <= 10 ? str_repeat('*', strlen($key)) : substr($key, 0, 4).'...'.substr($key, -4);
    }
}
