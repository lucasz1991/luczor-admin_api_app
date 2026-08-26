<?php

namespace App\Services;

use App\Jobs\ProcessMemoryProjection;
use App\Models\MemoryLink;
use App\Models\MemoryProjectionOutbox;
use App\Services\Cognee\CogneeClient;
use App\Services\Cognee\CogneeRequestException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Rebuildable, idempotent projection of canonical SQL memories into Cognee. */
class MemoryProjectionService
{
    private const POLL_INTERVAL_SECONDS = 15;

    private const MAX_BACKGROUND_SECONDS = 1800;

    private const PROCESSING_LEASE_SECONDS = 90;

    public function __construct(private CogneeClient $cognee) {}

    public function process(int $outboxId): void
    {
        $outbox = DB::transaction(function () use ($outboxId) {
            $row = MemoryProjectionOutbox::query()->whereKey($outboxId)->lockForUpdate()->first();
            if (! $row
                || $row->status === 'done'
                || ($row->status === 'processing'
                    && $row->updated_at->gt(now()->subSeconds(self::PROCESSING_LEASE_SECONDS)))
                || ($row->next_attempt_at && $row->next_attempt_at->isFuture())) {
                return null;
            }

            $row->update([
                'status' => 'processing',
                'last_error' => null,
            ]);

            return $row->fresh();
        });
        if (! $outbox) {
            return;
        }

        try {
            if (! $this->cognee->enabled()) {
                throw new RuntimeException('Cognee is not configured.');
            }
            $payloadBeforeAck = $outbox->payload ?? [];
            $hadPendingAck = trim((string) ($payloadBeforeAck['launch_ack_pending_key'] ?? '')) !== '';
            $acknowledged = $this->acknowledgePendingLaunch($outbox);
            $outbox->refresh();
            $phase = (string) (($outbox->payload ?? [])['phase'] ?? 'new');
            if ($phase === 'launch_ack_pending_terminal') {
                if (! $acknowledged) {
                    $this->defer($outbox, self::POLL_INTERVAL_SECONDS);

                    return;
                }

                $completed = true;
            } elseif ($hadPendingAck
                && ! $acknowledged
                && ! in_array($phase, ['polling', 'improve_polling'], true)) {
                $this->defer($outbox, self::POLL_INTERVAL_SECONDS);

                return;
            } else {
                $completed = match ($outbox->action) {
                    'upsert' => $this->upsert($outbox),
                    'delete' => $this->delete($outbox),
                    'improve' => $this->improve($outbox),
                    default => throw new RuntimeException('Unknown memory projection action.'),
                };
            }

            if (! $completed) {
                $this->acknowledgePendingLaunch($outbox);

                return;
            }
            if (! $this->acknowledgePendingLaunch($outbox)) {
                $this->markTerminalAckPending($outbox);
                $this->defer($outbox, self::POLL_INTERVAL_SECONDS);

                return;
            }

            $this->complete($outbox);
        } catch (Throwable $error) {
            $outbox->refresh();
            $failureCount = min(65535, (int) $outbox->attempts + 1);
            $outbox->update([
                'status' => 'failed',
                'attempts' => $failureCount,
                'last_error' => mb_substr($error->getMessage(), 0, 4000),
                'next_attempt_at' => now()->addSeconds(min(3600, 10 * (2 ** min(8, $failureCount - 1)))),
            ]);
            if ($outbox->action === 'upsert' && $outbox->memory_link_id) {
                MemoryLink::query()->whereKey($outbox->memory_link_id)->update(['projection_status' => 'failed']);
            }

            throw $error;
        }
    }

    private function upsert(MemoryProjectionOutbox $outbox): bool
    {
        $payload = $outbox->payload ?? [];
        $phase = (string) ($payload['phase'] ?? 'new');
        $link = MemoryLink::query()->find($outbox->memory_link_id);

        // Once a cognify launch may have reached Cognee, deletion must wait for
        // a terminal state. Deleting first can let the still-running build
        // recreate an orphan after our compensating forget has returned.
        if (in_array($phase, [
            'restart_source_ineligible',
            'cognify_source_ineligible',
            'add_absent_recovered',
            'add_skipped_source_ineligible',
        ], true)) {
            $this->replayTerminalUpsertEffects($outbox, $link, $payload);

            return true;
        }
        if ($phase === 'cognify_launching') {
            return $this->recoverCognifyLaunch($outbox, $link, $payload);
        }
        if ($phase === 'polling') {
            return $this->pollCognify($outbox, $link, $payload);
        }

        $dataId = trim((string) ($payload['cognee_memory_id'] ?? ''));
        $eligible = $this->isProjectionEligible($link);
        if ($phase === 'new') {
            $retained = $this->adoptRetainedProjection($outbox);
            if ($retained['state'] === 'deferred') {
                return false;
            }
            $link = $retained['link'];
            $eligible = $this->isProjectionEligible($link);
            if ($retained['state'] === 'ineligible') {
                $this->replayTerminalUpsertEffects($outbox, $link, $retained['payload']);

                return true;
            }
            if ($retained['state'] === 'adopted') {
                return true;
            }
        }
        if ($phase === 'ingested') {
            // Exact Add recovery may find multiple Data rows left by an older
            // race. Their complete identity list is durable in the source
            // payload, so every retry can recreate a missing compensation.
            if (! $this->isUuid($dataId)) {
                throw new RuntimeException('Durable ingested state is missing its Data UUID.');
            }
            $link?->update([
                'cognee_memory_id' => $dataId,
                'projection_status' => $eligible ? 'processing' : 'delete_pending',
            ]);
            $this->queueRecoveredDataCompensation($outbox, $link, $payload, ! $eligible);
        }
        if (in_array($phase, ['ingested', 'cognify_rejected', 'cognify_failed'], true)
            && ! $eligible) {
            if ($dataId !== '' && $phase !== 'ingested') {
                $link?->update(['cognee_memory_id' => $dataId, 'projection_status' => 'delete_pending']);
                $this->queueCompensatingDelete($outbox, $dataId, $link);
            }

            return true;
        }
        if ($phase === 'add_rejected') {
            $link?->update(['projection_status' => 'failed']);

            return true;
        }
        if ($phase === 'new' && ! $this->isProjectionEligible($link)) {
            $link?->update(['projection_status' => $this->inactiveProjectionStatus($link)]);

            return true;
        }

        if (! $this->ownsDatasetTurn($outbox)) {
            $this->defer($outbox, 5);

            return false;
        }

        // A deleted source can still be retried from the durable `adding`
        // snapshot so Cognee's deterministic Data UUID can be recovered and
        // compensated. Keep this lookup explicitly nullable.
        $linkContentHash = $link === null ? null : $link->content_hash;

        return $this->withContentLock(
            $outbox,
            $outbox->dataset,
            trim((string) ($payload['content_hash'] ?? $linkContentHash ?? '')) ?: (string) $outbox->id,
            function () use ($outbox, $phase, $payload) {
                // A Delete can take dataset priority while this worker waits
                // for the per-content lock. Revalidate inside the lock before
                // any provider write so the stale Upsert cannot overtake it.
                if (! $this->ownsDatasetTurn($outbox)) {
                    $this->defer($outbox, 5);

                    return false;
                }

                $link = MemoryLink::query()->find($outbox->memory_link_id);
                if ($phase === 'new') {
                    if (! $this->isProjectionEligible($link)) {
                        $link?->update(['projection_status' => $this->inactiveProjectionStatus($link)]);

                        return true;
                    }

                    // Persist only an encrypted, time-bounded recovery copy.
                    // It exists solely to recover a lost /add response after a
                    // concurrent Forget; ordinary retries reload canonical SQL.
                    $payload = array_merge($payload, [
                        // `adding_prepared` proves no provider call has begun.
                        // `performAdd` durably advances to `adding` immediately
                        // before the HTTP request; only that phase needs exact
                        // recovery after a crash or lost response.
                        'phase' => 'adding_prepared',
                        'content_ciphertext' => Crypt::encryptString($link->summary),
                        'content_snapshot_expires_at' => now()
                            ->addSeconds($this->contentSnapshotTtlSeconds())
                            ->toIso8601String(),
                        'content_hash' => $link->content_hash,
                        'add_started_at' => now()->toIso8601String(),
                    ]);
                    unset($payload['content']);
                    $this->transitionPayload($outbox, $payload);
                }

                if (in_array(($payload['phase'] ?? null), ['adding_prepared', 'adding'], true)) {
                    return $this->performAdd($outbox, $link, $payload);
                }

                return $this->launchCognify($outbox);
            }
        );
    }

