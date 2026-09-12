<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** JSON-compatible encryption for chat jobs; legacy fixed-operation jobs retain their existing format. */
class DeviceJobData implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded) && ($decoded['luczor_encrypted_job_data'] ?? null) === 1 && is_string($decoded['ciphertext'] ?? null)) {
            return json_decode(Crypt::decryptString($decoded['ciphertext']), true, 512, JSON_THROW_ON_ERROR);
        }

        return $decoded;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (($attributes['tool_profile'] ?? null) !== 'workspace.chat' && (int) ($attributes['protocol_version'] ?? 1) !== 2) {
            return $json;
        }

        return json_encode(['luczor_encrypted_job_data' => 1, 'ciphertext' => Crypt::encryptString($json)], JSON_THROW_ON_ERROR);
    }
}
