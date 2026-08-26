<?php

namespace Tests\Unit;

use App\Services\MemoryLedgerIdentity;
use RuntimeException;
use Tests\TestCase;

class MemoryLedgerIdentityTest extends TestCase
{
    public function test_write_identities_are_keyed_domain_separated_and_deterministic(): void
    {
        config(['luczor.memory.ledger_key' => 'a-stable-ledger-key-with-at-least-32-bytes']);
        $digest = hash('sha256', 'enumerable account and request identity');

        $idempotency = MemoryLedgerIdentity::idempotency($digest);
        $fingerprint = MemoryLedgerIdentity::fingerprint($digest);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $idempotency);
        $this->assertNotSame($digest, $idempotency);
        $this->assertNotSame($idempotency, $fingerprint);
        $this->assertNotSame($idempotency, MemoryLedgerIdentity::erasedIdempotency($idempotency));
        $this->assertNotSame($fingerprint, MemoryLedgerIdentity::erasedFingerprint($fingerprint));
        $this->assertSame($idempotency, MemoryLedgerIdentity::idempotency($digest));
    }

    public function test_missing_or_invalid_key_and_digest_fail_closed(): void
    {
        config(['luczor.memory.ledger_key' => 'short']);
        $this->expectException(RuntimeException::class);
        MemoryLedgerIdentity::idempotency(hash('sha256', 'valid digest'));
    }

    public function test_non_digest_input_fails_closed(): void
    {
        config(['luczor.memory.ledger_key' => 'a-stable-ledger-key-with-at-least-32-bytes']);
        $this->expectException(RuntimeException::class);
        MemoryLedgerIdentity::fingerprint('not-a-digest');
    }
}
