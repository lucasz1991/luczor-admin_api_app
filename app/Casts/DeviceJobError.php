<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;

class DeviceJobError implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return (int) ($attributes['protocol_version'] ?? 1) === 2 ? Crypt::decryptString($value) : $value;
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return (int) ($attributes['protocol_version'] ?? 1) === 2 ? Crypt::encryptString((string) $value) : (string) $value;
    }
}
