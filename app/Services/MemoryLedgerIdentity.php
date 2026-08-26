<?php

namespace App\Services;

use RuntimeException;

/** Produces non-enumerable, domain-separated identities for the write ledger. */
final class MemoryLedgerIdentity
{
    private const IDEMPOTENCY_DOMAIN = "luczor-memory-write-id-v2\0";

    private const FINGERPRINT_DOMAIN = "luczor-memory-write-fingerprint-v2\0";

    private const ERASED_IDEMPOTENCY_DOMAIN = "luczor-memory-erased-write-id-v3\0";

    private const ERASED_FINGERPRINT_DOMAIN = "luczor-memory-erased-write-fingerprint-v3\0";

    public static function idempotency(string $unkeyedDigest): string
    {
        return self::opaque(self::IDEMPOTENCY_DOMAIN, $unkeyedDigest);
    }

    public static function fingerprint(string $unkeyedDigest): string
    {
        return self::opaque(self::FINGERPRINT_DOMAIN, $unkeyedDigest);
    }

    public static function erasedIdempotency(string $liveIdempotency): string
    {
        return self::opaque(self::ERASED_IDEMPOTENCY_DOMAIN, $liveIdempotency);
    }

    public static function erasedFingerprint(string $liveFingerprint): string
    {
        return self::opaque(self::ERASED_FINGERPRINT_DOMAIN, $liveFingerprint);
    }

    private static function opaque(string $domain, string $unkeyedDigest): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $unkeyedDigest) !== 1) {
            throw new RuntimeException('Memory ledger input must be a SHA-256 digest.');
        }

        $key = trim((string) config('luczor.memory.ledger_key', ''));
        if (strlen($key) < 32) {
            throw new RuntimeException(
                'LUCZOR_MEMORY_LEDGER_KEY must be a stable secret of at least 32 bytes.'
            );
        }

        // HMAC over the legacy digest is intentional: existing SHA-only rows
        // can be upgraded deterministically without retaining their preimage.
        return hash_hmac('sha256', $domain.$unkeyedDigest, $key);
    }
}