    /** @param array<string,mixed> $payload */
    private function performAdd(MemoryProjectionOutbox $outbox, ?MemoryLink $link, array $payload): bool
    {
        $phase = (string) ($payload['phase'] ?? 'adding_prepared');
        $contentHash = trim((string) ($payload['content_hash'] ?? ''));
        if (! preg_match('/^[a-f0-9]{64}$/', $contentHash)) {
            throw new RuntimeException('Cognee add recovery content identity is invalid.');
        }

        $ciphertext = trim((string) ($payload['content_ciphertext'] ?? ''));
        $expiresAt = trim((string) ($payload['content_snapshot_expires_at'] ?? ''));
        if ($expiresAt !== '' && Carbon::parse($expiresAt)->isPast()) {
            $payload['content_snapshot_erased_at'] = now()->toIso8601String();
            $this->scrubContentSnapshot($payload);
            $this->transitionPayload($outbox, $payload);
            $ciphertext = '';
            $expiresAt = '';
        }

        // A persisted `adding` phase means the previous process may have sent
        // the request. The wrapper lookup waits for every foreground Add and
        // then queries the exact filename, making both positive and negative
        // recovery results safe without a blind duplicate upload.
        if ($phase === 'adding') {
            $providerMemoryLinkId = $this->providerMemoryLinkId($outbox, $payload);
            if ($providerMemoryLinkId === null) {
                throw new RuntimeException('Cognee Add recovery lost its deterministic filename identity.');
            }
            $recovered = $this->cognee->findData(
                $outbox->dataset,
                $providerMemoryLinkId,
                $contentHash,
                true,
            );
            if ($recovered['data_ids'] !== []) {
                return $this->completeRecoveredAdd(
                    $outbox,
                    $link,
                    $payload,
                    (string) $recovered['dataset_id'],
                    $recovered['data_ids'],
                );
            }
        } elseif ($phase !== 'adding_prepared') {
            throw new RuntimeException('Cognee Add state is invalid.');
        }

        // This is the linearization point for provider egress. Lock the fresh
        // canonical row and the dataset turn in one transaction, then persist
        // `adding` before any HTTP call. A Forget that committed first makes
        // the source ineligible and therefore prevents the Add entirely.
        $claim = $this->claimAddEgress($outbox, $phase === 'adding');
        if ($claim['state'] === 'deferred') {
            return false;
        }
        $link = $claim['link'];
        $payload = $claim['payload'];
        if ($claim['state'] === 'ineligible') {
            $this->replayTerminalUpsertEffects($outbox, $link, $payload);

            return true;
        }

        if (! $link) {
            throw new RuntimeException('Cognee Add egress claim lost its canonical source.');
        }

        $ciphertext = trim((string) ($payload['content_ciphertext'] ?? ''));
        $expiresAt = trim((string) ($payload['content_snapshot_expires_at'] ?? ''));

        if ($ciphertext === '' || $expiresAt === '') {
            $ciphertext = Crypt::encryptString($link->summary);
            $payload['content_ciphertext'] = $ciphertext;
            $payload['content_snapshot_expires_at'] = now()
                ->addSeconds($this->contentSnapshotTtlSeconds())
                ->toIso8601String();
        }
        try {
            $content = Crypt::decryptString($ciphertext);
        } catch (Throwable) {
            // SQL remains canonical. Replace a corrupt/rotated recovery
            // envelope instead of weakening the projection identity.
            $payload['content_snapshot_invalid_at'] = now()->toIso8601String();
            $content = $link->summary;
            $payload['content_ciphertext'] = Crypt::encryptString($content);
            $payload['content_snapshot_expires_at'] = now()
                ->addSeconds($this->contentSnapshotTtlSeconds())
                ->toIso8601String();
        }
        if ($content === '') {
            throw new RuntimeException('Cognee add recovery content is empty.');
        }

        $this->transitionPayload($outbox, $payload);

        try {
            $response = $this->cognee->add($outbox->dataset, $content, [
                'memory_link_id' => $outbox->memory_link_id,
                'content_hash' => $contentHash,
            ], true);
        } catch (CogneeRequestException $error) {
            if ($error->isPermanentAddRejection()) {
                $payload['phase'] = 'add_rejected';
                $payload['last_add_rejection_status'] = $error->statusCode();
                $payload['last_add_rejected_at'] = now()->toIso8601String();
                $this->scrubContentSnapshot($payload);
            } elseif ($error->isDeterministicLaunchRejection()) {
                // Authentication and routing failures prove the Add endpoint
                // did not accept content, but may become repairable later.
                $payload['phase'] = 'new';
                $payload['last_add_retryable_status'] = $error->statusCode();
                $payload['last_add_retryable_at'] = now()->toIso8601String();
                $this->scrubContentSnapshot($payload);
            } else {
                $payload['phase'] = 'adding';
                $payload['last_add_ambiguous_status'] = $error->statusCode();
                $payload['last_add_ambiguous_at'] = now()->toIso8601String();
            }
            $this->transitionPayload($outbox, $payload);
            $link->update(['projection_status' => 'failed']);

            throw $error;
        } catch (Throwable $error) {
            $payload['phase'] = 'adding';
            $payload['last_add_ambiguous_at'] = now()->toIso8601String();
            $this->transitionPayload($outbox, $payload);
            $link->update(['projection_status' => 'failed']);

            throw $error;
        }
        $dataId = $this->cognee->dataId($response);
        $datasetId = $this->cognee->datasetId($response);
        if (! $dataId || ! $datasetId) {
            $payload['phase'] = 'adding';
            $payload['last_add_ambiguous_at'] = now()->toIso8601String();
            $this->transitionPayload($outbox, $payload);

            throw new RuntimeException('Cognee add returned no Data UUID or dataset UUID.');
        }

        return $this->completeRecoveredAdd($outbox, $link, $payload, $datasetId, [$dataId]);
    }

    /** @param array<string,mixed> $payload @param list<string> $dataIds */
    private function completeRecoveredAdd(
        MemoryProjectionOutbox $outbox,
        ?MemoryLink $link,
        array $payload,
        string $datasetId,
        array $dataIds,
    ): bool {
        $dataIds = array_values(array_unique(array_map(
            fn (mixed $dataId): string => trim((string) $dataId),
            $dataIds,
        )));
        $dataId = $dataIds[0] ?? '';
        if (! $this->isUuid($datasetId)
            || $dataId === ''
            || collect($dataIds)->contains(fn (string $candidate): bool => ! $this->isUuid($candidate))) {
            throw new RuntimeException('Cognee add recovery returned an invalid Data identity.');
        }

        $payload = array_merge($payload, [
            'phase' => 'ingested',
            'cognee_memory_id' => $dataId,
            'cognee_dataset_id' => $datasetId,
            // Keep every recovered UUID until the source outbox itself is
            // durable terminal state. If the process dies before creating a
            // duplicate Delete, retry can reconstruct that exact Delete.
            'recovered_data_ids' => $dataIds,
            'ingested_at' => now()->toIso8601String(),
        ]);
        $this->scrubContentSnapshot($payload);
        if ($instanceId = $this->cognee->observedInstanceId()) {
            $payload['cognee_instance_id'] = $instanceId;
        }
        $this->transitionPayload($outbox, $payload);

        $link = MemoryLink::query()->find($outbox->memory_link_id);
        if (! $this->isProjectionEligible($link)) {
            $link?->update(['cognee_memory_id' => $dataId, 'projection_status' => 'delete_pending']);
            $this->queueRecoveredDataCompensation($outbox, $link, $payload, true);

            return true;
        }

        $link->update(['cognee_memory_id' => $dataId, 'projection_status' => 'processing']);
        $this->queueRecoveredDataCompensation($outbox, $link, $payload, false);

        return $this->launchCognify($outbox);
    }

    private function launchCognify(MemoryProjectionOutbox $outbox): bool
    {
        $claim = $this->claimCognifyEgress($outbox);
        if ($claim['state'] === 'deferred') {
            return false;
        }
        $link = $claim['link'];
        $payload = $claim['payload'];
        if ($claim['state'] === 'ineligible') {
            $this->replayTerminalUpsertEffects($outbox, $link, $payload);

            return true;
        }

        $launchKey = (string) $payload['launch_key'];
        try {
            $instanceId = $this->cognee->probeRuntime(true);
            if (! $instanceId) {
                throw new RuntimeException('Cognee runtime guard was not observed; refusing an unguarded cognify launch.');
            }
        } catch (Throwable $error) {
            // No Cognify POST has happened yet. Release only this exact launch
            // claim so a concurrent Forget is not blocked behind a phantom
            // background task. Ambiguous post-call states are never released.
            $released = $this->releaseUnstartedCognifyClaim($outbox, $launchKey);
            if ($released['state'] === 'ineligible') {
                $this->replayTerminalUpsertEffects(
                    $outbox,
                    $released['link'],
                    $released['payload'],
                );

                return true;
            }

            throw $error;
        }
        $payload['cognee_instance_id'] = $instanceId;
        $payload['launch_http_attempted_at'] = now()->toIso8601String();
        $this->transitionPayload($outbox, $payload);
        try {
            $response = $this->cognee->cognifyOnce($outbox->dataset, $launchKey, true);
        } catch (CogneeRequestException $error) {
            if ($error->isDeterministicLaunchRejection()) {
                $this->markLaunchRejected($outbox, $payload, 'cognify_rejected', $error);
            }

            throw $error;
        }
        $launchInstanceId = $this->requireGuardedLaunchAcceptance('cognify', $instanceId);
        $runId = $this->cognee->cognifyRunId(
            $response,
            (string) $payload['cognee_dataset_id'],
        );
        if (! $runId) {
            throw new RuntimeException('Cognee cognify returned no pipeline run UUID.');
        }

        $payload['phase'] = 'polling';
        $payload['pipeline_run_id'] = $runId;
        $payload['cognify_accepted_at'] = now()->toIso8601String();
        $payload['cognee_instance_id'] = $launchInstanceId;
        $payload['launch_ack_pending_key'] = $launchKey;
        $this->transitionPayload($outbox, $payload);
        $link?->update(['projection_status' => 'processing']);
        $this->defer($outbox, self::POLL_INTERVAL_SECONDS);

        return false;
    }

