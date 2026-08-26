<?php

namespace App\Services;

/**
 * Resolves the stable numeric component used in Cognee's deterministic
 * filenames. Once persisted, this identity is monotonic: it may be recovered
 * from the still-linked SQL row, but it can never be guessed or overwritten.
 */
final class MemoryProviderIdentity
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array{identity:?int,error:null|'invalid_stored'|'invalid_current'|'conflict'}
     */
    public static function resolve(array $payload, mixed $currentMemoryLinkId): array
    {
        $storedPresent = array_key_exists('provider_memory_link_id', $payload);
        $storedId = $storedPresent ? self::positiveInteger($payload['provider_memory_link_id']) : null;
        $currentPresent = $currentMemoryLinkId !== null;
        $currentId = $currentPresent ? self::positiveInteger($currentMemoryLinkId) : null;

        if ($storedPresent && $storedId === null) {
            return ['identity' => null, 'error' => 'invalid_stored'];
        }
        if ($currentPresent && $currentId === null) {
            return ['identity' => null, 'error' => 'invalid_current'];
        }
        if ($storedId !== null && $currentId !== null && $storedId !== $currentId) {
            return ['identity' => null, 'error' => 'conflict'];
        }

        return ['identity' => $storedId ?? $currentId, 'error' => null];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{content_hash:?string,error:null|'invalid_stored'|'invalid_current'|'conflict'}
     */
    public static function resolveContentHash(array $payload, mixed $currentContentHash): array
    {
        $storedPresent = array_key_exists('content_hash', $payload);
        $storedHash = $storedPresent ? self::contentHash($payload['content_hash']) : null;
        $currentPresent = $currentContentHash !== null;
        $currentHash = $currentPresent ? self::contentHash($currentContentHash) : null;

        if ($storedPresent && $storedHash === null) {
            return ['content_hash' => null, 'error' => 'invalid_stored'];
        }
        if ($currentPresent && $currentHash === null) {
            return ['content_hash' => null, 'error' => 'invalid_current'];
        }
        if ($storedHash !== null && $currentHash !== null && ! hash_equals($storedHash, $currentHash)) {
            return ['content_hash' => null, 'error' => 'conflict'];
        }

        return ['content_hash' => $storedHash ?? $currentHash, 'error' => null];
    }

    private static function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($parsed) ? $parsed : null;
    }

    private static function contentHash(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1
            ? $value
            : null;
    }
}
