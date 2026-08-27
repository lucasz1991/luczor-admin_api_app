<?php

use App\Services\MemoryErasureIdentity;
use App\Services\MemoryLedgerIdentity;
use App\Services\MemoryProviderIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const USER_FK_TABLES = ['memory_links', 'memory_projection_outbox', 'memory_write_events'];

    /** @var list<string> */
    private const USER_OWNED_SCOPES = ['device', 'user', 'private', 'project', 'skill', 'agent', 'session'];

    /** @var list<string> */
    private const SHARED_SCOPES = ['workspace', 'global'];

    public function up(): void
    {
        // Run the provider-recovery preflight before MySQL's auto-committing
        // DDL. An ambiguous historical Add must stop the cutover without
        // leaving a partially replaced foreign-key topology behind.
        $this->assertNoUnrecoverableOwnerlessAdds();

        // Raw/bulk deletes bypass model events. RESTRICT turns such a path
        // into a hard failure instead of silently orphaning memory. MySQL
        // auto-commits DDL, so install a second named RESTRICT constraint on
        // every table before replacing even the first legacy constraint.
        $this->installRestrictGuards();
        foreach (self::USER_FK_TABLES as $tableName) {
            $this->replaceUserForeignKey($tableName, 'restrict');
        }
        $this->dropRestrictGuards();

        // The DDL lock above closes the old NULL-ON-DELETE race first. Rows
        // orphaned before that point cannot be safely reassigned: erase their
        // canonical SQL content and retain only content-free recovery state so
        // known or ambiguous Cognee objects can still be forgotten.
        $this->erasePreexistingOwnerlessUserMemory();
        $this->detachPreexistingOwnerlessSharedActors();
        $this->erasePreexistingOwnerlessOutboxOnlyRows();

        // The observer explicitly detaches content-free tombstones and
        // provider cleanup rows before the final user DELETE. Their RESTRICT
        // constraints therefore remain as permanent bulk-delete guards.
    }

    public function down(): void
    {
        // Keep a RESTRICT guard on all tables while the canonical constraints
        // are restored one by one. Every table therefore has a valid user FK
        // throughout MySQL's auto-committing rollback path as well.
        $this->installRestrictGuards();
        $this->replaceUserForeignKey('memory_write_events', 'cascade');
        $this->replaceUserForeignKey('memory_projection_outbox', 'set null');
        $this->replaceUserForeignKey('memory_links', 'set null');
        $this->dropRestrictGuards();
    }

    private function installRestrictGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        foreach (self::USER_FK_TABLES as $tableName) {
            $constraint = $this->guardConstraintName($tableName);
            if (! $this->foreignKeyExists($driver, $tableName, $constraint)) {
                DB::statement($this->userGuardForeignKeySql($driver, $tableName, 'add'));
            }
        }
    }

    private function dropRestrictGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        foreach (self::USER_FK_TABLES as $tableName) {
            $constraint = $this->guardConstraintName($tableName);
            if ($this->foreignKeyExists($driver, $tableName, $constraint)) {
                DB::statement($this->userGuardForeignKeySql($driver, $tableName, 'drop'));
            }
        }
    }

    private function guardConstraintName(string $tableName): string
    {
        if (! in_array($tableName, self::USER_FK_TABLES, true)) {
            throw new RuntimeException('Unsupported memory user foreign-key guard table.');
        }

        return $tableName.'_user_id_erasure_guard_foreign';
    }

    private function userGuardForeignKeySql(string $driver, string $tableName, string $operation): string
    {
        $constraint = $this->guardConstraintName($tableName);
        if (! in_array($operation, ['add', 'drop'], true)) {
            throw new RuntimeException('Unsupported memory user foreign-key guard operation.');
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return $operation === 'add'
                ? "ALTER TABLE `{$tableName}` ADD CONSTRAINT `{$constraint}` "
                    .'FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT'
                : "ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$constraint}`";
        }
        if ($driver === 'pgsql') {
            return $operation === 'add'
                ? "ALTER TABLE \"{$tableName}\" ADD CONSTRAINT \"{$constraint}\" "
                    .'FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE RESTRICT'
                : "ALTER TABLE \"{$tableName}\" DROP CONSTRAINT \"{$constraint}\"";
        }

        throw new RuntimeException('Unsupported memory user foreign-key guard driver.');
    }

    private function foreignKeyExists(string $driver, string $tableName, string $constraint): bool
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', $tableName)
                ->where('CONSTRAINT_NAME', $constraint)
                ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
                ->exists();
        }
        if ($driver === 'pgsql') {
            return DB::table('pg_constraint as pc')
                ->join('pg_class as tables', 'tables.oid', '=', 'pc.conrelid')
                ->join('pg_namespace as namespaces', 'namespaces.oid', '=', 'tables.relnamespace')
                ->where('tables.relname', $tableName)
                ->where('pc.conname', $constraint)
                ->where('pc.contype', 'f')
                ->whereRaw('namespaces.nspname = current_schema()')
                ->exists();
        }

        return false;
    }

    private function replaceUserForeignKey(string $tableName, string $onDelete): void
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $guard = $this->guardConstraintName($tableName);
            if (! $this->foreignKeyExists($driver, $tableName, $guard)) {
                throw new RuntimeException(
                    "Memory erasure guard is missing before replacing {$tableName}.user_id."
                );
            }

            $constraint = $tableName.'_user_id_foreign';
            if ($this->foreignKeyExists($driver, $tableName, $constraint)) {
                DB::statement($this->mysqlUserForeignKeySql($tableName, $onDelete, 'drop'));
            }
            if (! $this->foreignKeyExists($driver, $tableName, $constraint)) {
                DB::statement($this->mysqlUserForeignKeySql($tableName, $onDelete, 'add'));
            }

            return;
        }

        $sql = $this->userForeignKeySql($driver, $tableName, $onDelete);
        if ($sql !== null) {
            // PostgreSQL takes one table lock for the atomic replacement.
            DB::statement($sql);

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($onDelete): void {
            $table->dropForeign(['user_id']);
            $foreign = $table->foreign('user_id')->references('id')->on('users');
            match ($onDelete) {
                'restrict' => $foreign->restrictOnDelete(),
                'set null' => $foreign->nullOnDelete(),
                'cascade' => $foreign->cascadeOnDelete(),
                default => throw new RuntimeException('Unsupported memory user foreign-key action.'),
            };
        });
    }

    private function userForeignKeySql(string $driver, string $tableName, string $onDelete): ?string
    {
        $allowedTables = ['memory_links', 'memory_projection_outbox', 'memory_write_events'];
        $actions = [
            'restrict' => 'RESTRICT',
            'set null' => 'SET NULL',
            'cascade' => 'CASCADE',
        ];
        if (! in_array($tableName, $allowedTables, true) || ! isset($actions[$onDelete])) {
            throw new RuntimeException('Unsupported memory user foreign-key replacement.');
        }

        $constraint = $tableName.'_user_id_foreign';
        if ($driver === 'pgsql') {
            return "ALTER TABLE \"{$tableName}\" DROP CONSTRAINT \"{$constraint}\", "
                ."ADD CONSTRAINT \"{$constraint}\" FOREIGN KEY (\"user_id\") REFERENCES \"users\" (\"id\") "
                .'ON DELETE '.$actions[$onDelete];
        }

        return null;
    }

    private function mysqlUserForeignKeySql(
        string $tableName,
        string $onDelete,
        string $operation,
    ): string {
        $allowedTables = ['memory_links', 'memory_projection_outbox', 'memory_write_events'];
        $actions = [
            'restrict' => 'RESTRICT',
            'set null' => 'SET NULL',
            'cascade' => 'CASCADE',
        ];
        if (! in_array($tableName, $allowedTables, true)
            || ! isset($actions[$onDelete])
            || ! in_array($operation, ['drop', 'add'], true)) {
            throw new RuntimeException('Unsupported MySQL memory user foreign-key operation.');
        }

        $constraint = $tableName.'_user_id_foreign';

        return $operation === 'drop'
            ? "ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$constraint}`"
            : "ALTER TABLE `{$tableName}` ADD CONSTRAINT `{$constraint}` "
                ."FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE {$actions[$onDelete]}";
    }

    private function erasePreexistingOwnerlessUserMemory(): void
    {
        $ledgerContractInstalled = Schema::hasColumn('memory_links', 'ledger_identity_version')
            && Schema::hasColumn('memory_write_events', 'ledger_identity_version');
        $linkColumns = [
            'id',
            'dataset',
            'cognee_memory_id',
            'content_hash',
            'idempotency_key',
            'write_fingerprint',
            'provenance',
            'client_id',
            'external_id',
        ];
        if ($ledgerContractInstalled) {
            $linkColumns[] = 'ledger_identity_version';
        }

        DB::table('memory_links')
            ->whereNull('user_id')
            ->whereIn('scope', self::USER_OWNED_SCOPES)
            ->select($linkColumns)
            ->orderBy('id')
            ->chunkById(250, function ($links) use ($ledgerContractInstalled): void {
                foreach ($links as $link) {
                    $datasetUserId = $this->formerUserIdFromDataset((string) $link->dataset);
                    $provenance = $this->decodePayload($link->provenance);
                    $provenanceUserId = $this->positiveInteger($provenance['actor_user_id'] ?? null);
                    if ($datasetUserId !== null
                        && $provenanceUserId !== null
                        && $datasetUserId !== $provenanceUserId) {
                        $this->quarantineOwnerlessLink((int) $link->id, 'ownerless_identity_conflict_review_required');

                        continue;
                    }

                    $formerUserId = $datasetUserId ?? $provenanceUserId;
                    if ($formerUserId === null) {
                        // NULL predates user ownership and is not proof of an
                        // erased account. Keep ambiguous system/legacy rows in
                        // their existing fail-closed review quarantine.
                        $this->quarantineOwnerlessLink((int) $link->id, 'ownerless_scope_review_required');

                        continue;
                    }
                    $reattached = DB::transaction(function () use (
                        $formerUserId,
                        $link,
                    ): bool {
                        // This locking read and the reattach update must share
                        // one transaction. A concurrent account deletion then
                        // either waits for the link to become owned, or commits
                        // first and makes this current read return no user.
                        $ownerStillExists = DB::table('users')
                            ->where('id', $formerUserId)
                            ->lockForUpdate()
                            ->exists();
                        if (! $ownerStillExists) {
                            return false;
                        }

                        $ownedReplacementExists = DB::table('memory_links')
                            ->where('user_id', $formerUserId)
                            ->where('client_id', $link->client_id)
                            ->where('dataset', $link->dataset)
                            ->where('external_id', $link->external_id)
                            ->where('id', '!=', $link->id)
                            ->lockForUpdate()
                            ->exists();
                        if ($ownedReplacementExists) {
                            // Reattaching would violate the canonical unique
                            // identity. The owned row is authoritative; the
                            // caller uses the recoverable erasure path instead.
                            return false;
                        }

                        // A raw legacy dataset or an authenticated actor in
                        // provenance is positive ownership evidence. Holding
                        // the user-row lock closes the deletion race until the
                        // formerly detached link is visible to normal erasure.
                        DB::table('memory_links')->where('id', $link->id)->update([
                            'user_id' => $formerUserId,
                            'write_reason' => 'ownerless_scope_reattached',
                            'updated_at' => now(),
                        ]);

                        return true;
                    });
                    if ($reattached) {
                        continue;
                    }

                    DB::transaction(function () use ($ledgerContractInstalled, $link): void {
                        $dataIds = array_filter([trim((string) $link->cognee_memory_id)]);
                        $upserts = DB::table('memory_projection_outbox')
                            ->where('memory_link_id', $link->id)
                            ->where('action', 'upsert')
                            ->orderBy('id')
                            ->get();
                        $linkedDeletes = DB::table('memory_projection_outbox')
                            ->where('memory_link_id', $link->id)
                            ->where('action', 'delete')
                            ->orderBy('id')
                            ->get();

                        foreach ($linkedDeletes as $linkedDelete) {
                            $deletePayload = $this->decodePayload($linkedDelete->payload);
                            unset(
                                $deletePayload['content'],
                                $deletePayload['content_ciphertext'],
                                $deletePayload['content_snapshot_expires_at'],
                            );
                            $deletePayload['erasure_reason'] = 'legacy_ownerless_user_scope';
                            $exactForgetAcknowledged = trim((string) ($deletePayload['exact_forget_ack_at'] ?? '')) !== '';
                            $hasProviderIdentity = trim((string) ($deletePayload['cognee_memory_id'] ?? '')) !== '';
                            if ($linkedDelete->status === 'done'
                                && ($exactForgetAcknowledged || ! $hasProviderIdentity)) {
                                $this->terminalizeOwnerlessOutbox(
                                    $linkedDelete,
                                    $deletePayload,
                                    'legacy_ownerless_user_scope',
                                );

                                continue;
                            }

                            $updates = [
                                'user_id' => null,
                                'payload' => json_encode($deletePayload, JSON_THROW_ON_ERROR),
                                'updated_at' => now(),
                            ];
                            if ($linkedDelete->status !== 'processing') {
                                $updates = array_merge($updates, [
                                    'status' => 'queued',
                                    'last_error' => null,
                                    'next_attempt_at' => null,
                                    'processed_at' => null,
                                ]);
                            }
                            DB::table('memory_projection_outbox')->where('id', $linkedDelete->id)->update($updates);
                        }

                        foreach ($upserts as $upsert) {
                            $payload = $this->decodePayload($upsert->payload);
                            $payload = $this->preserveOwnerlessAddRecoveryIdentity(
                                $upsert,
                                $payload,
                                $link->content_hash,
                            );
                            $dataIds[] = trim((string) ($payload['cognee_memory_id'] ?? ''));
                            $recovered = $payload['recovered_data_ids'] ?? [];
                            if (is_array($recovered)) {
                                foreach ($recovered as $dataId) {
                                    $dataIds[] = trim((string) $dataId);
                                }
                            }
                            $payload['source_erasure_reason'] = 'legacy_ownerless_user_scope';
                            $payload['content_snapshot_erased_at'] ??= now()->toIso8601String();
                            unset(
                                $payload['content'],
                                $payload['content_ciphertext'],
                                $payload['content_snapshot_expires_at'],
                            );
                            $payload['account_erasure_reason'] = 'legacy_ownerless_user_scope';
                            $updates = [
                                'user_id' => null,
                                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                                'updated_at' => now(),
                            ];
                            if ($upsert->status !== 'done' || ! $this->terminalUpsertIsSafe($payload)) {
                                $updates = array_merge($updates, [
                                    'status' => 'queued',
                                    'last_error' => null,
                                    'next_attempt_at' => null,
                                    'processed_at' => null,
                                ]);
                            } else {
                                $updates = array_merge($updates, [
                                    'memory_link_id' => null,
                                    'dataset' => MemoryErasureIdentity::dataset((string) $link->dataset),
                                    'dedupe_key' => MemoryErasureIdentity::dedupe((string) $upsert->dedupe_key),
                                    'payload' => json_encode([
                                        'phase' => 'erasure_cleanup_complete',
                                        'erasure_reason' => 'legacy_ownerless_user_scope',
                                    ], JSON_THROW_ON_ERROR),
                                ]);
                            }
                            DB::table('memory_projection_outbox')->where('id', $upsert->id)->update($updates);
                        }

                        $dataIds = array_values(array_unique(array_filter(
                            $dataIds,
                            fn (string $dataId): bool => preg_match(
                                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                                $dataId,
                            ) === 1,
                        )));
                        foreach ($dataIds as $dataId) {
                            $dedupe = hash('sha256', implode('|', [
                                'delete',
                                $link->dataset,
                                $link->id,
                                $dataId,
                            ]));
                            DB::table('memory_projection_outbox')->insertOrIgnore([
                                'memory_link_id' => $link->id,
                                'user_id' => null,
                                'action' => 'delete',
                                'dataset' => $link->dataset,
                                'dedupe_key' => $dedupe,
                                'payload' => json_encode([
                                    'cognee_memory_id' => $dataId,
                                    'content_hash' => $link->content_hash,
                                    'erasure_reason' => 'legacy_ownerless_user_scope',
                                ], JSON_THROW_ON_ERROR),
                                'status' => 'queued',
                                'attempts' => 0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            DB::table('memory_projection_outbox')
                                ->where('dedupe_key', $dedupe)
                                ->whereNotIn('status', ['done', 'processing'])
                                ->update([
                                    'user_id' => null,
                                    'status' => 'queued',
                                    'last_error' => null,
                                    'next_attempt_at' => null,
                                    'processed_at' => null,
                                    'updated_at' => now(),
                                ]);
                        }

                        if ($link->idempotency_key
                            && $link->write_fingerprint
                            && ! DB::table('memory_write_events')
                                ->where('memory_link_id', $link->id)
                                ->exists()) {
                            $idempotencyKey = (string) $link->idempotency_key;
                            $writeFingerprint = (string) $link->write_fingerprint;
                            $identityVersion = (int) ($link->ledger_identity_version ?? 1);
                            if ($ledgerContractInstalled) {
                                [$idempotencyKey, $writeFingerprint] = $this->erasedLedgerIdentity(
                                    $idempotencyKey,
                                    $writeFingerprint,
                                    $identityVersion,
                                );
                            }
                            $event = [
                                'idempotency_key' => $idempotencyKey,
                                'write_fingerprint' => $writeFingerprint,
                                'memory_link_id' => $link->id,
                                'user_id' => null,
                                'dataset' => MemoryErasureIdentity::dataset((string) $link->dataset),
                                'state' => 'forgotten',
                                'forgotten_at' => now(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                            if ($ledgerContractInstalled) {
                                $event['ledger_identity_version'] = 3;
                            }
                            DB::table('memory_write_events')->insertOrIgnore($event);
                        }
                        $this->tombstoneOwnerlessWriteEvents($link, $ledgerContractInstalled);
                        $improves = DB::table('memory_projection_outbox')
                            ->where('dataset', $link->dataset)
                            ->where('action', 'improve')
                            ->orderBy('id')
                            ->get();
                        foreach ($improves as $improve) {
                            $improvePayload = $this->decodePayload($improve->payload);
                            $improvePayload['account_erasure_reason'] = 'legacy_ownerless_user_scope';
                            if ($improve->status === 'processing' || $this->hasLiveImproveRun($improvePayload)) {
                                $updates = [
                                    'user_id' => null,
                                    'payload' => json_encode($improvePayload, JSON_THROW_ON_ERROR),
                                    'updated_at' => now(),
                                ];
                                if ($improve->status !== 'processing') {
                                    $updates = array_merge($updates, [
                                        'status' => 'queued',
                                        'last_error' => null,
                                        'next_attempt_at' => null,
                                        'processed_at' => null,
                                    ]);
                                }
                                DB::table('memory_projection_outbox')->where('id', $improve->id)->update($updates);

                                continue;
                            }

                            DB::table('memory_projection_outbox')->where('id', $improve->id)->update([
                                'memory_link_id' => null,
                                'user_id' => null,
                                'dataset' => MemoryErasureIdentity::dataset((string) $link->dataset),
                                'dedupe_key' => MemoryErasureIdentity::dedupe((string) $improve->dedupe_key),
                                'payload' => json_encode([
                                    'phase' => 'erasure_cleanup_complete',
                                    'erasure_reason' => 'legacy_ownerless_user_scope',
                                ], JSON_THROW_ON_ERROR),
                                'status' => 'done',
                                'last_error' => null,
                                'next_attempt_at' => null,
                                'processed_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        DB::table('memory_links')->where('id', $link->id)->delete();
                    });
                }
            });
    }

    private function tombstoneOwnerlessWriteEvents(object $link, bool $ledgerContractInstalled): void
    {
        $events = DB::table('memory_write_events')
            ->where('memory_link_id', $link->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($events as $event) {
            $attributes = [
                'user_id' => null,
                'dataset' => MemoryErasureIdentity::dataset((string) $link->dataset),
                'state' => 'forgotten',
                'forgotten_at' => $event->forgotten_at ?? now(),
                'updated_at' => now(),
            ];
            if ($ledgerContractInstalled) {
                [$idempotencyKey, $writeFingerprint] = $this->erasedLedgerIdentity(
                    (string) $event->idempotency_key,
                    (string) $event->write_fingerprint,
                    (int) $event->ledger_identity_version,
                );
                $collision = DB::table('memory_write_events')
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('id', '!=', $event->id)
                    ->lockForUpdate()
                    ->exists();
                if ($collision) {
                    throw new RuntimeException(
                        'Ownerless memory erasure found a conflicting hardened write tombstone.'
                    );
                }
                $attributes = array_merge($attributes, [
                    'idempotency_key' => $idempotencyKey,
                    'write_fingerprint' => $writeFingerprint,
                    'ledger_identity_version' => 3,
                ]);
            }
            DB::table('memory_write_events')->where('id', $event->id)->update($attributes);
        }
    }

    /** @return array{string,string} */
    private function erasedLedgerIdentity(string $idempotencyKey, string $writeFingerprint, int $version): array
    {
        if (! in_array($version, [1, 2, 3], true)) {
            throw new RuntimeException('Ownerless memory erasure found an unsupported ledger identity version.');
        }
        if ($version === 1) {
            $idempotencyKey = MemoryLedgerIdentity::idempotency($idempotencyKey);
            $writeFingerprint = MemoryLedgerIdentity::fingerprint($writeFingerprint);
            $version = 2;
        }
        if ($version === 2) {
            $idempotencyKey = MemoryLedgerIdentity::erasedIdempotency($idempotencyKey);
            $writeFingerprint = MemoryLedgerIdentity::erasedFingerprint($writeFingerprint);
        }

        return [$idempotencyKey, $writeFingerprint];
    }

    /**
     * Older NULL-ON-DELETE deployments can contain projection rows whose
     * account and canonical link are both gone while the raw user dataset,
     * encrypted recovery content or provider UUID remains. They are not found
     * by the MemoryLink repair above, so classify them independently.
     */
    private function erasePreexistingOwnerlessOutboxOnlyRows(): void
    {
        DB::table('memory_projection_outbox')
            ->whereNull('user_id')
            ->where(function ($query): void {
                $query->whereNull('memory_link_id')
                    ->orWhereNotExists(function ($links): void {
                        $links->selectRaw('1')
                            ->from('memory_links')
                            ->whereColumn('memory_links.id', 'memory_projection_outbox.memory_link_id');
                    });
            })
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(250, function ($rows): void {
                foreach ($rows as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        $outbox = DB::table('memory_projection_outbox')
                            ->where('id', $candidate->id)
                            ->lockForUpdate()
                            ->first();
                        if (! $outbox || $outbox->user_id !== null) {
                            return;
                        }
                        if ($outbox->memory_link_id !== null
                            && DB::table('memory_links')->where('id', $outbox->memory_link_id)->exists()) {
                            return;
                        }

                        // Missing canonical SQL ownership is sufficient proof
                        // that Upsert/Delete is orphaned, including opaque v2
                        // datasets whose account cannot be decoded. Improve is
                        // dataset-wide and may be shared, so only a positively
                        // identified raw user dataset may be erased here.
                        if (! in_array($outbox->action, ['upsert', 'delete'], true)
                            && $this->formerUserIdFromDataset((string) $outbox->dataset) === null) {
                            return;
                        }

                        $reason = 'legacy_ownerless_user_outbox';
                        $payload = $this->decodePayload($outbox->payload);
                        if ($outbox->action === 'upsert') {
                            $payload = $this->preserveOwnerlessAddRecoveryIdentity($outbox, $payload);
                        }
                        unset(
                            $payload['content'],
                            $payload['content_ciphertext'],
                            $payload['content_snapshot_expires_at'],
                        );

                        if ($outbox->action === 'upsert') {
                            $payload['account_erasure_reason'] = $reason;
                            foreach ($this->providerDataIds($payload) as $dataId) {
                                $this->ensureOwnerlessOutboxCompensation($outbox, $payload, $dataId, $reason);
                            }
                            if ($outbox->status === 'done' && $this->terminalUpsertIsSafe($payload)) {
                                $this->terminalizeOwnerlessOutbox($outbox, $payload, $reason);

                                return;
                            }

                            $this->wakeOwnerlessOutbox($outbox, $payload);

                            return;
                        }

                        if ($outbox->action === 'delete') {
                            $payload['erasure_reason'] = $reason;
                            $exactForgetAcknowledged = trim((string) ($payload['exact_forget_ack_at'] ?? '')) !== '';
                            $hasProviderIdentity = trim((string) ($payload['cognee_memory_id'] ?? '')) !== '';
                            if ($outbox->status === 'done'
                                && ($exactForgetAcknowledged || ! $hasProviderIdentity)) {
                                $this->terminalizeOwnerlessOutbox($outbox, $payload, $reason);

                                return;
                            }

                            $this->wakeOwnerlessOutbox($outbox, $payload);

                            return;
                        }

                        if ($outbox->action === 'improve') {
                            $payload['account_erasure_reason'] = $reason;
                            if ($outbox->status === 'done' && ! $this->hasLiveImproveRun($payload)) {
                                $this->terminalizeOwnerlessOutbox($outbox, $payload, $reason);

                                return;
                            }

                            $this->wakeOwnerlessOutbox($outbox, $payload);

                            return;
                        }

                        throw new RuntimeException(
                            'Unknown ownerless memory projection action '.$outbox->action.'.'
                        );
                    });
                }
            });
    }

    /** @return list<string> */
    private function providerDataIds(array $payload): array
    {
        $dataIds = [trim((string) ($payload['cognee_memory_id'] ?? ''))];
        $recovered = $payload['recovered_data_ids'] ?? [];
        if (is_array($recovered)) {
            foreach ($recovered as $dataId) {
                $dataIds[] = trim((string) $dataId);
            }
        }

        return array_values(array_unique(array_filter(
            $dataIds,
            fn (string $dataId): bool => preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $dataId,
            ) === 1,
        )));
    }

    /**
     * A persisted `adding` phase means the HTTP Add may already have reached
     * Cognee. Exact recovery uses the original MemoryLink ID embedded in the
     * deterministic provider filename. Without that ID, neither absence nor
     * all possible duplicates can be proven, so erasure must stop fail-closed.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function preserveOwnerlessAddRecoveryIdentity(
        object $outbox,
        array $payload,
        mixed $canonicalContentHash = null,
    ): array {
        $resolution = MemoryProviderIdentity::resolve($payload, $outbox->memory_link_id ?? null);
        if ($resolution['error'] === 'invalid_stored' || $resolution['error'] === 'invalid_current') {
            throw new RuntimeException(
                "Memory hardening blocked: ownerless Upsert {$outbox->id} has an invalid provider_memory_link_id. "
                .'Restore the original positive MemoryLink ID from trusted backup or audit evidence; do not guess.'
            );
        }
        if ($resolution['error'] === 'conflict') {
            throw new RuntimeException(
                "Memory hardening blocked: ownerless Upsert {$outbox->id} has conflicting provider filename identities."
            );
        }

        $providerId = $resolution['identity'];
        if ($providerId === null && (string) ($payload['phase'] ?? '') === 'adding') {
            throw new RuntimeException(
                "Memory hardening blocked: ownerless Upsert {$outbox->id} is in live adding state without "
                .'a verified provider_memory_link_id. Restore the original positive MemoryLink ID from trusted '
                .'backup or audit evidence, or complete an audited Cognee cleanup and mark the Add absent before '
                .'retrying; do not guess.'
            );
        }

        if ($providerId !== null) {
            $payload['provider_memory_link_id'] = $providerId;
        }
        $contentIdentity = MemoryProviderIdentity::resolveContentHash($payload, $canonicalContentHash);
        if ($contentIdentity['error'] !== null) {
            throw new RuntimeException(
                "Memory hardening blocked: ownerless Upsert {$outbox->id} has an invalid or conflicting content_hash."
            );
        }
        if ($contentIdentity['content_hash'] === null && (string) ($payload['phase'] ?? '') === 'adding') {
            throw new RuntimeException(
                "Memory hardening blocked: ownerless Upsert {$outbox->id} is in live adding state without "
                .'a verified content_hash. Restore it from trusted backup or audit evidence, or complete an audited '
                .'Cognee cleanup and mark the Add absent before retrying; do not guess.'
            );
        }
        if ($contentIdentity['content_hash'] !== null) {
            $payload['content_hash'] = $contentIdentity['content_hash'];
        }

        return $payload;
    }

    private function assertNoUnrecoverableOwnerlessAdds(): void
    {
        DB::table('memory_projection_outbox')
            ->whereNull('user_id')
            ->where('action', 'upsert')
            ->select(['id', 'memory_link_id', 'payload'])
            ->orderBy('id')
            ->chunkById(250, function ($rows): void {
                foreach ($rows as $outbox) {
                    $canonicalContentHash = $outbox->memory_link_id === null
                        ? null
                        : DB::table('memory_links')->where('id', $outbox->memory_link_id)->value('content_hash');
                    $this->preserveOwnerlessAddRecoveryIdentity(
                        $outbox,
                        $this->decodePayload($outbox->payload),
                        $canonicalContentHash,
                    );
                }
            });
    }

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

    private function hasLiveImproveRun(array $payload): bool
    {
        return in_array((string) ($payload['phase'] ?? ''), [
            'improve_launching',
            'improve_polling',
            'launch_ack_pending_terminal',
        ], true) || trim((string) ($payload['launch_ack_pending_key'] ?? '')) !== '';
    }

    private function ensureOwnerlessOutboxCompensation(
        object $source,
        array $payload,
        string $dataId,
        string $reason,
    ): void {
        $resolution = MemoryProviderIdentity::resolve($payload, $source->memory_link_id ?? null);
        if ($resolution['error'] !== null) {
            throw new RuntimeException('Ownerless provider compensation has an invalid filename identity.');
        }
        $providerMemoryLinkId = $resolution['identity'];
        $dedupe = hash('sha256', implode('|', [
            'delete',
            $source->dataset,
            $providerMemoryLinkId ?? 'none',
            $dataId,
        ]));
        DB::table('memory_projection_outbox')->insertOrIgnore([
            'memory_link_id' => $source->memory_link_id,
            'user_id' => null,
            'action' => 'delete',
            'dataset' => $source->dataset,
            'dedupe_key' => $dedupe,
            'payload' => json_encode(array_filter([
                'cognee_memory_id' => $dataId,
                'content_hash' => $payload['content_hash'] ?? null,
                'provider_memory_link_id' => $providerMemoryLinkId,
                'erasure_reason' => $reason,
            ], static fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function wakeOwnerlessOutbox(object $outbox, array $payload): void
    {
        $updates = [
            'user_id' => null,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];
        if ($outbox->status !== 'processing') {
            $updates = array_merge($updates, [
                'status' => 'pending',
                'last_error' => null,
                'next_attempt_at' => null,
                'processed_at' => null,
            ]);
        }
        DB::table('memory_projection_outbox')->where('id', $outbox->id)->update($updates);
    }

    private function terminalizeOwnerlessOutbox(object $outbox, array $payload, string $reason): void
    {
        $acknowledgedAt = trim((string) ($payload['exact_forget_ack_at'] ?? ''));
        DB::table('memory_projection_outbox')->where('id', $outbox->id)->update([
            'memory_link_id' => null,
            'user_id' => null,
            'dataset' => MemoryErasureIdentity::dataset((string) $outbox->dataset),
            'dedupe_key' => MemoryErasureIdentity::dedupe((string) $outbox->dedupe_key),
            'payload' => json_encode(array_filter([
                'phase' => 'erasure_cleanup_complete',
                'erasure_reason' => $reason,
                'exact_forget_ack_at' => $acknowledgedAt !== '' ? $acknowledgedAt : null,
            ], static fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR),
            'status' => 'done',
            'last_error' => null,
            'next_attempt_at' => null,
            'processed_at' => $outbox->processed_at ?? now(),
            'updated_at' => now(),
        ]);
    }

    private function quarantineOwnerlessLink(int $linkId, string $reason): void
    {
        DB::table('memory_links')->where('id', $linkId)->update([
            'projection_status' => 'legacy_review_required',
            'write_reason' => $reason,
            'updated_at' => now(),
        ]);
    }

    /**
     * Old NULL-ON-DELETE foreign keys may already have detached a shared row
     * from its actor. Only an explicit, now-missing provenance actor is proof
     * that account attribution must be removed. Ambiguous/system-owned shared
     * rows and rows referring to an existing actor remain byte-for-byte intact.
     */
    private function detachPreexistingOwnerlessSharedActors(): void
    {
        DB::table('memory_links')
            ->whereNull('user_id')
            ->whereIn('scope', self::SHARED_SCOPES)
            ->select(['id', 'provenance', 'meta'])
            ->orderBy('id')
            ->chunkById(250, function ($links): void {
                foreach ($links as $link) {
                    $provenance = $this->decodePayload($link->provenance);
                    $formerActorId = $this->positiveInteger($provenance['actor_user_id'] ?? null);
                    if ($formerActorId === null || DB::table('users')->where('id', $formerActorId)->exists()) {
                        continue;
                    }

                    $safeProvenance = [];
                    foreach (['captured_at', 'policy_version'] as $safeKey) {
                        if (is_string($provenance[$safeKey] ?? null)) {
                            $safeProvenance[$safeKey] = $provenance[$safeKey];
                        }
                    }
                    $safeProvenance['account_actor_erased_at'] = now()->toIso8601String();

                    $meta = $this->decodePayload($link->meta);
                    $safeMeta = [];
                    foreach (['source_external_id', 'memory_key'] as $identityKey) {
                        $value = $meta[$identityKey] ?? null;
                        if (is_string($value) && trim($value) !== '') {
                            $safeMeta[$identityKey] = trim($value);
                        }
                    }

                    DB::table('memory_links')->where('id', $link->id)->update([
                        'client_id' => null,
                        'source_ref' => null,
                        'provenance' => json_encode($safeProvenance, JSON_THROW_ON_ERROR),
                        'meta' => $safeMeta === [] ? null : json_encode($safeMeta, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                }
            });
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

    private function formerUserIdFromDataset(string $dataset): ?int
    {
        if (preg_match('/(?:^|:)user:(\d+)(?::|$)/', $dataset, $matches) !== 1) {
            return null;
        }

        $userId = (int) $matches[1];

        return $userId > 0 ? $userId : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }
};