    private function delete(MemoryProjectionOutbox $outbox): bool
    {
        if (! $this->ownsDatasetTurn($outbox)) {
            $this->defer($outbox, 5);

            return false;
        }

        $payload = $outbox->payload ?? [];
        $forgetAcknowledged = trim((string) ($payload['exact_forget_ack_at'] ?? '')) !== '';
        $cogneeId = trim((string) ($payload['cognee_memory_id'] ?? ''));
        if ($cogneeId === '') {
            return true;
        }

        $contentHash = trim((string) ($payload['content_hash'] ?? ''));
        if ($contentHash === '') {
            $contentHash = (string) MemoryLink::query()
                ->where('dataset', $outbox->dataset)
                ->where('cognee_memory_id', $cogneeId)
                ->value('content_hash');
        }

        return $this->withContentLock($outbox, $outbox->dataset, $contentHash ?: $cogneeId, function () use ($outbox, $cogneeId, $contentHash, $forgetAcknowledged): bool {
            // Ownership can change while this worker waits for the content
            // lock. In particular, a retryable Upsert may persist a live
            // Cognify launch before releasing the lock. Revalidate here so a
            // Delete never races that background run and leaves an orphan.
            if (! $this->ownsDatasetTurn($outbox)) {
                $this->defer($outbox, 5);

                return false;
            }

            if ($forgetAcknowledged) {
                // The provider acknowledgement is durable before terminal
                // completion. A crash can therefore leave SQL links pointing
                // at the already-deleted Cognee object. Replay the local side
                // effect under the same content lock, but never repeat Forget.
                $this->finalizeForgottenLinks($outbox, $cogneeId);

                return true;
            }

            $hasActiveReference = DB::transaction(function () use ($outbox, $cogneeId, $contentHash) {
                $links = MemoryLink::query()
                    ->where('dataset', $outbox->dataset)
                    ->where(function ($references) use ($cogneeId, $contentHash) {
                        $references->where('cognee_memory_id', $cogneeId);
                        if ($contentHash !== '') {
                            // A replacement upsert may already own the same
                            // content while its Data UUID still lives only in
                            // the outbox payload. Keep the shared Cognee object
                            // until that active SQL reference has finalized.
                            $references->orWhere('content_hash', $contentHash);
                        }
                    })
                    ->lockForUpdate()
                    ->get();
                $now = now();
                $hasActive = false;

                foreach ($links as $link) {
                    if (MemoryProjectionPolicy::isEligible($link, $now)) {
                        if (hash_equals($cogneeId, (string) $link->cognee_memory_id)) {
                            $hasActive = true;
                        } elseif ($contentHash !== ''
                            && ! $link->cognee_memory_id
                            && hash_equals($contentHash, (string) $link->content_hash)) {
                            // Transfer the retained shared Data UUID to a
                            // durable SQL owner. If this replacement is deleted
                            // before its own Upsert runs, Forget can still find
                            // and erase the exact Cognee object.
                            $link->update(['cognee_memory_id' => $cogneeId]);
                            $hasActive = true;
                        }

                        continue;
                    }

                    if (hash_equals($cogneeId, (string) $link->cognee_memory_id)) {
                        $link->update([
                            'cognee_memory_id' => null,
                            'projection_status' => $this->finalProjectionStatus($outbox, $link, $now),
                        ]);
                    }
                }

                return $hasActive;
            });

            if ($hasActiveReference) {
                return true;
            }

            $forgetResponse = $this->cognee->forget($outbox->dataset, $cogneeId, true);
            if (! $this->cognee->forgetSucceeded($forgetResponse, $cogneeId)) {
                throw new RuntimeException('Cognee did not acknowledge the exact Data deletion.');
            }
            $payload = $outbox->fresh()->payload ?? [];
            $payload['exact_forget_ack_at'] = now()->toIso8601String();
            $this->transitionPayload($outbox, $payload);
            $this->finalizeForgottenLinks($outbox, $cogneeId);

            return true;
        });
    }

    private function finalizeForgottenLinks(MemoryProjectionOutbox $outbox, string $cogneeId): void
    {
        $links = MemoryLink::query()
            ->where('dataset', $outbox->dataset)
            ->where('cognee_memory_id', $cogneeId)
            ->get();
        foreach ($links as $link) {
            $link->update([
                'cognee_memory_id' => null,
                'projection_status' => $this->finalProjectionStatus($outbox, $link),
            ]);
        }
    }

    private function complete(MemoryProjectionOutbox $outbox): void
    {
        DB::transaction(function () use ($outbox): void {
            $current = MemoryProjectionOutbox::query()->whereKey($outbox->id)->lockForUpdate()->firstOrFail();
            $payload = $current->payload ?? [];
            $this->scrubContentSnapshot($payload);
            $rawDataset = (string) $current->dataset;
            $exactForgetAck = $current->action === 'delete'
                && trim((string) ($payload['exact_forget_ack_at'] ?? '')) !== '';
            $hasDeleteIdentity = $current->action === 'delete'
                && trim((string) ($payload['cognee_memory_id'] ?? '')) !== '';

            $current->update([
                'payload' => $payload ?: null,
                'status' => 'done',
                'processed_at' => now(),
                'next_attempt_at' => null,
            ]);

            // Once a terminal row has materialized every required follow-up
            // event, its raw account dataset/link attribution has no recovery
            // purpose. Exact Forget acknowledgement additionally lets us scrub
            // older terminal rows from the same erasure group (including rows
            // that were already done when the account was deleted).
            if ($this->isAccountErasurePayload($payload)
                && (! $hasDeleteIdentity || $exactForgetAck)) {
                $this->sanitizeTerminalErasureRow($current, $payload);
            }
            if (! $exactForgetAck) {
                return;
            }

            MemoryProjectionOutbox::query()
                ->where('dataset', $rawDataset)
                ->where('status', 'done')
                ->whereKeyNot($current->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (MemoryProjectionOutbox $row): void {
                    $rowPayload = $row->payload ?? [];
                    if ($this->isAccountErasurePayload($rowPayload)) {
                        $this->sanitizeTerminalErasureRow($row, $rowPayload);
                    }
                });
        });
    }

    /** @param array<string,mixed> $payload */
    private function isAccountErasurePayload(array $payload): bool
    {
        return in_array(
            (string) ($payload['erasure_reason'] ?? $payload['account_erasure_reason'] ?? ''),
            ['account_deleted', 'legacy_ownerless_user_scope', 'legacy_ownerless_user_outbox'],
            true,
        ) || ($payload['phase'] ?? null) === 'account_erased';
    }

