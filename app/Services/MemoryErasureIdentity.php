<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/** Produces non-enumerable, domain-separated identities for erased records. */
final class MemoryErasureIdentity
{
    private const DATASET_DOMAIN = "luczor-memory-erasure-dataset-v1\0";

    private const DEDUPE_DOMAIN = "luczor-memory-erasure-dedupe-v1\0";

    private const OUTBOX_DEDUPE_DOMAIN = "luczor-memory-erasure-outbox-dedupe-v2\0";

    public static function dataset(string $dataset): string
    {
        return self::opaque('erased:v1:', self::DATASET_DOMAIN, $dataset);
    }

    public static function dedupe(string $dedupeKey): string
    {
        return self::opaque('erased-dedupe:v1:', self::DEDUPE_DOMAIN, $dedupeKey);
    }

    /**
     * Anonymize a durable outbox identity without collapsing distinct rows.
     *
     * An Upsert can be anonymized before a later compensating Delete reuses
     * its live dedupe key. Including the immutable row id prevents the second
     * tombstone from colliding with the first record's unique index entry.
     */
    public static function outboxDedupe(string $dedupeKey, int $outboxId): string
    {
        if ($outboxId < 1) {
            throw new InvalidArgumentException('Memory outbox erasure requires a positive row identity.');
        }
        if (str_starts_with($dedupeKey, 'erased-dedupe:v1:')
            || str_starts_with($dedupeKey, 'erased-dedupe:v2:')) {
            return $dedupeKey;
        }

        return self::opaque(
            'erased-dedupe:v2:',
            self::OUTBOX_DEDUPE_DOMAIN,
            $dedupeKey."\0".$outboxId,
        );
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
