<?php

namespace App\Services;

use RuntimeException;

/** Produces non-enumerable, domain-separated identities for erased records. */
final class MemoryErasureIdentity
{
    private const DATASET_DOMAIN = "luczor-memory-erasure-dataset-v1\0";

    private const DEDUPE_DOMAIN = "luczor-memory-erasure-dedupe-v1\0";

    public static function dataset(string $dataset): string
    {
        return self::opaque('erased:v1:', self::DATASET_DOMAIN, $dataset);
    }

    public static function dedupe(string $dedupeKey): string
    {
        return self::opaque('erased-dedupe:v1:', self::DEDUPE_DOMAIN, $dedupeKey);
    }

    private static function opaque(string $prefix, string $domain, string $value): string
    {
        if (str_starts_with($value, $prefix)) {
            return $value;
        }

        $key = trim((string) config('luczor.memory.namespace_key', ''));
        if ($key === '') {
            throw new RuntimeException(
                'LUCZOR_MEMORY_NAMESPACE_KEY is required to anonymize erased memory identities.'
            );
        }

        return $prefix.hash_hmac('sha256', $domain.$value, $key);
    }
}
