<?php

namespace App\Services;

use App\Jobs\ProcessMemoryProjection;
use App\Models\MemoryLink;
use App\Models\MemoryProjectionOutbox;
use App\Models\MemoryWriteEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Removes user-owned memory before the account row can disappear.
 *
 * A MemoryLink.user_id identifies the owner only for user-owned scopes. For
 * workspace/global memory it records the actor; ownership stays with the
 * tenant or the curated global namespace, so those rows are detached instead.
 */
final class AccountMemoryErasureService
{
    /** @var list<string> */
    public const USER_OWNED_SCOPES = [
        'device',
        'user',
        'private',
        'project',
        'skill',
        'agent',
        'session',
    ];

    /** @var list<string> */
    public const SHARED_SCOPES = [
        'workspace',
        'global',
    ];

    public function eraseBeforeDelete(User $user): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Account memory erasure must run inside the user deletion transaction.');
        }

        $userId = (int) $user->getKey();
        if ($userId < 1) {
            throw new LogicException('Cannot erase memory for a user without a persisted identity.');
        }

        // Remember takes this same account-row lock before it creates any
        // canonical memory or outbox state. Re-acquiring it here makes this
        // service safe even when invoked directly inside a delete transaction,
        // and proves the caller did not hand us a stale/deleted model.
        $lockedUser = User::query()->whereKey($userId)->lockForUpdate()->first();
        if (! $lockedUser || (int) $lockedUser->getKey() !== $userId) {
            throw new LogicException('Account deletion blocked: the memory owner no longer exists.');
        }
        $user = $lockedUser;

        // Take a non-locking snapshot while the account row prevents new
        // Remember writes. Backfill durable events first, then lock events and
        // only afterwards lock/revalidate links. This matches Forget's global
        // event -> link order and avoids a link/event deadlock.
        $snapshot = MemoryLink::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get();
        foreach ($snapshot as $link) {
            $this->backfillWriteTombstone($link);
        }
        $snapshotIds = $snapshot->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        MemoryWriteEvent::query()
            ->whereIn('memory_link_id', $snapshotIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $links = MemoryLink::query()
            ->whereIn('id', $snapshotIds)
            ->where('user_id', $userId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $this->assertKnownOwnership($user, $links);

        $shared = $links->whereIn('scope', self::SHARED_SCOPES)->values();
        $owned = $links->whereIn('scope', self::USER_OWNED_SCOPES)->values();
        $sharedDatasets = $shared
            ->pluck('dataset')
            ->map(fn (mixed $dataset): string => (string) $dataset)
            ->unique()
            ->values()
            ->all();

        $this->detachSharedActor($shared);
        $this->eraseOwnedLinks($userId, $owned);
        $this->eraseResidualUserOutboxes($userId, $sharedDatasets);
        $this->tombstoneUnlinkedUserEvents($userId);
    }

    /** @param Collection<int,MemoryLink> $links */
    private function assertKnownOwnership(User $user, Collection $links): void
    {
        $known = array_merge(self::USER_OWNED_SCOPES, self::SHARED_SCOPES);
        $unknown = $links
            ->pluck('scope')
            ->map(fn (mixed $scope): string => (string) $scope)
            ->reject(fn (string $scope): bool => in_array($scope, $known, true))
            ->unique()
            ->values();

        if ($unknown->isNotEmpty()) {
            throw new LogicException(
                'Account deletion blocked: memory ownership is undefined for scope(s): '.$unknown->implode(', ').'.'
            );
        }

        $workspaceLinks = $links->where('scope', 'workspace');
        if ($workspaceLinks->isEmpty()) {
            return;
        }

        $tenantId = $user->tenant_id === null ? null : (int) $user->tenant_id;
        $hasInvalidWorkspaceOwner = $tenantId === null
            || $workspaceLinks->contains(
                fn (MemoryLink $link): bool => $link->tenant_id === null || (int) $link->tenant_id !== $tenantId
            );

        if ($hasInvalidWorkspaceOwner) {
            throw new LogicException(
                'Account deletion blocked: workspace memory must have the deleting user tenant as durable owner.'
            );
        }
    }

    /** @param Collection<int,MemoryLink> $shared */
    private function detachSharedActor(Collection $shared): void
    {
        if ($shared->isEmpty()) {
            return;
        }

        $ids = $shared->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        MemoryWriteEvent::query()
            ->whereIn('memory_link_id', $ids)
            ->update(['user_id' => null]);
        MemoryProjectionOutbox::query()
            ->whereIn('memory_link_id', $ids)
            ->update(['user_id' => null]);
        foreach ($shared as $link) {
            // Caller provenance is intentionally open-ended, therefore a
            // denylist cannot prove account erasure. Rebuild it from the small
            // set of non-attributing controller fields and discard all caller
            // metadata fail-closed.
            $oldProvenance = is_array($link->provenance) ? $link->provenance : [];
            $provenance = [];
            foreach (['captured_at', 'policy_version'] as $safeKey) {
                if (is_string($oldProvenance[$safeKey] ?? null)) {
                    $provenance[$safeKey] = $oldProvenance[$safeKey];
                }
            }
            $provenance['account_actor_erased_at'] = now()->toIso8601String();

            // Shared memory must keep only the non-attributing identifiers
            // required to address its logical version family. Every other
            // caller-controlled metadata field is personal and is removed.
            $oldMeta = is_array($link->meta) ? $link->meta : [];
            $meta = [];
            foreach (['source_external_id', 'memory_key'] as $identityKey) {
                $value = $oldMeta[$identityKey] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $meta[$identityKey] = trim($value);
                }
            }

            $link->update([
                'user_id' => null,
                'client_id' => null,
                'source_ref' => null,
                'provenance' => $provenance,
                'meta' => $meta ?: null,
            ]);
        }
    }

    /** @param Collection<int,MemoryLink> $owned */
    private function eraseOwnedLinks(int $userId, Collection $owned): void
    {
        if ($owned->isEmpty()) {
            return;
        }

        $ownedIds = $owned->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $foreignEvent = MemoryWriteEvent::query()
            ->where('user_id', $userId)
            ->whereNotNull('memory_link_id')
            ->whereNotIn('memory_link_id', $ownedIds)
            ->exists();
        if ($foreignEvent) {
            throw new LogicException('Account deletion blocked: a memory write event has inconsistent ownership.');
        }

        $upserts = MemoryProjectionOutbox::query()
            ->whereIn('memory_link_id', $ownedIds)
            ->where('action', 'upsert')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('memory_link_id');
        $deletes = MemoryProjectionOutbox::query()
            ->whereIn('memory_link_id', $ownedIds)
            ->where('action', 'delete')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($deletes as $delete) {
            $payload = is_array($delete->payload) ? $delete->payload : [];
            $payload['erasure_reason'] = 'account_deleted';
            if ($delete->status === 'done') {
                $exactForgetAcknowledged = trim((string) ($payload['exact_forget_ack_at'] ?? '')) !== '';
                $hasProviderIdentity = trim((string) ($payload['cognee_memory_id'] ?? '')) !== '';
                if ($exactForgetAcknowledged || ! $hasProviderIdentity) {
                    $delete->update(['payload' => $payload, 'user_id' => null]);
                    $this->sanitizeTerminalOutbox($delete, 'account_deleted');

                    continue;
                }

                // A prior Delete may have completed only because another SQL
                // row still referenced this Data UUID. Preserve and recheck
                // the exact identity instead of anonymizing before Forget.
                $this->wakeResidualProjection($delete, $payload);

                continue;
            }

            if ($delete->status === 'processing') {
                $delete->update(['user_id' => null, 'payload' => $payload]);

                continue;
            }

            $delete->update([
                'user_id' => null,
                'payload' => $payload,
                'status' => 'queued',
                'last_error' => null,
                'next_attempt_at' => null,
            ]);
            ProcessMemoryProjection::dispatch($delete->id)->afterCommit();
        }

        foreach ($owned as $link) {
            MemoryWriteEvent::query()
                ->where('memory_link_id', $link->id)
                ->orderBy('id')
                ->get()
                ->each(fn (MemoryWriteEvent $event) => $this->tombstoneWriteEvent(
                    $event,
                    $this->erasedDataset((string) $link->dataset),
                ));

            $dataIds = collect([(string) $link->cognee_memory_id]);
            foreach ($upserts->get($link->id, collect()) as $upsert) {
                $payload = is_array($upsert->payload) ? $upsert->payload : [];
                $dataIds->push((string) ($payload['cognee_memory_id'] ?? ''));
                foreach (is_array($payload['recovered_data_ids'] ?? null)
                    ? $payload['recovered_data_ids']
                    : [] as $recoveredDataId) {
                    $dataIds->push((string) $recoveredDataId);
                }
                $this->wakeProjectionRecovery($upsert, $link);
            }

            $dataIds
                ->map(fn (string $dataId): string => trim($dataId))
                ->filter()
                ->unique()
                ->each(fn (string $dataId) => $this->enqueueDelete($link, $dataId));

            $link->delete();
        }

        $ownedDatasets = $owned->pluck('dataset')->unique()->values();
        MemoryProjectionOutbox::query()
            ->whereIn('dataset', $ownedDatasets)
            ->where('action', 'improve')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (MemoryProjectionOutbox $outbox): void {
                $payload = is_array($outbox->payload) ? $outbox->payload : [];
                $payload['account_erasure_reason'] = 'account_deleted';
                if ($outbox->status === 'processing' || $this->hasLiveImproveRun($payload)) {
                    $updates = [
                        'user_id' => null,
                        'payload' => $payload,
                    ];
                    if ($outbox->status !== 'processing') {
                        $updates = array_merge($updates, [
                            'status' => 'queued',
                            'last_error' => null,
                            'next_attempt_at' => null,
                            'processed_at' => null,
                        ]);
                    }
                    $outbox->update($updates);
                    if ($outbox->status !== 'processing') {
                        ProcessMemoryProjection::dispatch($outbox->id)->afterCommit();
                    }

                    return;
                }

                $payload['phase'] = 'account_erased';
                $payload['account_erased_at'] = now()->toIso8601String();
                $outbox->update([
                    'user_id' => null,
                    'payload' => $payload,
                    'status' => 'done',
                    'last_error' => null,
                    'next_attempt_at' => null,
                    'processed_at' => now(),
                ]);
                $this->sanitizeTerminalOutbox($outbox, 'account_deleted');
            });
    }

    private function backfillWriteTombstone(MemoryLink $link): void
    {
        if (! $link->idempotency_key || ! $link->write_fingerprint) {
            return;
        }

        $event = MemoryWriteEvent::query()->firstOrCreate(
            ['idempotency_key' => $link->idempotency_key],
            [
                'write_fingerprint' => $link->write_fingerprint,
                'ledger_identity_version' => 2,
                'memory_link_id' => $link->id,
                'user_id' => $link->user_id,
                'dataset' => $link->dataset,
                'state' => 'committed',
            ],
        );

        if ((int) $event->memory_link_id !== (int) $link->id
            || ! hash_equals((string) $event->write_fingerprint, (string) $link->write_fingerprint)) {
            throw new LogicException('Account deletion blocked: a memory tombstone has inconsistent identity.');
        }
    }

    private function wakeProjectionRecovery(MemoryProjectionOutbox $upsert, MemoryLink $link): void
    {
        $payload = is_array($upsert->payload) ? $upsert->payload : [];
        $payload = $this->preserveResidualAddRecoveryIdentity($upsert, $payload, $link->content_hash);
        $payload['source_erasure_reason'] = 'account_deleted';
        $payload['content_snapshot_erased_at'] ??= now()->toIso8601String();
        unset(
            $payload['content'],
            $payload['content_ciphertext'],
            $payload['content_snapshot_expires_at'],
        );
        $payload['account_erasure_reason'] = 'account_deleted';
        $updates = [
            'user_id' => null,
            'payload' => $payload,
        ];
        $terminalDone = $upsert->status === 'done' && $this->terminalUpsertIsSafe($payload);
        if (! $terminalDone && $upsert->status !== 'processing') {
            $updates = array_merge($updates, [
                'status' => 'queued',
                'last_error' => null,
                'next_attempt_at' => null,
                'processed_at' => null,
            ]);
        }
        $upsert->update($updates);

        if ($terminalDone) {
            $this->sanitizeTerminalOutbox($upsert, 'account_deleted');

            return;
        }
        if ($upsert->status === 'processing') {
            return;
        }

        ProcessMemoryProjection::dispatch($upsert->id)->afterCommit();
    }

    private function enqueueDelete(MemoryLink $link, string $dataId): void
    {
        $dedupe = hash('sha256', implode('|', [
            'delete',
            $link->dataset,
            $link->id,
            $dataId,
        ]));
        $outbox = MemoryProjectionOutbox::query()->firstOrCreate(
            ['dedupe_key' => $dedupe],
            [
                'memory_link_id' => $link->id,
                'user_id' => null,
                'action' => 'delete',
                'dataset' => $link->dataset,
                'payload' => [
                    'cognee_memory_id' => $dataId,
                    'content_hash' => $link->content_hash,
                    'erasure_reason' => 'account_deleted',
                ],
                'status' => 'pending',
            ],
        );

        if (in_array($outbox->status, ['done', 'processing', 'queued'], true)) {
            return;
        }

        $outbox->update([
            'user_id' => null,
            'status' => 'queued',
            'last_error' => null,
            'next_attempt_at' => null,
        ]);
        ProcessMemoryProjection::dispatch($outbox->id)->afterCommit();
    }

    /**
     * Classify every account-attributed row that no longer has a canonical
     * link (for example after an ordinary Forget) before the user FK is
     * cleared. The erasure marker is durable and content snapshots disappear
     * while the exact provider identity remains available for compensation.
     *
     * @param  list<string>  $sharedDatasets
     */
    private function eraseResidualUserOutboxes(int $userId, array $sharedDatasets): void
    {
        $rows = MemoryProjectionOutbox::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $outbox) {
            $payload = is_array($outbox->payload) ? $outbox->payload : [];
            unset(
                $payload['content'],
                $payload['content_ciphertext'],
                $payload['content_snapshot_expires_at'],
            );

            // Improve belongs to a dataset, not to an individual MemoryLink.
            // A shared workspace/global run therefore survives deletion of its
            // initiating actor byte-for-byte; only that actor FK is detached.
            if ($outbox->action === 'improve'
                && in_array((string) $outbox->dataset, $sharedDatasets, true)) {
                $outbox->update(['user_id' => null]);

                continue;
            }

            if ($outbox->action === 'delete') {
                $payload['erasure_reason'] = 'account_deleted';
                $exactForgetAcknowledged = trim((string) ($payload['exact_forget_ack_at'] ?? '')) !== '';
                $hasProviderIdentity = trim((string) ($payload['cognee_memory_id'] ?? '')) !== '';
                if ($outbox->status === 'done' && ($exactForgetAcknowledged || ! $hasProviderIdentity)) {
                    $outbox->update(['payload' => $payload, 'user_id' => null]);
                    $this->sanitizeTerminalOutbox($outbox, 'account_deleted');

                    continue;
                }

                $this->wakeResidualProjection($outbox, $payload);

                continue;
            }

            if ($outbox->action === 'upsert') {
                $payload = $this->preserveResidualAddRecoveryIdentity($outbox, $payload);
                $payload['account_erasure_reason'] = 'account_deleted';
                $this->wakeResidualProjection($outbox, $payload);

                continue;
            }

            if ($outbox->action === 'improve') {
                $payload['account_erasure_reason'] = 'account_deleted';
                if ($outbox->status === 'done' && ! $this->hasLiveImproveRun($payload)) {
                    $outbox->update(['payload' => $payload, 'user_id' => null]);
                    $this->sanitizeTerminalOutbox($outbox, 'account_deleted');

                    continue;
                }

                $this->wakeResidualProjection($outbox, $payload);

                continue;
            }

            throw new LogicException(
                'Account deletion blocked: unknown memory projection action '.$outbox->action.'.'
            );
        }
    }

    /** @param array<string,mixed> $payload */
    private function hasLiveImproveRun(array $payload): bool
    {
        return in_array((string) ($payload['phase'] ?? ''), [
            'improve_launching',
            'improve_polling',
            'launch_ack_pending_terminal',
        ], true) || trim((string) ($payload['launch_ack_pending_key'] ?? '')) !== '';
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

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function preserveResidualAddRecoveryIdentity(
        MemoryProjectionOutbox $outbox,
        array $payload,
        mixed $canonicalContentHash = null,
    ): array {
        $resolution = MemoryProviderIdentity::resolve($payload, $outbox->memory_link_id);
        if ($resolution['error'] === 'invalid_stored' || $resolution['error'] === 'invalid_current') {
            throw new LogicException(
                "Account deletion blocked: residual Memory Add {$outbox->id} has an invalid provider_memory_link_id."
            );
        }
        if ($resolution['error'] === 'conflict') {
            throw new LogicException(
                "Account deletion blocked: residual Memory Add {$outbox->id} has conflicting provider filename identities."
            );
        }

        $providerId = $resolution['identity'];
        if ($providerId === null && (string) ($payload['phase'] ?? '') === 'adding') {
            throw new LogicException(
                "Account deletion blocked: residual Memory Add {$outbox->id} is in live adding state without "
                .'a verified provider_memory_link_id. Restore the original positive MemoryLink ID from trusted '
                .'backup or audit evidence, or complete an audited Cognee cleanup before retrying.'
            );
        }
        if ($providerId !== null) {
            $payload['provider_memory_link_id'] = $providerId;
        }
        $contentIdentity = MemoryProviderIdentity::resolveContentHash($payload, $canonicalContentHash);
        if ($contentIdentity['error'] !== null) {
            throw new LogicException(
                "Account deletion blocked: residual Memory Add {$outbox->id} has an invalid or conflicting content_hash."
            );
        }
        if ($contentIdentity['content_hash'] === null && (string) ($payload['phase'] ?? '') === 'adding') {
            throw new LogicException(
                "Account deletion blocked: residual Memory Add {$outbox->id} is in live adding state without "
                .'a verified content_hash. Restore it from trusted backup or audit evidence, or complete an '
                .'audited Cognee cleanup before retrying.'
            );
        }
        if ($contentIdentity['content_hash'] !== null) {
            $payload['content_hash'] = $contentIdentity['content_hash'];
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function wakeResidualProjection(MemoryProjectionOutbox $outbox, array $payload): void
    {
        $updates = [
            'user_id' => null,
            'payload' => $payload,
        ];
        if ($outbox->status !== 'processing') {
            $updates = array_merge($updates, [
                'status' => 'queued',
                'last_error' => null,
                'next_attempt_at' => null,
                'processed_at' => null,
            ]);
        }
        $outbox->update($updates);

        if ($outbox->status !== 'processing') {
            ProcessMemoryProjection::dispatch($outbox->id)->afterCommit();
        }
    }

    private function tombstoneUnlinkedUserEvents(int $userId): void
    {
        MemoryWriteEvent::query()
            ->where('user_id', $userId)
            ->whereNull('memory_link_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (MemoryWriteEvent $event): void {
                $this->tombstoneWriteEvent(
                    $event,
                    $this->erasedDataset((string) $event->dataset),
                );
            });
    }

    /**
     * Move a deleted account's durable write tombstone into a second keyed
     * domain. This preserves replay protection without retaining an identity
     * that can be correlated with the former live account ledger.
     */
    private function tombstoneWriteEvent(MemoryWriteEvent $event, string $erasedDataset): void
    {
        $version = (int) $event->getAttribute('ledger_identity_version');
        $idempotencyKey = (string) $event->idempotency_key;
        $writeFingerprint = (string) $event->write_fingerprint;

        if (! in_array($version, [1, 2, 3], true)) {
            throw new LogicException('Account deletion blocked: unsupported memory ledger identity version.');
        }

        if ($version === 1) {
            $idempotencyKey = MemoryLedgerIdentity::idempotency($idempotencyKey);
            $writeFingerprint = MemoryLedgerIdentity::fingerprint($writeFingerprint);
            $version = 2;
        }

        if ($version === 2) {
            $idempotencyKey = MemoryLedgerIdentity::erasedIdempotency($idempotencyKey);
            $writeFingerprint = MemoryLedgerIdentity::erasedFingerprint($writeFingerprint);
            $version = 3;
        }

        $event->update([
            'idempotency_key' => $idempotencyKey,
            'write_fingerprint' => $writeFingerprint,
            'ledger_identity_version' => $version,
            'memory_link_id' => null,
            'user_id' => null,
            'dataset' => $erasedDataset,
            'state' => 'forgotten',
            'forgotten_at' => $event->forgotten_at ?? now(),
        ]);
    }

    private function erasedDataset(string $dataset): string
    {
        return MemoryErasureIdentity::dataset($dataset);
    }

    private function sanitizeTerminalOutbox(MemoryProjectionOutbox $outbox, string $reason): void
    {
        $outbox->update([
            'memory_link_id' => null,
            'user_id' => null,
            'dataset' => $this->erasedDataset((string) $outbox->dataset),
            'dedupe_key' => MemoryErasureIdentity::outboxDedupe(
                (string) $outbox->dedupe_key,
                (int) $outbox->id,
            ),
            'payload' => [
                'phase' => 'erasure_cleanup_complete',
                'erasure_reason' => $reason,
            ],
        ]);
    }
}
