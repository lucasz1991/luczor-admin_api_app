<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** Preserve the legacy sync archive while encrypting the new shared public conversation channel. */
class ConversationArchiveData implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded) && ($decoded['conversation_archive_encrypted'] ?? null) === 1) {
            return json_decode(Crypt::decryptString($decoded['ciphertext']), true, 32, JSON_THROW_ON_ERROR);
        }

        return $decoded;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return ($attributes['entity_type'] ?? null) === 'conversation_message_v1'
            ? json_encode(['conversation_archive_encrypted' => 1, 'ciphertext' => Crypt::encryptString($json)], JSON_THROW_ON_ERROR) : $json;
    }
}