    /** @param array<string,mixed> $payload */
    private function sanitizeTerminalErasureRow(MemoryProjectionOutbox $row, array $payload): void
    {
        $reason = (string) ($payload['erasure_reason'] ?? $payload['account_erasure_reason'] ?? 'account_deleted');
        $acknowledgedAt = trim((string) ($payload['exact_forget_ack_at'] ?? ''));

        $row->update([
            'memory_link_id' => null,
            'user_id' => null,
            'dataset' => MemoryErasureIdentity::dataset((string) $row->dataset),
            'dedupe_key' => MemoryErasureIdentity::dedupe((string) $row->dedupe_key),
            'payload' => array_filter([
                'phase' => 'erasure_cleanup_complete',
                'erasure_reason' => $reason,
                'exact_forget_ack_at' => $acknowledgedAt !== '' ? $acknowledgedAt : null,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function improve(MemoryProjectionOutbox $outbox): bool
    {
        $payload = $outbox->payload ?? [];
        $phase = (string) ($payload['phase'] ?? 'new');
        if ($this->isAccountErasurePayload($payload)
            && ! in_array($phase, ['improve_launching', 'improve_polling'], true)) {
            return true;
        }
        if ($phase === 'improve_disabled') {
            return true;
        }
        if ($phase === 'new' && ! config('luczor.cognee.improve_enabled', false)) {
            $payload['phase'] = 'improve_disabled';
            $payload['disabled_at'] = now()->toIso8601String();
            $this->transitionPayload($outbox, $payload);

            return true;
        }
        if ($phase === 'improve_polling') {
            return $this->pollImprove($outbox, $payload);
        }
        $freshlyClaimed = false;
        if ($phase === 'new') {
            if (! $this->claimImproveLaunchIntent($outbox)) {
                $this->defer($outbox, 5);

                return false;
            }

            $outbox->refresh();
            $payload = $outbox->payload ?? [];
            $phase = (string) ($payload['phase'] ?? 'new');
            $freshlyClaimed = true;
        } elseif (! $this->ownsDatasetTurn($outbox)) {
            $this->defer($outbox, 5);

            return false;
        }
        if ($phase !== 'improve_launching') {
            throw new RuntimeException('Cognee improve launch state is invalid.');
        }

        $launchKey = trim((string) ($payload['launch_key'] ?? ''));
        if (! preg_match('/^[a-f0-9]{64}$/', $launchKey)) {
            throw new RuntimeException('Cognee improve launch intent has no valid idempotency key.');
        }

        // `improve_launching` persists the probe for this exact generation
        // before its POST. A launch-instance field belongs to an older polling
        // generation and must never decide whether the current task is alive.
        $previousInstanceId = trim((string) ($payload['cognee_probe_instance_id'] ?? ''));
        if ($this->isAccountErasurePayload($payload) && $previousInstanceId === '') {
            // The probe identity is committed before the guarded POST. Without
            // one, this durable launch intent is provably unstarted and may
            // yield immediately to the account's privacy Delete.
            $this->abandonErasedImproveLaunch($outbox, $payload, 'unstarted');

            return true;
        }

        try {
            $probedInstanceId = $this->cognee->probeRuntime(true);
            if (! $probedInstanceId) {
                throw new RuntimeException('Cognee runtime guard was not observed; refusing an unguarded improve launch.');
            }
        } catch (Throwable $error) {
            if ($freshlyClaimed) {
                // This process created the intent and has not called Improve
                // yet, so it can safely yield to a waiting privacy Delete. An
                // older durable `improve_launching` retry remains protected:
                // its earlier POST may already have reached Cognee.
                $this->releaseUnstartedImproveLaunch($outbox, $launchKey);
            }

            throw $error;
        }
        $payload['cognee_probe_instance_id'] = $probedInstanceId;
        $payload = $this->transitionPayload($outbox, $payload);
        if ($this->isAccountErasurePayload($payload)
            && ($previousInstanceId === '' || ! hash_equals($previousInstanceId, $probedInstanceId))) {
            // Cognee background tasks are process-local and the wrapper lease
            // permits only one boot. A changed boot proves the old ambiguous
            // Improve is dead; never relaunch private work after erasure.
            $this->abandonErasedImproveLaunch($outbox, $payload, 'runtime_restarted');

            return true;
        }

        // Replaying the same key is safe after a lost response: the wrapper
        // returns its durable acceptance response or keeps an in-flight launch
        // fail-closed. A new boot may relaunch because the old task is dead.
        try {
            $response = $this->cognee->improveOnce($outbox->dataset, $launchKey, true);
        } catch (CogneeRequestException $error) {
            if ($error->isDeterministicLaunchRejection() || $error->isTerminalImproveFailure()) {
                // No live task remains. Reset to a new generation so the failed
                // Improve can yield to privacy-sensitive Forget/Delete work.
                if ($error->isTerminalImproveFailure()) {
                    $payload['launch_ack_pending_key'] = $launchKey;
                }
                $this->markLaunchRejected($outbox, $payload, 'new', $error);
                if ($error->isTerminalImproveFailure()) {
                    $this->acknowledgePendingLaunch($outbox);
                }
            }

            throw $error;
        }
        $launchInstanceId = $this->requireGuardedLaunchAcceptance('improve', $probedInstanceId);
        $runId = $this->cognee->pipelineInfoRunId($response);
        $datasetId = $this->cognee->datasetId($response);
        if (! $runId || ! $datasetId) {
            throw new RuntimeException('Cognee improve returned no pipeline run or dataset UUID.');
        }
        $payload = array_merge($payload, [
            'phase' => 'improve_polling',
            'pipeline_run_id' => $runId,
            'cognee_dataset_id' => $datasetId,
            'cognee_instance_id' => $launchInstanceId,
            'improve_started_at' => now()->toIso8601String(),
            'launch_ack_pending_key' => $launchKey,
        ]);
        $this->transitionPayload($outbox, $payload);
        $this->defer($outbox, self::POLL_INTERVAL_SECONDS);

        return false;
    }

    /** @param array<string,mixed> $payload */
    private function pollImprove(MemoryProjectionOutbox $outbox, array $payload): bool
    {
        $datasetId = trim((string) ($payload['cognee_dataset_id'] ?? ''));
        $runId = trim((string) ($payload['pipeline_run_id'] ?? ''));
        if ($datasetId === '' || $runId === '') {
            throw new RuntimeException('Cognee improve polling state is incomplete.');
        }

        try {
            $run = $this->cognee->pipelineRun($datasetId, $runId, true);
        } catch (CogneeRequestException $error) {
            if ($error->statusCode() === 404 && $this->runtimeInstanceChanged($payload)) {
                return $this->recoverImproveAfterCogneeRestart($outbox, $payload);
            }

            throw $error;
        }
        if (! $run || ! $this->isImproveRunForDataset($run, $datasetId, $runId)) {
            throw new RuntimeException('Cognee returned an unrelated improve pipeline run.');
        }
        $status = (string) ($run['status'] ?? 'unknown');
        if ($this->pipelineCompleted($status)) {
            return true;
        }
        if ($this->pipelineFailed($status)) {
            $payload['phase'] = 'new';
            unset($payload['pipeline_run_id'], $payload['launch_key'], $payload['cognee_dataset_id']);
            $this->transitionPayload($outbox, $payload);
            throw new RuntimeException('Cognee background improve reported a failed pipeline.');
        }
        if ($this->runtimeInstanceChanged($payload)) {
            return $this->recoverImproveAfterCogneeRestart($outbox, $payload);
        }

        $startedAt = isset($payload['improve_started_at'])
            ? Carbon::parse((string) $payload['improve_started_at'])
            : now();
        $pollDelay = self::POLL_INTERVAL_SECONDS;
        if ($startedAt->lte(now()->subSeconds(self::MAX_BACKGROUND_SECONDS))) {
            $payload['deadline_exceeded_at'] ??= now()->toIso8601String();
            $payload['operator_attention_required_at'] ??= now()->toIso8601String();
            $this->transitionPayload($outbox, $payload);
            $pollDelay = $this->overduePollDelay($startedAt);
        }
        $this->defer($outbox, $pollDelay);

        return false;
    }

    /** @param array<string,mixed> $payload */
    private function recoverCognifyLaunch(
        MemoryProjectionOutbox $outbox,
        ?MemoryLink $link,
        array $payload,
    ): bool {
        [$datasetId, $dataId] = $this->projectionIds($payload);
        // The activity feed is capped at 50 rows in Cognee 1.4. Use it only to
        // observe the runtime header when no run ID is known; exact run state
        // is read through Luczor's authenticated wrapper endpoint below.
        $knownRunId = trim((string) ($payload['pipeline_run_id'] ?? ''));
        try {
            $run = $knownRunId === ''
                ? null
                : $this->cognee->pipelineRun($datasetId, $knownRunId, true);
        } catch (CogneeRequestException $error) {
            if ($error->statusCode() === 404 && $this->runtimeInstanceChanged($payload)) {
                return $this->recoverAfterCogneeRestart($outbox, $link, $payload);
            }

            throw $error;
        }
        if ($knownRunId === '') {
            $this->cognee->pipelineRuns($datasetId, true);
        }
        if ($knownRunId !== '' && $run !== null && $this->isCognifyRunForDataset($run, $datasetId, $knownRunId)) {
            $status = (string) ($run['status'] ?? 'unknown');
            if ($this->pipelineCompleted($status) || $this->pipelineFailed($status)) {
                return $this->handlePipelineRunStatus($outbox, $link, $payload, $dataId, $status);
            }
            if ($this->runtimeInstanceChanged($payload)) {
                return $this->recoverAfterCogneeRestart($outbox, $link, $payload);
            }

            $payload['phase'] = 'polling';
            $payload['cognify_started_at'] ??= $payload['launch_intent_at'] ?? now()->toIso8601String();
            $this->transitionPayload($outbox, $payload);

            return $this->handlePipelineRunStatus($outbox, $link, $payload, $dataId, $status);
        }

        if ($this->runtimeInstanceChanged($payload)) {
            // The old process-local task is now provably dead. Re-enter through
            // the atomic source/turn claim: an erased source is compensated
            // immediately, while an eligible source receives a new generation.
            return $this->recoverAfterCogneeRestart($outbox, $link, $payload);
        }

        // Replay the same launch key. The Luczor Cognee wrapper serializes and
        // caches this key per process, so a lost HTTP response cannot create a
        // second run. A container restart changes the instance ID and is
        // handled above; its process-local task is then provably gone.
        $launchKey = trim((string) ($payload['launch_key'] ?? ''));
        if (! preg_match('/^[a-f0-9]{64}$/', $launchKey)) {
            $payload['recovery_required_at'] ??= now()->toIso8601String();
            $this->transitionPayload($outbox, $payload);
            $this->defer($outbox, self::POLL_INTERVAL_SECONDS);

            return false;
        }

        $probedInstanceId = $this->cognee->probeRuntime(true);
        if (! $probedInstanceId) {
            throw new RuntimeException('Cognee runtime guard was not observed; refusing an unguarded cognify replay.');
        }
        try {
            $response = $this->cognee->cognifyOnce($outbox->dataset, $launchKey, true);
        } catch (CogneeRequestException $error) {
            if ($error->isDeterministicLaunchRejection()) {
                $this->markLaunchRejected($outbox, $payload, 'cognify_rejected', $error);
            }

            throw $error;
        }
        $launchInstanceId = $this->requireGuardedLaunchAcceptance('cognify', $probedInstanceId);
        $runId = $this->cognee->cognifyRunId($response, $datasetId);
        if (! $runId) {
            throw new RuntimeException('Cognee idempotent cognify replay returned no pipeline run UUID.');
        }
        $payload['phase'] = 'polling';
        $payload['pipeline_run_id'] = $runId;
        $payload['cognify_accepted_at'] = now()->toIso8601String();
        $payload['cognee_instance_id'] = $launchInstanceId;
        $payload['launch_ack_pending_key'] = $launchKey;
        $this->transitionPayload($outbox, $payload);
        $link?->update(['projection_status' => 'processing']);
        $this->defer($outbox, self::POLL_INTERVAL_SECONDS);

        return false;
    }

    /** @param array<string,mixed> $payload */
    private function pollCognify(MemoryProjectionOutbox $outbox, ?MemoryLink $link, array $payload): bool
    {
        [$datasetId, $dataId] = $this->projectionIds($payload);
        $runId = trim((string) ($payload['pipeline_run_id'] ?? ''));
        if ($runId === '') {
            $payload['phase'] = 'cognify_launching';
            $this->transitionPayload($outbox, $payload);

            return $this->recoverCognifyLaunch($outbox, $link, $payload);
        }

        try {
            $run = $this->cognee->pipelineRun($datasetId, $runId, true);
        } catch (CogneeRequestException $error) {
            if ($error->statusCode() === 404 && $this->runtimeInstanceChanged($payload)) {
                return $this->recoverAfterCogneeRestart($outbox, $link, $payload);
            }

            throw $error;
        }
        if ($run === null || ! $this->isCognifyRunForDataset($run, $datasetId, $runId)) {
            throw new RuntimeException('Cognee exact Cognify run identity did not match the polling request.');
        }
        $status = (string) ($run['status'] ?? 'unknown');
        if ($this->pipelineCompleted($status) || $this->pipelineFailed($status)) {
            return $this->handlePipelineRunStatus($outbox, $link, $payload, $dataId, $status);
        }
        if ($this->runtimeInstanceChanged($payload)) {
            return $this->recoverAfterCogneeRestart($outbox, $link, $payload);
        }

        return $this->handlePipelineRunStatus($outbox, $link, $payload, $dataId, $status);
    }

    /** @param array<string,mixed> $payload */
    private function finalizeWithContentLock(
        MemoryProjectionOutbox $outbox,
        ?MemoryLink $link,
        array $payload,
        string $dataId,
    ): bool {
        $contentHash = trim((string) ($payload['content_hash'] ?? ''));
        $contentIdentity = $contentHash !== ''
            ? $contentHash
            : ($link === null ? $dataId : (string) $link->content_hash);

        return $this->withContentLock(
            $outbox,
            $outbox->dataset,
            $contentIdentity,
            function () use ($outbox, $dataId): bool {
                $this->finalizeUpsert($outbox, $dataId);

                return true;
            }
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{0:string,1:string}
     */
    private function projectionIds(array $payload): array
    {
        $datasetId = trim((string) ($payload['cognee_dataset_id'] ?? ''));
        $dataId = trim((string) ($payload['cognee_memory_id'] ?? ''));
        if ($datasetId === '' || $dataId === '') {
            throw new RuntimeException('Cognee polling state is missing its dataset or Data UUID.');
        }

        return [$datasetId, $dataId];
    }

    /** @param array<string,mixed> $run */
    private function isCognifyRunForDataset(array $run, string $datasetId, string $runId): bool
    {
        return strtolower((string) ($run['pipeline_name'] ?? '')) === 'cognify_pipeline'
            && hash_equals(strtolower($datasetId), strtolower((string) ($run['dataset_id'] ?? '')))
            && hash_equals(strtolower($runId), strtolower((string) ($run['pipeline_run_id'] ?? '')));
    }

    /** @param array<string,mixed> $run */
    private function isImproveRunForDataset(array $run, string $datasetId, string $runId): bool
    {
        return strtolower((string) ($run['pipeline_name'] ?? '')) === 'improve_pipeline'
            && hash_equals(strtolower($datasetId), strtolower((string) ($run['dataset_id'] ?? '')))
            && hash_equals(strtolower($runId), strtolower((string) ($run['pipeline_run_id'] ?? '')));
    }

    /** @param array<string,mixed> $payload */
    private function runtimeInstanceChanged(array $payload): bool
    {
        $expected = strtolower(trim((string) ($payload['cognee_instance_id'] ?? '')));
        $current = strtolower(trim((string) ($this->cognee->observedInstanceId() ?? '')));

        return $expected !== '' && $current !== '' && ! hash_equals($expected, $current);
    }

    private function requireGuardedLaunchAcceptance(string $operation, string $probedInstanceId): string
    {
        $responseInstanceId = $this->cognee->observedInstanceId();
        $launchInstanceId = $this->cognee->observedLaunchInstanceId();
        if (! $responseInstanceId
            || ! hash_equals(strtolower($probedInstanceId), strtolower($responseInstanceId))
            || ! $launchInstanceId) {
            throw new RuntimeException("Cognee {$operation} launch was not accepted by the probed runtime guard.");
        }

        return $launchInstanceId;
    }

    /** @param array<string,mixed> $payload */
    private function recoverAfterCogneeRestart(
        MemoryProjectionOutbox $outbox,
        ?MemoryLink $link,
        array $payload,
    ): bool {
        if (! $this->acknowledgePendingLaunch($outbox)) {
            $this->defer($outbox, self::POLL_INTERVAL_SECONDS);

            return false;
        }
        $outbox->refresh();
        $transition = DB::transaction(function () use ($outbox): array {
            $link = MemoryLink::query()->whereKey($outbox->memory_link_id)->lockForUpdate()->first();
            $rows = $this->lockDatasetTurnRows($outbox->dataset);
            $current = $rows->firstWhere('id', $outbox->id);
            if (! $current) {
                throw new RuntimeException('Memory restart recovery lost its outbox row.');
            }
            if ($this->datasetTurnOwner($rows)?->id !== $current->id) {
                $current->update([
                    'status' => 'pending',
                    'next_attempt_at' => now()->addSeconds(5),
                    'last_error' => null,
                ]);

                return ['state' => 'deferred', 'link' => $link, 'payload' => $current->payload ?? []];
            }

            $payload = $current->payload ?? [];
            [, $dataId] = $this->projectionIds($payload);
            $payload['recovery_generation'] = (int) ($payload['recovery_generation'] ?? 0) + 1;
            unset(
                $payload['pipeline_run_id'],
                $payload['launch_key'],
                $payload['deadline_exceeded_at'],
                $payload['cognee_instance_id'],
            );
            if (! $this->isProjectionEligible($link)) {
                $payload['phase'] = 'restart_source_ineligible';
                $payload['source_ineligible_at'] = now()->toIso8601String();
                $this->scrubContentSnapshot($payload);
                $current->update(['payload' => $payload]);
                $link?->update([
                    'cognee_memory_id' => $dataId,
                    'projection_status' => 'delete_pending',
                ]);

                return ['state' => 'ineligible', 'link' => $link, 'payload' => $payload];
            }

            $payload['phase'] = 'ingested';
            $current->update(['payload' => $payload]);
            $link->update([
                'cognee_memory_id' => $dataId,
                'projection_status' => 'processing',
            ]);

            return ['state' => 'eligible', 'link' => $link, 'payload' => $payload];
        }, 3);

        if ($transition['state'] === 'deferred') {
            return false;
        }
        $link = $transition['link'];
        $payload = $transition['payload'];
        if ($transition['state'] === 'ineligible') {
            $this->replayTerminalUpsertEffects($outbox, $link, $payload);

            return true;
        }

        return $this->launchCognify($outbox);
    }

    /** @param array<string,mixed> $payload */
    private function recoverImproveAfterCogneeRestart(
        MemoryProjectionOutbox $outbox,
        array $payload,
    ): bool {
        // A restarted wrapper requires a new launch generation. Never overwrite
        // the durable acknowledgement key from the prior generation.
        if (! $this->acknowledgePendingLaunch($outbox)) {
            $this->defer($outbox, self::POLL_INTERVAL_SECONDS);

            return false;
        }
        $outbox->refresh();
        $payload = $outbox->payload ?? [];
        $payload['phase'] = 'new';
        $payload['recovery_generation'] = (int) ($payload['recovery_generation'] ?? 0) + 1;
        unset($payload['pipeline_run_id'], $payload['launch_key'], $payload['cognee_dataset_id']);
        $this->transitionPayload($outbox, $payload);

        return $this->improve($outbox->fresh());
    }

    /** @param array<string,mixed> $payload */
    private function handlePipelineRunStatus(
        MemoryProjectionOutbox $outbox,
        ?MemoryLink $link,
        array $payload,
        string $dataId,
        string $status,
    ): bool {
        if ($this->pipelineFailed($status)) {
            $payload['phase'] = 'cognify_failed';
            unset($payload['pipeline_run_id'], $payload['cognify_started_at'], $payload['launch_key']);
            $this->transitionPayload($outbox, $payload);
            throw new RuntimeException('Cognee background cognify reported a failed pipeline.');
        }
        if ($this->pipelineCompleted($status)) {
            return $this->finalizeWithContentLock($outbox, $link, $payload, $dataId);
        }

        $startedAt = isset($payload['cognify_started_at'])
            ? Carbon::parse((string) $payload['cognify_started_at'])
            : now();
        $pollDelay = self::POLL_INTERVAL_SECONDS;
        if ($startedAt->lte(now()->subSeconds(self::MAX_BACKGROUND_SECONDS))) {
            // A long-running task is not assumed dead. Only a changed runtime
            // instance (checked before this method) proves Cognee's
            // process-local task disappeared and permits a new generation.
            $payload['deadline_exceeded_at'] ??= now()->toIso8601String();
            $payload['operator_attention_required_at'] ??= now()->toIso8601String();
            if (! $this->pipelineRunning($status)) {
                $payload['recovery_required_at'] ??= now()->toIso8601String();
            }
            $this->transitionPayload($outbox, $payload);
            $pollDelay = $this->overduePollDelay($startedAt);
        }

        $link?->update(['projection_status' => 'processing']);
        $this->defer($outbox, $pollDelay);

        return false;
    }

    private function finalizeUpsert(MemoryProjectionOutbox $outbox, string $dataId): void
    {
        $needsDelete = DB::transaction(function () use ($outbox, $dataId) {
            $link = MemoryLink::query()->whereKey($outbox->memory_link_id)->lockForUpdate()->first();
            if ($this->isProjectionEligible($link)) {
                $link->update([
                    'cognee_memory_id' => $dataId,
                    'projection_status' => 'ready',
                ]);

                return null;
            }

            // Forget/supersede may win while add/cognify is in flight. Preserve
            // the returned Data UUID in a durable delete event so the newly
            // created Cognee object cannot become an orphan.
            if ($link) {
                $link->update([
                    'cognee_memory_id' => $dataId,
                    'projection_status' => 'delete_pending',
                ]);
            }

            return ['link' => $link];
        });

        if (is_array($needsDelete)) {
            $this->queueCompensatingDelete($outbox, $dataId, $needsDelete['link']);
        }
    }

    private function queueCompensatingDelete(
        MemoryProjectionOutbox $source,
        string $dataId,
        ?MemoryLink $link,
    ): void {
        $freshSource = $source->fresh() ?? $source;
        $linkId = $link ? $link->id : $freshSource->memory_link_id;
        $sourcePayload = $freshSource->payload ?? [];
        $providerMemoryLinkId = $this->providerMemoryLinkId($freshSource, $sourcePayload);
        $erasureReason = trim((string) (
            $sourcePayload['erasure_reason']
                ?? $sourcePayload['account_erasure_reason']
                ?? $sourcePayload['source_erasure_reason']
                ?? ''
        ));
        $dedupe = hash('sha256', implode('|', [
            'delete',
            $freshSource->dataset,
            $linkId ?? $providerMemoryLinkId ?? 'none',
            $dataId,
        ]));
        $delete = MemoryProjectionOutbox::query()->firstOrCreate(['dedupe_key' => $dedupe], [
            'memory_link_id' => $linkId,
            'user_id' => $freshSource->user_id,
            'action' => 'delete',
            'dataset' => $freshSource->dataset,
            'payload' => array_filter([
                'cognee_memory_id' => $dataId,
                'content_hash' => $link
                    ? $link->content_hash
                    : ($sourcePayload['content_hash'] ?? null),
                'provider_memory_link_id' => $providerMemoryLinkId,
                'erasure_reason' => $erasureReason !== '' ? $erasureReason : null,
            ], static fn (mixed $value): bool => $value !== null),
            'status' => 'pending',
        ]);
        if ($erasureReason !== '') {
            $deletePayload = $delete->payload ?? [];
            $deletePayload['erasure_reason'] = $erasureReason;
            $updates = ['payload' => $deletePayload];
            if ($this->isAccountErasurePayload($sourcePayload)) {
                $updates['user_id'] = null;
            }
            $delete->update($updates);
        }
        if (! $delete->wasRecentlyCreated && $delete->status !== 'failed') {
            return;
        }

        $delete->update(['status' => 'queued', 'next_attempt_at' => null]);
        ProcessMemoryProjection::dispatch($delete->id);
    }

    /**
     * @return array{state:'none'|'adopted'|'ineligible'|'deferred',link:?MemoryLink,data_id:string,payload:array<string,mixed>}
     */
    private function adoptRetainedProjection(MemoryProjectionOutbox $outbox): array
    {
        return DB::transaction(function () use ($outbox): array {
            $link = MemoryLink::query()->whereKey($outbox->memory_link_id)->lockForUpdate()->first();
            $rows = $this->lockDatasetTurnRows($outbox->dataset);
            $current = $rows->firstWhere('id', $outbox->id);
            if (! $current) {
                throw new RuntimeException('Retained projection adoption lost its outbox row.');
            }
            if ($this->datasetTurnOwner($rows)?->id !== $current->id) {
                $current->update([
                    'status' => 'pending',
                    'next_attempt_at' => now()->addSeconds(5),
                    'last_error' => null,
                ]);

                return [
                    'state' => 'deferred',
                    'link' => $link,
                    'data_id' => '',
                    'payload' => $current->payload ?? [],
                ];
            }

            $payload = $current->payload ?? [];
            if ($link === null) {
                // Forget can delete the canonical row before a queued, never
                // started Upsert gets its turn. That is a terminal source
                // decision, not an exceptional retry. Preserve privacy facts,
                // remove every recovery snapshot, and compensate any Data IDs
                // which a prior interrupted attempt already made durable.
                $dataId = trim((string) ($payload['cognee_memory_id'] ?? ''));
                $recovered = is_array($payload['recovered_data_ids'] ?? null)
                    ? $payload['recovered_data_ids']
                    : [];
                if ($dataId === '' && $recovered !== []) {
                    $dataId = trim((string) reset($recovered));
                    $payload['cognee_memory_id'] = $dataId;
                }
                $payload['phase'] = $dataId === ''
                    ? 'add_skipped_source_ineligible'
                    : 'cognify_source_ineligible';
                $payload['source_ineligible_at'] = now()->toIso8601String();
                $this->scrubContentSnapshot($payload);
                $current->update(['payload' => $payload]);

                return [
                    'state' => 'ineligible',
                    'link' => null,
                    'data_id' => $dataId,
                    'payload' => $payload,
                ];
            }

            $dataId = trim((string) ($link->cognee_memory_id ?? ''));
            if ($dataId === '') {
                return ['state' => 'none', 'link' => $link, 'data_id' => '', 'payload' => $payload];
            }
            if (! $this->isUuid($dataId)) {
                throw new RuntimeException('Canonical memory contains an invalid retained Data UUID.');
            }
            if (! $this->isProjectionEligible($link)) {
                $payload['phase'] = 'cognify_source_ineligible';
                $payload['source_ineligible_at'] = now()->toIso8601String();
                $payload['cognee_memory_id'] = $dataId;
                $this->scrubContentSnapshot($payload);
                $current->update(['payload' => $payload]);
                $link->update(['projection_status' => 'delete_pending']);

                return [
                    'state' => 'ineligible',
                    'link' => $link,
                    'data_id' => $dataId,
                    'payload' => $payload,
                ];
            }

            // A preceding Delete transferred an already built same-content
            // projection to this replacement. Adopt it atomically so another
            // Delete sees a live SQL owner before the Upsert completes.
            $link->update(['projection_status' => 'ready']);

            return ['state' => 'adopted', 'link' => $link, 'data_id' => $dataId, 'payload' => $payload];
        }, 3);
    }

    /**
     * @return array{state:'claimed'|'deferred'|'ineligible',link:?MemoryLink,payload:array<string,mixed>}
     */
    private function claimAddEgress(MemoryProjectionOutbox $outbox, bool $absenceConfirmed): array
    {
        return DB::transaction(function () use ($outbox, $absenceConfirmed): array {
            // Match Forget/Reconcile lock order: canonical link before outbox.
            $link = MemoryLink::query()->whereKey($outbox->memory_link_id)->lockForUpdate()->first();
            $rows = $this->lockDatasetTurnRows($outbox->dataset);
            $current = $rows->firstWhere('id', $outbox->id);
            if (! $current) {
                throw new RuntimeException('Memory Add egress claim lost its outbox row.');
            }

            if ($this->datasetTurnOwner($rows)?->id !== $current->id) {
                $current->update([
                    'status' => 'pending',
                    'next_attempt_at' => now()->addSeconds(5),
                    'last_error' => null,
                ]);

                return ['state' => 'deferred', 'link' => $link, 'payload' => $current->payload ?? []];
            }

            $payload = $current->payload ?? [];
            $phase = (string) ($payload['phase'] ?? '');
            if (! in_array($phase, ['adding_prepared', 'adding'], true)) {
                throw new RuntimeException('Memory Add egress claim observed an invalid phase.');
            }

            if (! $this->isProjectionEligible($link)) {
                $payload['phase'] = $absenceConfirmed
                    ? 'add_absent_recovered'
                    : 'add_skipped_source_ineligible';
                $payload[$absenceConfirmed ? 'add_absence_confirmed_at' : 'add_skipped_at'] = now()->toIso8601String();
                $this->scrubContentSnapshot($payload);
                $current->update(['payload' => $payload]);
                $link?->update(['projection_status' => $this->inactiveProjectionStatus($link)]);

                return ['state' => 'ineligible', 'link' => $link, 'payload' => $payload];
            }

            $payload['phase'] = 'adding';
            $payload['add_attempted_at'] = now()->toIso8601String();
            $current->update(['payload' => $payload]);

            return ['state' => 'claimed', 'link' => $link, 'payload' => $payload];
        }, 3);
    }

    /**
     * @return array{state:'claimed'|'deferred'|'ineligible',link:?MemoryLink,payload:array<string,mixed>}
     */
    private function claimCognifyEgress(MemoryProjectionOutbox $outbox): array
    {
        return DB::transaction(function () use ($outbox): array {
            // Persist the launch claim before even probing the provider. This
            // gives Forget and Cognify one deterministic SQL ordering point.
            $link = MemoryLink::query()->whereKey($outbox->memory_link_id)->lockForUpdate()->first();
            $rows = $this->lockDatasetTurnRows($outbox->dataset);
            $current = $rows->firstWhere('id', $outbox->id);
            if (! $current) {
                throw new RuntimeException('Memory Cognify egress claim lost its outbox row.');
            }

            if ($this->datasetTurnOwner($rows)?->id !== $current->id) {
                $current->update([
                    'status' => 'pending',
                    'next_attempt_at' => now()->addSeconds(5),
                    'last_error' => null,
                ]);

                return ['state' => 'deferred', 'link' => $link, 'payload' => $current->payload ?? []];
            }

            $payload = $current->payload ?? [];
            $phase = (string) ($payload['phase'] ?? '');
            if (! in_array($phase, ['ingested', 'cognify_rejected', 'cognify_failed'], true)) {
                throw new RuntimeException('Memory Cognify egress claim observed an invalid phase.');
            }

            $dataId = trim((string) ($payload['cognee_memory_id'] ?? ''));
            if (! $this->isUuid($dataId)) {
                throw new RuntimeException('Memory Cognify egress claim is missing its Data UUID.');
            }
            if (! $this->isProjectionEligible($link)) {
                $payload['phase'] = 'cognify_source_ineligible';
                $payload['source_ineligible_at'] = now()->toIso8601String();
                unset($payload['pipeline_run_id'], $payload['launch_key']);
                $this->scrubContentSnapshot($payload);
                $current->update(['payload' => $payload]);
                $link?->update([
                    'cognee_memory_id' => $dataId,
                    'projection_status' => 'delete_pending',
                ]);

                return ['state' => 'ineligible', 'link' => $link, 'payload' => $payload];
            }

            $startedAt = now()->toIso8601String();
            $generation = (int) ($payload['launch_generation'] ?? 0) + 1;
            $launchKey = hash('sha256', implode('|', [
                'luczor-cognify-v1',
                $current->dedupe_key,
                (string) $generation,
            ]));
            $payload = array_merge($payload, [
                'phase' => 'cognify_launching',
                'cognify_started_at' => $startedAt,
                'launch_intent_at' => $startedAt,
                'launch_source_claimed_at' => $startedAt,
                'launch_generation' => $generation,
                'launch_key' => $launchKey,
            ]);
            unset(
                $payload['deadline_exceeded_at'],
                $payload['pipeline_run_id'],
                $payload['cognee_instance_id'],
                $payload['launch_http_attempted_at'],
            );
            $current->update(['payload' => $payload]);
            $link->update([
                'cognee_memory_id' => $dataId,
                'projection_status' => 'processing',
            ]);

            return ['state' => 'claimed', 'link' => $link, 'payload' => $payload];
        }, 3);
    }

    /**
     * @return array{state:'released'|'ineligible',link:?MemoryLink,payload:array<string,mixed>}
     */
    private function releaseUnstartedCognifyClaim(
        MemoryProjectionOutbox $outbox,
        string $launchKey,
    ): array {
        return DB::transaction(function () use ($outbox, $launchKey): array {
            $link = MemoryLink::query()->whereKey($outbox->memory_link_id)->lockForUpdate()->first();
            $current = MemoryProjectionOutbox::query()->whereKey($outbox->id)->lockForUpdate()->firstOrFail();
            $payload = $current->payload ?? [];
            if (($payload['phase'] ?? null) !== 'cognify_launching'
                || ! hash_equals($launchKey, (string) ($payload['launch_key'] ?? ''))
                || isset($payload['launch_http_attempted_at'])) {
                throw new RuntimeException('Memory Cognify launch claim is no longer safely releasable.');
            }

            unset(
                $payload['launch_key'],
                $payload['launch_intent_at'],
                $payload['launch_source_claimed_at'],
                $payload['cognee_instance_id'],
            );
            if (! $this->isProjectionEligible($link)) {
                $dataId = trim((string) ($payload['cognee_memory_id'] ?? ''));
                if (! $this->isUuid($dataId)) {
                    throw new RuntimeException('Released Cognify claim is missing its Data UUID.');
                }
                $payload['phase'] = 'cognify_source_ineligible';
                $payload['source_ineligible_at'] = now()->toIso8601String();
                $this->scrubContentSnapshot($payload);
                $current->update(['payload' => $payload]);
                $link?->update([
                    'cognee_memory_id' => $dataId,
                    'projection_status' => 'delete_pending',
                ]);

                return ['state' => 'ineligible', 'link' => $link, 'payload' => $payload];
            }

            $payload['phase'] = 'ingested';
            $payload['last_probe_failed_at'] = now()->toIso8601String();
            $current->update(['payload' => $payload]);

            return ['state' => 'released', 'link' => $link, 'payload' => $payload];
        }, 3);
    }

    /** @param array<string,mixed> $payload */
    private function replayTerminalUpsertEffects(
        MemoryProjectionOutbox $outbox,
        ?MemoryLink $link,
        array $payload,
    ): void {
        $phase = (string) ($payload['phase'] ?? '');
        if (in_array($phase, ['add_absent_recovered', 'add_skipped_source_ineligible'], true)) {
            $link?->update(['projection_status' => $this->inactiveProjectionStatus($link)]);

            return;
        }
        if (! in_array($phase, ['restart_source_ineligible', 'cognify_source_ineligible'], true)) {
            throw new RuntimeException('Unknown terminal memory upsert phase.');
        }

        $dataId = trim((string) ($payload['cognee_memory_id'] ?? ''));
        if (! $this->isUuid($dataId)) {
            throw new RuntimeException('Terminal memory recovery is missing its Data UUID.');
        }

        $link?->update([
            'cognee_memory_id' => $dataId,
            'projection_status' => 'delete_pending',
        ]);
        $this->queueRecoveredDataCompensation($outbox, $link, $payload, true);
    }

    /** @param array<string,mixed> $payload */
    private function queueRecoveredDataCompensation(
        MemoryProjectionOutbox $outbox,
        ?MemoryLink $link,
        array $payload,
        bool $includePrimary,
    ): void {
        $primary = trim((string) ($payload['cognee_memory_id'] ?? ''));
        $stored = $payload['recovered_data_ids'] ?? [];
        $dataIds = is_array($stored) ? $stored : [];
        if ($primary !== '') {
            array_unshift($dataIds, $primary);
        }
        $dataIds = array_values(array_unique(array_map(
            fn (mixed $dataId): string => trim((string) $dataId),
            $dataIds,
        )));

        foreach ($dataIds as $dataId) {
            if (! $this->isUuid($dataId)) {
                throw new RuntimeException('Durable Add recovery contains an invalid Data UUID.');
            }
            if (! $includePrimary && hash_equals($primary, $dataId)) {
                continue;
            }

            $this->queueCompensatingDelete($outbox, $dataId, $link);
        }
    }

    private function ownsDatasetTurn(MemoryProjectionOutbox $outbox): bool
    {
        return DB::transaction(function () use ($outbox) {
            $owner = $this->datasetTurnOwner($this->lockDatasetTurnRows($outbox->dataset));

            return $owner?->id === $outbox->id;
        });
    }

    private function claimImproveLaunchIntent(MemoryProjectionOutbox $outbox): bool
    {
        return DB::transaction(function () use ($outbox) {
            $rows = $this->lockDatasetTurnRows($outbox->dataset);
            $owner = $this->datasetTurnOwner($rows);
            if (! $owner || $owner->id !== $outbox->id) {
                return false;
            }

            $current = $owner;
            $payload = $current->payload ?? [];
            if ($current->action !== 'improve' || ($payload['phase'] ?? 'new') !== 'new') {
                return false;
            }

            $generation = (int) ($payload['launch_generation'] ?? 0) + 1;
            unset(
                $payload['pipeline_run_id'],
                $payload['cognee_dataset_id'],
                $payload['cognee_probe_instance_id'],
                $payload['cognee_instance_id'],
                $payload['improve_started_at'],
            );
            $payload = array_merge($payload, [
                'phase' => 'improve_launching',
                'launch_key' => hash('sha256', implode('|', [
                    'luczor-improve-v1',
                    $current->dedupe_key,
                    (string) $generation,
                ])),
                'launch_generation' => $generation,
                'launch_intent_at' => now()->toIso8601String(),
            ]);
            $current->update(['payload' => $payload]);

            return true;
        });
    }

    private function releaseUnstartedImproveLaunch(MemoryProjectionOutbox $outbox, string $launchKey): void
    {
        DB::transaction(function () use ($outbox, $launchKey) {
            $current = MemoryProjectionOutbox::query()->whereKey($outbox->id)->lockForUpdate()->firstOrFail();
            $payload = $current->payload ?? [];
            if (($payload['phase'] ?? null) !== 'improve_launching'
                || ! hash_equals($launchKey, (string) ($payload['launch_key'] ?? ''))) {
                return;
            }

            $payload['phase'] = 'new';
            $payload['last_probe_failed_at'] = now()->toIso8601String();
            unset(
                $payload['launch_key'],
                $payload['launch_intent_at'],
                $payload['cognee_probe_instance_id'],
                $payload['cognee_instance_id'],
            );
            $current->update(['payload' => $payload]);
        });
    }

    /** @return Collection<int,MemoryProjectionOutbox> */
    private function lockDatasetTurnRows(string $dataset): Collection
    {
        return MemoryProjectionOutbox::query()
            ->where('dataset', $dataset)
            ->whereIn('action', ['upsert', 'delete', 'improve'])
            ->whereIn('status', ['pending', 'queued', 'processing', 'failed'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int,MemoryProjectionOutbox>  $rows
     */
    private function datasetTurnOwner(Collection $rows): ?MemoryProjectionOutbox
    {
        $protectedOwner = $rows->first(function (MemoryProjectionOutbox $row) {
            $phase = ($row->payload ?? [])['phase'] ?? null;

            return in_array($phase, [
                'adding',
                'ingested',
                'cognify_launching',
                'polling',
                'improve_launching',
                'improve_polling',
            ], true);
        });
        if ($protectedOwner) {
            return $protectedOwner;
        }

        // A terminal Improve failure has no live background task. Do not let
        // its retry backoff delay a later Forget/Delete indefinitely.
        $ready = $rows->filter(function (MemoryProjectionOutbox $row) {
            if ($row->status !== 'failed'
                || ! $row->next_attempt_at
                || ! $row->next_attempt_at->isFuture()) {
                return true;
            }

            $phase = ($row->payload ?? [])['phase'] ?? null;

            // Failed Deletes keep privacy priority; failed Upserts also remain
            // ordered unless a Delete supersedes them.
            return $row->action !== 'improve'
                || in_array($phase, ['improve_launching', 'improve_polling'], true);
        });

        return $ready->firstWhere('action', 'delete')
            ?? $ready->firstWhere('action', 'upsert')
            ?? $ready->first();
    }

    private function defer(MemoryProjectionOutbox $outbox, int $seconds): void
    {
        $outbox->update([
            'status' => 'pending',
            'next_attempt_at' => now()->addSeconds($seconds),
            'last_error' => null,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function scrubContentSnapshot(array &$payload): void
    {
        unset(
            $payload['content'],
            $payload['content_ciphertext'],
            $payload['content_snapshot_expires_at'],
        );
    }

    /**
     * Persist a worker payload transition without allowing a stale in-memory
     * copy to undo account erasure committed by another transaction.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function transitionPayload(MemoryProjectionOutbox $outbox, array $payload): array
    {
        return DB::transaction(function () use ($outbox, $payload): array {
            $current = MemoryProjectionOutbox::query()->whereKey($outbox->id)->lockForUpdate()->firstOrFail();
            $fresh = $current->payload ?? [];
            $reason = trim((string) (
                $fresh['erasure_reason'] ?? $fresh['account_erasure_reason'] ?? ''
            ));
            $sourceErasureReason = trim((string) ($fresh['source_erasure_reason'] ?? ''));
            $snapshotErasedAt = trim((string) ($fresh['content_snapshot_erased_at'] ?? ''));
            $sourceWasDeleted = $current->user_id === null
                && $current->memory_link_id !== null
                && ! MemoryLink::query()->whereKey($current->memory_link_id)->exists();
            if ($reason !== '' || $sourceErasureReason !== '' || $snapshotErasedAt !== '' || $sourceWasDeleted) {
                $reason = $reason !== '' ? $reason : 'account_deleted';
                if ($sourceErasureReason !== '') {
                    $payload['source_erasure_reason'] = $sourceErasureReason;
                } elseif ($current->action === 'delete') {
                    $payload['erasure_reason'] = $reason;
                } else {
                    $payload['account_erasure_reason'] = $reason;
                }
                $payload['content_snapshot_erased_at'] = $snapshotErasedAt !== ''
                    ? $snapshotErasedAt
                    : ($payload['content_snapshot_erased_at'] ?? now()->toIso8601String());
                $this->scrubContentSnapshot($payload);
            }

            // These are monotonic privacy/recovery facts. A stale worker may
            // advance its own phase, but can never remove a committed marker.
            foreach (['erasure_reason', 'account_erasure_reason', 'exact_forget_ack_at'] as $stickyKey) {
                if (array_key_exists($stickyKey, $fresh) && ! array_key_exists($stickyKey, $payload)) {
                    $payload[$stickyKey] = $fresh[$stickyKey];
                }
            }
            foreach (['source_erasure_reason', 'provider_memory_link_id', 'content_snapshot_erased_at'] as $stickyKey) {
                if (array_key_exists($stickyKey, $fresh)) {
                    $payload[$stickyKey] = $fresh[$stickyKey];
                }
            }

            $current->update(['payload' => $payload ?: null]);
            $outbox->setAttribute('payload', $payload ?: null);

            return $payload;
        }, 3);
    }

    private function contentSnapshotTtlSeconds(): int
    {
        return max(300, min(86400, (int) config('luczor.cognee.content_snapshot_ttl_seconds', 3600)));
    }

    /** @param array<string,mixed> $payload */
    private function providerMemoryLinkId(MemoryProjectionOutbox $outbox, array $payload): ?int
    {
        $currentLinkId = $this->positiveInteger($outbox->memory_link_id);
        $storedLinkId = array_key_exists('provider_memory_link_id', $payload)
            ? $this->positiveInteger($payload['provider_memory_link_id'])
            : null;
        if (array_key_exists('provider_memory_link_id', $payload) && $storedLinkId === null) {
            throw new RuntimeException('Memory projection has an invalid provider filename identity.');
        }
        if ($currentLinkId !== null && $storedLinkId !== null && $currentLinkId !== $storedLinkId) {
            throw new RuntimeException('Memory projection provider filename identity changed unexpectedly.');
        }

        return $storedLinkId ?? $currentLinkId;
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

    private function overduePollDelay(Carbon $startedAt): int
    {
        $overdueSeconds = max(0, (int) $startedAt->diffInSeconds(now(), false) - self::MAX_BACKGROUND_SECONDS);
        $exponent = min(6, intdiv($overdueSeconds, self::MAX_BACKGROUND_SECONDS));

        return min(3600, 60 * (2 ** $exponent));
    }

    /** @phpstan-impure */
    private function acknowledgePendingLaunch(MemoryProjectionOutbox $outbox): bool
    {
        $outbox->refresh();
        $payload = $outbox->payload ?? [];
        $launchKey = trim((string) ($payload['launch_ack_pending_key'] ?? ''));
        if ($launchKey === '') {
            return true;
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $launchKey)) {
            throw new RuntimeException('Cognee launch acknowledgement state is invalid.');
        }
        if (! $this->cognee->acknowledgeLaunch($launchKey)) {
            return false;
        }

        unset($payload['launch_ack_pending_key']);
        $this->transitionPayload($outbox, $payload);

        return true;
    }

    /** @param array<string,mixed> $payload */
    private function abandonErasedImproveLaunch(
        MemoryProjectionOutbox $outbox,
        array $payload,
        string $reason,
    ): void {
        $payload['phase'] = 'erasure_improve_abandoned';
        $payload['improve_abandoned_reason'] = $reason;
        $payload['improve_abandoned_at'] = now()->toIso8601String();
        unset(
            $payload['launch_key'],
            $payload['launch_intent_at'],
            $payload['pipeline_run_id'],
            $payload['cognee_probe_instance_id'],
            $payload['cognee_instance_id'],
        );
        $this->transitionPayload($outbox, $payload);
    }

    private function markTerminalAckPending(MemoryProjectionOutbox $outbox): void
    {
        $outbox->refresh();
        $payload = $outbox->payload ?? [];
        $payload['terminal_phase_before_ack'] = $payload['phase'] ?? null;
        $payload['phase'] = 'launch_ack_pending_terminal';
        $payload['launch_terminal_at'] ??= now()->toIso8601String();
        $this->transitionPayload($outbox, $payload);
    }

    /** @param array<string,mixed> $payload */
    private function markLaunchRejected(
        MemoryProjectionOutbox $outbox,
        array $payload,
        string $safePhase,
        CogneeRequestException $error,
    ): void {
        $payload['phase'] = $safePhase;
        $payload['last_launch_rejection_status'] = $error->statusCode();
        $payload['last_launch_rejected_at'] = now()->toIso8601String();
        unset(
            $payload['launch_key'],
            $payload['launch_intent_at'],
            $payload['pipeline_run_id'],
            $payload['cognee_probe_instance_id'],
            $payload['cognee_instance_id'],
        );
        $this->transitionPayload($outbox, $payload);
    }

    private function pipelineCompleted(string $status): bool
    {
        return str_contains(strtolower($status), 'completed');
    }

    private function pipelineRunning(string $status): bool
    {
        $status = strtolower($status);

        return str_contains($status, 'running')
            || str_contains($status, 'started')
            || str_contains($status, 'pending')
            || str_contains($status, 'in_progress');
    }

    private function pipelineFailed(string $status): bool
    {
        $status = strtolower($status);

        return str_contains($status, 'failed') || str_contains($status, 'errored');
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function isProjectionEligible(?MemoryLink $link, ?\DateTimeInterface $now = null): bool
    {
        return $link !== null && MemoryProjectionPolicy::isEligible($link, $now);
    }

    private function finalProjectionStatus(
        MemoryProjectionOutbox $outbox,
        MemoryLink $link,
        ?\DateTimeInterface $now = null,
    ): string {
        if (in_array($link->projection_status, ['legacy_review_required', 'not_required', 'deferred'], true)) {
            return $link->projection_status;
        }

        $requested = (string) (($outbox->payload ?? [])['final_projection_status'] ?? '');
        if ($outbox->memory_link_id === $link->id
            && in_array($requested, ['legacy_review_required', 'not_required'], true)) {
            return $requested;
        }

        // Shared Cognee UUIDs are cleared by whichever Delete runs first. The
        // reconciler temporarily marks every affected link `delete_pending`,
        // so a sibling's intended legacy-review state must be recovered from
        // its own durable Delete outbox rather than from the current row.
        $ownDelete = MemoryProjectionOutbox::query()
            ->where('action', 'delete')
            ->where('dataset', $link->dataset)
            ->where('memory_link_id', $link->id)
            ->latest('id')
            ->first();
        $ownRequested = $ownDelete
            ? (string) (($ownDelete->payload ?? [])['final_projection_status'] ?? '')
            : '';
        if (in_array($ownRequested, ['legacy_review_required', 'not_required'], true)) {
            return $ownRequested;
        }

        return $this->inactiveProjectionStatus($link, $now);
    }

    private function inactiveProjectionStatus(
        ?MemoryLink $link,
        ?\DateTimeInterface $now = null,
    ): string {
        $now ??= now();

        return $link
            && $link->status === 'active'
            && $link->retention !== 'session'
            && $link->valid_from
            && $link->valid_from->gt($now)
            && (! $link->valid_until || $link->valid_until->gt($now))
            && (! $link->expires_at || $link->expires_at->gt($now))
                ? 'deferred'
                : 'not_required';
    }

    /** @param \Closure():bool $callback */
    private function withContentLock(
        MemoryProjectionOutbox $outbox,
        string $dataset,
        string $contentIdentity,
        \Closure $callback,
    ): bool {
        $key = 'luczor:memory-projection:'.hash('sha256', $dataset."\0".$contentIdentity);
        $lockSeconds = (int) config('luczor.cognee.content_lock_seconds', 120);
        $lock = Cache::lock($key, $lockSeconds);
        if (! $lock->get()) {
            // A 20-second blocking wait sits outside the bounded provider
            // budget and can exceed both the job timeout and Redis
            // retry_after. Yield without charging an attempt; every provider
            // phase is already durable and safe to resume.
            $this->defer($outbox, 5);

            return false;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
