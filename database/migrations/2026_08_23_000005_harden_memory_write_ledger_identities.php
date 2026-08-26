<?php

use App\Services\MemoryLedgerIdentity;
use App\Services\MemoryProviderIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Validate the non-rotating secret before touching durable identities.
        MemoryLedgerIdentity::idempotency(str_repeat('0', 64));
        $this->addIdentityVersionColumn('memory_links');
        $this->addIdentityVersionColumn('memory_write_events');

        // Reject legacy writers before the backfill begins. PostgreSQL's NOT
        // VALID check applies to every new row immediately while allowing the
        // existing v1 rows to be upgraded. MySQL/MariaDB use equivalent
        // insert/update triggers. New code writes version 2 explicitly.
        $this->installVersionGuards();

        DB::transaction(function (): void {
            DB::table('memory_write_events')
                ->where('ledger_identity_version', 1)
                ->select(['id'])
                ->orderBy('id')
                ->chunkById(250, function ($events): void {
                    foreach ($events as $event) {
                        $this->hardenWriteEvent($event);
                    }
                });

            // The canonical link mirrors the ledger identity while it exists.
            // Transform it independently because forgotten event tombstones no
            // longer have a link and historical links may lack an event FK.
            DB::table('memory_links')
                ->where('ledger_identity_version', 1)
                ->select(['id'])
                ->orderBy('id')
                ->chunkById(250, function ($links): void {
                    foreach ($links as $link) {
                        $this->hardenMemoryLink($link);
                    }
                });
        }, 3);

        $this->validateVersionGuards();
    }

    public function down(): void
    {
        // One-way by design: an HMAC cannot be converted back into its
        // enumerable SHA-only predecessor without retaining sensitive input.
    }

    private function addIdentityVersionColumn(string $tableName): void
    {
        if (Schema::hasColumn($tableName, 'ledger_identity_version')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            // Version 1 remains the deliberate database default. It is an
            // old-writer tripwire: current writers must opt into v2/v3 and
            // PostgreSQL/MySQL reject an omitted version before raw SHA
            // identities can be mislabeled as hardened ones.
            $table->unsignedTinyInteger('ledger_identity_version')
                ->default(1)
                ->after('write_fingerprint');
        });
    }

    private function installVersionGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            foreach (['memory_links', 'memory_write_events'] as $tableName) {
                $constraint = $tableName.'_ledger_identity_contract_check';
                $exists = DB::table('pg_constraint')->where('conname', $constraint)->exists();
                if (! $exists) {
                    $allowed = $tableName === 'memory_write_events'
                        ? 'IN (2, 3)'
                        : '= 2';
                    DB::statement(
                        "ALTER TABLE \"{$tableName}\" ADD CONSTRAINT \"{$constraint}\" "
                        ."CHECK (\"ledger_identity_version\" {$allowed}) NOT VALID"
                    );
                }
            }

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach (['memory_links', 'memory_write_events'] as $tableName) {
                $allowed = $tableName === 'memory_write_events'
                    ? 'NOT IN (2, 3)'
                    : '<> 2';
                foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $operation) {
                    $trigger = $tableName.'_ledger_identity_v2_'.$suffix;
                    DB::unprepared("DROP TRIGGER IF EXISTS `{$trigger}`");
                    DB::unprepared(
                        "CREATE TRIGGER `{$trigger}` BEFORE {$operation} ON `{$tableName}` FOR EACH ROW "
                        ."BEGIN IF NEW.ledger_identity_version {$allowed} THEN SIGNAL SQLSTATE '45000' "
                        ."SET MESSAGE_TEXT = 'Memory ledger identity contract version is invalid'; END IF; END"
                    );
                }
            }

            return;
        }

        if ($driver === 'sqlite') {
            foreach (['memory_links', 'memory_write_events'] as $tableName) {
                $allowed = $tableName === 'memory_write_events'
                    ? 'NOT IN (2, 3)'
                    : '<> 2';
                foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $operation) {
                    $trigger = $tableName.'_ledger_identity_v2_'.$suffix;
                    DB::unprepared("DROP TRIGGER IF EXISTS \"{$trigger}\"");
                    DB::unprepared(
                        "CREATE TRIGGER \"{$trigger}\" BEFORE {$operation} ON \"{$tableName}\" "
                        ."FOR EACH ROW WHEN NEW.ledger_identity_version {$allowed} BEGIN "
                        ."SELECT RAISE(ABORT, 'Memory ledger identity contract version is invalid'); END"
                    );
                }
            }

            return;
        }

        throw new RuntimeException('Unsupported database for the memory ledger cutover guard.');
    }

    private function validateVersionGuards(): void
    {
        if (DB::table('memory_links')->where('ledger_identity_version', '!=', 2)->exists()
            || DB::table('memory_write_events')->whereNotIn('ledger_identity_version', [2, 3])->exists()) {
            throw new RuntimeException('Memory ledger cutover left a legacy identity row.');
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['memory_links', 'memory_write_events'] as $tableName) {
            $constraint = $tableName.'_ledger_identity_contract_check';
            DB::statement("ALTER TABLE \"{$tableName}\" VALIDATE CONSTRAINT \"{$constraint}\"");
        }
    }

    private function hardenWriteEvent(object $event): void
    {
        // The chunk row is only a cursor. Account deletion can rekey a row to
        // the v3 erasure domain between the chunk SELECT and this call; always
        // lock and classify the current row before deriving any replacement.
        $event = DB::table('memory_write_events')
            ->where('id', $event->id)
            ->lockForUpdate()
            ->first([
                'id',
                'idempotency_key',
                'write_fingerprint',
                'ledger_identity_version',
                'memory_link_id',
                'user_id',
                'dataset',
                'state',
                'forgotten_at',
            ]);
        if ($event === null || (int) $event->ledger_identity_version !== 1) {
            return;
        }

        $idempotency = MemoryLedgerIdentity::idempotency((string) $event->idempotency_key);
        $fingerprint = MemoryLedgerIdentity::fingerprint((string) $event->write_fingerprint);
        $erased = $event->state === 'forgotten'
            && str_starts_with((string) $event->dataset, 'erased:v1:');
        if ($erased) {
            $idempotency = MemoryLedgerIdentity::erasedIdempotency($idempotency);
            $fingerprint = MemoryLedgerIdentity::erasedFingerprint($fingerprint);
        }
        $identityVersion = $erased ? 3 : 2;
        $existing = DB::table('memory_write_events')
            ->where('idempotency_key', $idempotency)
            ->where('id', '!=', $event->id)
            ->lockForUpdate()
            ->first();
        if ($existing === null) {
            DB::table('memory_write_events')->where('id', $event->id)->update([
                'idempotency_key' => $idempotency,
                'write_fingerprint' => $fingerprint,
                'ledger_identity_version' => $identityVersion,
                'updated_at' => now(),
            ]);

            return;
        }

        $sameFingerprint = hash_equals((string) $existing->write_fingerprint, $fingerprint);
        $eventLinkId = $event->memory_link_id === null ? null : (int) $event->memory_link_id;
        $existingLinkId = $existing->memory_link_id === null ? null : (int) $existing->memory_link_id;
        $sameLink = $eventLinkId === null || $existingLinkId === null || $eventLinkId === $existingLinkId;
        if (! $sameFingerprint || ! $sameLink || (string) $existing->dataset !== (string) $event->dataset) {
            throw new RuntimeException('Memory ledger v1/v2 collision could not be merged safely.');
        }

        $forgotten = $existing->state === 'forgotten' || $event->state === 'forgotten';
        if ($forgotten) {
            $this->eraseForgottenCollisionLinks([$existingLinkId, $eventLinkId]);
        }
        DB::table('memory_write_events')->where('id', $existing->id)->update([
            'memory_link_id' => $forgotten ? null : ($existingLinkId ?? $eventLinkId),
            'user_id' => $existing->user_id ?? $event->user_id,
            'state' => $forgotten ? 'forgotten' : $existing->state,
            'forgotten_at' => $forgotten
                ? ($existing->forgotten_at ?? $event->forgotten_at ?? now())
                : $existing->forgotten_at,
            'ledger_identity_version' => $identityVersion,
            'updated_at' => now(),
        ]);
        DB::table('memory_write_events')->where('id', $event->id)->delete();
    }

    private function hardenMemoryLink(object $linkCursor): void
    {
        // As with events, a runtime writer may already have installed a v2
        // identity after the chunk snapshot. Never HMAC a current HMAC again.
        $link = DB::table('memory_links')
            ->where('id', $linkCursor->id)
            ->lockForUpdate()
            ->first(['id', 'idempotency_key', 'write_fingerprint', 'ledger_identity_version']);
        if ($link === null || (int) $link->ledger_identity_version !== 1) {
            return;
        }

        $attributes = [
            'ledger_identity_version' => 2,
            'updated_at' => now(),
        ];
        if ($link->idempotency_key !== null) {
            $attributes['idempotency_key'] = MemoryLedgerIdentity::idempotency(
                (string) $link->idempotency_key
            );
        }
        if ($link->write_fingerprint !== null) {
            $attributes['write_fingerprint'] = MemoryLedgerIdentity::fingerprint(
                (string) $link->write_fingerprint
            );
        }

        DB::table('memory_links')
            ->where('id', $link->id)
            ->where('ledger_identity_version', 1)
            ->update($attributes);
    }

    /** @param array<int|null> $linkIds */
    private function eraseForgottenCollisionLinks(array $linkIds): void
    {
        $linkIds = array_values(array_unique(array_filter(
            $linkIds,
            static fn (?int $linkId): bool => $linkId !== null,
        )));

        foreach ($linkIds as $linkId) {
            $link = DB::table('memory_links')
                ->where('id', $linkId)
                ->lockForUpdate()
                ->first([
                    'id',
                    'user_id',
                    'dataset',
                    'content_hash',
                    'cognee_memory_id',
                    'projection_status',
                ]);
            if ($link === null) {
                continue;
            }

            $dataIds = [trim((string) $link->cognee_memory_id)];
            $outboxes = DB::table('memory_projection_outbox')
                ->where('memory_link_id', $link->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'action',
                    'payload',
                    'status',
                ]);

            foreach ($outboxes as $outbox) {
                $payload = $this->decodePayload($outbox->payload);
                if (in_array($outbox->action, ['upsert', 'delete'], true)) {
                    $dataIds[] = trim((string) ($payload['cognee_memory_id'] ?? ''));
                    $recovered = $payload['recovered_data_ids'] ?? [];
                    if (is_array($recovered)) {
                        foreach ($recovered as $dataId) {
                            $dataIds[] = trim((string) $dataId);
                        }
                    }
                }

                $updates = [
                    'memory_link_id' => null,
                    'updated_at' => now(),
                ];
                if ($outbox->action === 'upsert') {
                    $storedProviderLinkId = array_key_exists('provider_memory_link_id', $payload)
                        ? $this->positiveInteger($payload['provider_memory_link_id'])
                        : null;
                    if (array_key_exists('provider_memory_link_id', $payload)
                        && $storedProviderLinkId === null) {
                        throw new RuntimeException(
                            'Forgotten ledger collision has an invalid provider filename identity.'
                        );
                    }
                    if ($storedProviderLinkId !== null && $storedProviderLinkId !== (int) $link->id) {
                        throw new RuntimeException(
                            'Forgotten ledger collision changed its provider filename identity.'
                        );
                    }

                    // The numeric SQL link id is part of Cognee's deterministic
                    // filename, but is not an account identity. Preserve only
                    // that recovery key and the content hash; content itself is
                    // irreversibly removed before the canonical link vanishes.
                    $payload['provider_memory_link_id'] = (int) $link->id;
                    $contentIdentity = MemoryProviderIdentity::resolveContentHash($payload, $link->content_hash);
                    if ($contentIdentity['error'] !== null || $contentIdentity['content_hash'] === null) {
                        throw new RuntimeException(
                            'Forgotten ledger collision has an invalid or conflicting provider content identity.'
                        );
                    }
                    $payload['content_hash'] = $contentIdentity['content_hash'];
                    $payload['source_erasure_reason'] = 'forgotten_ledger_collision';
                    $payload['content_snapshot_erased_at'] ??= now()->toIso8601String();
                    unset(
                        $payload['content'],
                        $payload['content_ciphertext'],
                        $payload['content_snapshot_expires_at'],
                    );
                    $updates['payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
                }
                if ($outbox->action === 'delete') {
                    $providerIdentity = MemoryProviderIdentity::resolve($payload, $link->id);
                    if ($providerIdentity['error'] !== null || $providerIdentity['identity'] === null) {
                        throw new RuntimeException(
                            'Forgotten ledger collision has an invalid or conflicting Delete filename identity.'
                        );
                    }
                    $payload['provider_memory_link_id'] = $providerIdentity['identity'];
                    $payload['erasure_reason'] = 'forgotten_ledger_collision';
                    unset(
                        $payload['content'],
                        $payload['content_ciphertext'],
                        $payload['content_snapshot_expires_at'],
                    );
                    $updates['payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
                }
                $unsafeTerminalUpsert = $outbox->action === 'upsert'
                    && $outbox->status === 'done'
                    && ! $this->terminalUpsertIsSafe($payload);
                $unsafeTerminalDelete = $outbox->action === 'delete'
                    && $outbox->status === 'done'
                    && trim((string) ($payload['cognee_memory_id'] ?? '')) !== ''
                    && trim((string) ($payload['exact_forget_ack_at'] ?? '')) === '';
                if (in_array($outbox->action, ['upsert', 'delete'], true)
                    && ($unsafeTerminalUpsert
                        || $unsafeTerminalDelete
                        || ! in_array($outbox->status, ['done', 'processing'], true))) {
                    // A dispatcher can safely resume this row after restart.
                    // In-flight upserts retain their encrypted recovery state
                    // so any provider object created concurrently is found and
                    // compensated by the normal projection state machine.
                    $updates = array_merge($updates, [
                        'status' => 'pending',
                        'last_error' => null,
                        'next_attempt_at' => null,
                        'processed_at' => null,
                    ]);
                }
                DB::table('memory_projection_outbox')->where('id', $outbox->id)->update($updates);
            }

            $dataIds = array_values(array_unique(array_filter($dataIds)));
            foreach ($dataIds as $dataId) {
                if (! $this->isUuid($dataId)) {
                    throw new RuntimeException('Forgotten ledger collision has an invalid provider identity.');
                }
                $this->queueCollisionDelete($link, $dataId);
            }

            $recoverableUpsertExists = $outboxes->contains(
                fn (object $row): bool => $row->action === 'upsert'
                    && ($row->status !== 'done'
                        || ! $this->terminalUpsertIsSafe($this->decodePayload($row->payload))),
            );
            if ($dataIds === []
                && in_array($link->projection_status, ['ready', 'processing', 'delete_pending'], true)
                && ! $recoverableUpsertExists) {
                throw new RuntimeException(
                    'Forgotten ledger collision lost the provider identity required for compensation.'
                );
            }

            DB::table('memory_write_events')
                ->where('memory_link_id', $link->id)
                ->update([
                    'memory_link_id' => null,
                    'state' => 'forgotten',
                    'forgotten_at' => now(),
                    'updated_at' => now(),
                ]);
            DB::table('memory_links')->where('id', $link->id)->delete();
        }
    }

    private function queueCollisionDelete(object $link, string $dataId): void
    {
        // A resumed lost-response Upsert uses this preserved provider filename
        // identity too, so migration and runtime converge on one exact Forget.
        $dedupe = hash('sha256', implode('|', [
            'delete',
            $link->dataset,
            $link->id,
            $dataId,
        ]));
        DB::table('memory_projection_outbox')->insertOrIgnore([
            'memory_link_id' => null,
            'user_id' => $link->user_id,
            'action' => 'delete',
            'dataset' => $link->dataset,
            'dedupe_key' => $dedupe,
            'payload' => json_encode(array_filter([
                'cognee_memory_id' => $dataId,
                'content_hash' => $link->content_hash,
                'provider_memory_link_id' => (int) $link->id,
                'erasure_reason' => 'forgotten_ledger_collision',
            ], static fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $existing = DB::table('memory_projection_outbox')
            ->where('dedupe_key', $dedupe)
            ->lockForUpdate()
            ->first();
        if ($existing === null
            || $existing->action !== 'delete'
            || ! hash_equals((string) $link->dataset, (string) $existing->dataset)) {
            throw new RuntimeException('Forgotten ledger collision Delete identity is inconsistent.');
        }

        $payload = $this->decodePayload($existing->payload);
        if (! hash_equals($dataId, trim((string) ($payload['cognee_memory_id'] ?? '')))) {
            throw new RuntimeException('Forgotten ledger collision Delete Data UUID is inconsistent.');
        }
        $providerIdentity = MemoryProviderIdentity::resolve($payload, $link->id);
        $contentIdentity = MemoryProviderIdentity::resolveContentHash($payload, $link->content_hash);
        if ($providerIdentity['error'] !== null
            || $providerIdentity['identity'] === null
            || $contentIdentity['error'] !== null
            || $contentIdentity['content_hash'] === null) {
            throw new RuntimeException('Forgotten ledger collision Delete recovery identity is invalid.');
        }
        $payload['provider_memory_link_id'] = $providerIdentity['identity'];
        $payload['content_hash'] = $contentIdentity['content_hash'];
        if (trim((string) ($payload['erasure_reason'] ?? '')) === '') {
            $payload['erasure_reason'] = 'forgotten_ledger_collision';
        }
        unset(
            $payload['content'],
            $payload['content_ciphertext'],
            $payload['content_snapshot_expires_at'],
        );

        $updates = [
            'memory_link_id' => null,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];
        $exactForgetAcknowledged = trim((string) ($payload['exact_forget_ack_at'] ?? '')) !== '';
        if ($existing->status !== 'processing'
            && ! ($existing->status === 'done' && $exactForgetAcknowledged)) {
            $updates = array_merge($updates, [
                'status' => 'pending',
                'last_error' => null,
                'next_attempt_at' => null,
                'processed_at' => null,
            ]);
        }
        DB::table('memory_projection_outbox')->where('id', $existing->id)->update($updates);
    }

    /** @param array<string,mixed> $payload */
    private function terminalUpsertIsSafe(array $payload): bool
    {
        if (trim((string) ($payload['launch_ack_pending_key'] ?? '')) !== '') {
            return false;
        }

        return ! in_array((string) ($payload['phase'] ?? 'new'), [
            'adding',
            'cognify_launching',
            'polling',
            'launch_ack_pending_terminal',
        ], true);
    }

    /** @return array<string,mixed> */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value,
        ) === 1;
    }

    private function positiveInteger(mixed $value): ?int
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
};
