<?php

namespace App\Services;

use App\Jobs\ProcessMemoryProjection;
use App\Models\MemoryLink;
use App\Models\MemoryProjectionOutbox;
use App\Services\Cognee\CogneeClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

            $completed = match ($outbox->action) {
                'upsert' => $this->upsert($outbox),
                'delete' => $this->delete($outbox),
                'improve' => $this->improve($outbox),
                default => throw new RuntimeException('Unknown memory projection action.'),
            };

            if (! $completed) {
                return;
            }

            $payload = $outbox->fresh()->payload ?? [];
            unset($payload['content']);
            $outbox->update([
                'payload' => $payload ?: null,
                'status' => 'done',
                'processed_at' => now(),
                'next_attempt_at' => null,
            ]);
        } catch (Throwable $error) {
            $outbox->refresh();
            $failureCount = min(65535, (int) $outbox->attempts + 1);
            $outbox->update([
                'status' => 'failed',
                'attempts' => $failureCount,
                'last_error' => mb_substr($error->getMessage(), 0, 4000),
                'next_attempt_at' => now()->addSeconds(min(3600, 10 * (2 ** min(8, $failureCount - 1)))),
            ]);
            if ($outbox->memory_link_id) {
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
        if ($phase === 'cognify_launching') {
            return $this->recoverCognifyLaunch($outbox, $link, $payload);
        }
        if ($phase === 'polling') {
            return $this->pollCognify($outbox, $link, $payload);
        }

        $dataId = trim((string) ($payload['cognee_memory_id'] ?? ''));
        if ($phase === 'ingested' && ! $this->isProjectionEligible($link)) {
            if ($dataId !== '') {
                $link?->update(['cognee_memory_id' => $dataId, 'projection_status' => 'delete_pending']);
                $this->queueCompensatingDelete($outbox, $dataId, $link);
            }

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

        return $this->withContentLock(
            $outbox->dataset,
            trim((string) ($payload['content_hash'] ?? $link?->content_hash ?? '')) ?: (string) $outbox->id,
            function () use ($outbox, $phase, $payload) {
                $link = MemoryLink::query()->find($outbox->memory_link_id);
                if ($phase === 'new') {
                    if (! $this->isProjectionEligible($link)) {
                        $link?->update(['projection_status' => $this->inactiveProjectionStatus($link)]);

                        return true;
                    }

                    // Durable intent and a short-lived content snapshot are
                    // persisted before the external call. If the worker dies
                    // after /add, a retry can repeat Cognee's deterministic
                    // ingestion, recover the Data UUID and compensate safely.
                    $payload = array_merge($payload, [
                        'phase' => 'adding',
                        'content' => $link->summary,
                        'content_hash' => $link->content_hash,
                        'add_started_at' => now()->toIso8601String(),
                    ]);
                    $outbox->update(['payload' => $payload]);
                }

                if (($payload['phase'] ?? null) === 'adding') {
                    return $this->performAdd($outbox, $link, $payload);
                }

                return $this->launchCognify($outbox, $link, $payload);
            }
        );
    }

    /** @param array<string,mixed> $payload */
    private function performAdd(MemoryProjectionOutbox $outbox, ?MemoryLink $link, array $payload): bool
    {
        $content = (string) ($payload['content'] ?? '');
        $contentHash = trim((string) ($payload['content_hash'] ?? ''));
        if ($content === '' || $contentHash === '') {
            throw new RuntimeException('Cognee add recovery state is missing its content snapshot or hash.');
        }

        $response = $this->cognee->add($outbox->dataset, $content, [
            'memory_link_id' => $outbox->memory_link_id,
            'content_hash' => $contentHash,
        ], true);
        $dataId = $this->cognee->dataId($response);
        $datasetId = $this->cognee->datasetId($response);
        if (! $dataId || ! $datasetId) {
            throw new RuntimeException('Cognee add returned no Data UUID or dataset UUID.');
        }

        $payload = array_merge($payload, [
            'phase' => 'ingested',
            'cognee_memory_id' => $dataId,
            'cognee_dataset_id' => $datasetId,
            'ingested_at' => now()->toIso8601String(),
        ]);
        if ($instanceId = $this->cognee->observedInstanceId()) {
            $payload['cognee_instance_id'] = $instanceId;
        }
        $outbox->update(['payload' => $payload]);

        $link = MemoryLink::query()->find($outbox->memory_link_id);
        if (! $this->isProjectionEligible($link)) {
            $link?->update(['cognee_memory_id' => $dataId, 'projection_status' => 'delete_pending']);
            $this->queueCompensatingDelete($outbox, $dataId, $link);

            return true;
        }

        return $this->launchCognify($outbox, $link, $payload);
    }

    /** @param array<string,mixed> $payload */
    private function launchCognify(
        MemoryProjectionOutbox $outbox,
        ?MemoryLink $link,
        array $payload,
    ): bool {
        $startedAt = now()->toIso8601String();
        $generation = (int) ($payload['launch_generation'] ?? 0) + 1;
        $launchKey = hash('sha256', implode('|', [
            'luczor-cognify-v1',
            $outbox->dedupe_key,
            (string) $generation,
        ]));
        $instanceId = $this->cognee->observedInstanceId()
            ?? (isset($payload['cognee_instance_id']) ? (string) $payload['cognee_instance_id'] : null);
        if (! $instanceId) {
            throw new RuntimeException('Cognee runtime guard was not observed; refusing an unguarded cognify launch.');
        }
        $payload = array_merge($payload, [
            'phase' => 'cognify_launching',
            'cognify_started_at' => $startedAt,
            'launch_intent_at' => $startedAt,
            'launch_generation' => $generation,
            'launch_key' => $launchKey,
            'cognee_instance_id' => $instanceId,
        ]);
        unset($payload['deadline_exceeded_at'], $payload['pipeline_run_id']);

        // Persist the launch intent first. A timeout or process death after
        // Cognee accepts the request is then reconciled from its exact run,
        // instead of blindly starting a second background graph build.
        $outbox->update(['payload' => $payload]);
        $response = $this->cognee->cognifyOnce($outbox->dataset, $launchKey, true);
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
        $payload['cognee_instance_id'] = $this->cognee->observedLaunchInstanceId()
            ?? $this->cognee->observedInstanceId()
            ?? $instanceId;
        $outbox->update(['payload' => $payload]);
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

        $this->withContentLock($outbox->dataset, $contentHash ?: $cogneeId, function () use ($outbox, $cogneeId, $contentHash) {
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
                $hasActive = $links->contains(fn (MemoryLink $link) => MemoryProjectionPolicy::isEligible($link, $now));

                if ($hasActive) {
                    foreach ($links as $link) {
                        if (! MemoryProjectionPolicy::isEligible($link, $now)) {
                            $link->update([
                                'cognee_memory_id' => null,
                                'projection_status' => $this->finalProjectionStatus($outbox, $link, $now),
                            ]);
                        }
                    }
                }

                return $hasActive;
            });

            if ($hasActiveReference) {
                return;
            }

            $this->cognee->forget($outbox->dataset, $cogneeId, true);
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
        });

        return true;
    }

    private function improve(MemoryProjectionOutbox $outbox): bool
    {
        $payload = $outbox->payload ?? [];
        $phase = (string) ($payload['phase'] ?? 'new');
        if ($phase === 'improve_polling') {
            return $this->pollImprove($outbox, $payload);
        }
        if (! $this->ownsDatasetTurn($outbox)) {
            $this->defer($outbox, 5);

            return false;
        }

        $launchKey = trim((string) ($payload['launch_key'] ?? ''));
        if (! preg_match('/^[a-f0-9]{64}$/', $launchKey)) {
            $launchKey = hash('sha256', implode('|', [
                'luczor-improve-v1',
                $outbox->dedupe_key,
                (string) ((int) ($payload['launch_generation'] ?? 0) + 1),
            ]));
        }
        $payload = array_merge($payload, [
            'phase' => 'improve_launching',
            'launch_key' => $launchKey,
            'launch_generation' => (int) ($payload['launch_generation'] ?? 0) + ($phase === 'new' ? 1 : 0),
            'launch_intent_at' => $payload['launch_intent_at'] ?? now()->toIso8601String(),
        ]);
        $outbox->update(['payload' => $payload]);

        // Replaying the same key is safe after a lost response: the wrapper
        // returns its durable acceptance response or keeps an in-flight launch
        // fail-closed. A new boot may relaunch because the old task is dead.
        $response = $this->cognee->improveOnce($outbox->dataset, $launchKey, true);
        $runId = $this->cognee->pipelineInfoRunId($response);
        $datasetId = $this->cognee->datasetId($response);
        if (! $runId || ! $datasetId) {
            throw new RuntimeException('Cognee improve returned no pipeline run or dataset UUID.');
        }
        $payload = array_merge($payload, [
            'phase' => 'improve_polling',
            'pipeline_run_id' => $runId,
            'cognee_dataset_id' => $datasetId,
            'cognee_instance_id' => $this->cognee->observedLaunchInstanceId()
                ?? $this->cognee->observedInstanceId(),
            'improve_started_at' => now()->toIso8601String(),
        ]);
        $outbox->update(['payload' => $payload]);
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

        $run = $this->cognee->pipelineRun($datasetId, $runId, true);
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
            $outbox->update(['payload' => $payload]);
            throw new RuntimeException('Cognee background improve reported a failed pipeline.');
        }
        if ($this->runtimeInstanceChanged($payload)) {
            $payload['phase'] = 'new';
            $payload['recovery_generation'] = (int) ($payload['recovery_generation'] ?? 0) + 1;
            unset($payload['pipeline_run_id'], $payload['launch_key'], $payload['cognee_dataset_id']);
            $outbox->update(['payload' => $payload]);

            return $this->improve($outbox->fresh());
        }

        $startedAt = isset($payload['improve_started_at'])
            ? Carbon::parse((string) $payload['improve_started_at'])
            : now();
        if ($startedAt->lte(now()->subSeconds(self::MAX_BACKGROUND_SECONDS))) {
            $payload['deadline_exceeded_at'] ??= now()->toIso8601String();
            $outbox->update(['payload' => $payload]);
        }
        $this->defer($outbox, self::POLL_INTERVAL_SECONDS);

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
        $run = $knownRunId === ''
            ? null
            : $this->cognee->pipelineRun($datasetId, $knownRunId, true);
        if ($knownRunId === '') {
            $this->cognee->pipelineRuns($datasetId, true);
        }
        if ($knownRunId !== '' && $run !== null && $this->isCognifyRunForDataset($run, $datasetId)) {
            $status = (string) ($run['status'] ?? 'unknown');
            if ($this->pipelineCompleted($status) || $this->pipelineFailed($status)) {
                return $this->handlePipelineRunStatus($outbox, $link, $payload, $dataId, $status);
            }
            if ($this->runtimeInstanceChanged($payload)) {
                return $this->recoverAfterCogneeRestart($outbox, $link, $payload);
            }

            $payload['phase'] = 'polling';
            $payload['cognify_started_at'] ??= $payload['launch_intent_at'] ?? now()->toIso8601String();
            $outbox->update(['payload' => $payload]);

            return $this->handlePipelineRunStatus($outbox, $link, $payload, $dataId, $status);
        }

        if ($this->runtimeInstanceChanged($payload)) {
            // The launch response is still ambiguous: a restart may have
            // happened before the POST reached Cognee. Keep the exact same
            // idempotency key and replay it on the currently observed process.
            // Its guard returns the cached response or starts it exactly once.
            $payload['recovery_observed_instance_id'] = $this->cognee->observedInstanceId();
            $outbox->update(['payload' => $payload]);
        }

        // Replay the same launch key. The Luczor Cognee wrapper serializes and
        // caches this key per process, so a lost HTTP response cannot create a
        // second run. A container restart changes the instance ID and is
        // handled above; its process-local task is then provably gone.
        $launchKey = trim((string) ($payload['launch_key'] ?? ''));
        if (! preg_match('/^[a-f0-9]{64}$/', $launchKey)
            || ! $this->cognee->observedInstanceId()) {
            $payload['recovery_required_at'] ??= now()->toIso8601String();
            $outbox->update(['payload' => $payload]);
            $this->defer($outbox, self::POLL_INTERVAL_SECONDS);

            return false;
        }

        $response = $this->cognee->cognifyOnce($outbox->dataset, $launchKey, true);
        $runId = $this->cognee->cognifyRunId($response, $datasetId);
        if (! $runId) {
            throw new RuntimeException('Cognee idempotent cognify replay returned no pipeline run UUID.');
        }
        $payload['phase'] = 'polling';
        $payload['pipeline_run_id'] = $runId;
        $payload['cognify_accepted_at'] = now()->toIso8601String();
        $payload['cognee_instance_id'] = $this->cognee->observedLaunchInstanceId()
            ?? $this->cognee->observedInstanceId()
            ?? ($payload['cognee_instance_id'] ?? null);
        $outbox->update(['payload' => $payload]);
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
            $outbox->update(['payload' => $payload]);

            return $this->recoverCognifyLaunch($outbox, $link, $payload);
        }

        $run = $this->cognee->pipelineRun($datasetId, $runId, true);
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
    ): void {
        $contentHash = trim((string) ($payload['content_hash'] ?? ''));
        $contentIdentity = $contentHash !== ''
            ? $contentHash
            : ($link === null ? $dataId : (string) $link->content_hash);
        $this->withContentLock(
            $outbox->dataset,
            $contentIdentity,
            fn () => $this->finalizeUpsert($outbox, $dataId)
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
    private function isCognifyRunForDataset(array $run, string $datasetId): bool
    {
        return strtolower((string) ($run['pipeline_name'] ?? '')) === 'cognify_pipeline'
            && hash_equals(strtolower($datasetId), strtolower((string) ($run['dataset_id'] ?? '')));
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

    /** @param array<string,mixed> $payload */
    private function recoverAfterCogneeRestart(
        MemoryProjectionOutbox $outbox,
        ?MemoryLink $link,
        array $payload,
    ): bool {
        $payload['phase'] = 'ingested';
        $payload['recovery_generation'] = (int) ($payload['recovery_generation'] ?? 0) + 1;
        $payload['cognee_instance_id'] = $this->cognee->observedInstanceId();
        unset($payload['pipeline_run_id'], $payload['launch_key'], $payload['deadline_exceeded_at']);
        $outbox->update(['payload' => $payload]);

        return $this->launchCognify($outbox, $link, $payload);
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
            $payload['phase'] = 'ingested';
            unset($payload['pipeline_run_id'], $payload['cognify_started_at'], $payload['launch_key']);
            $outbox->update(['payload' => $payload]);
            throw new RuntimeException('Cognee background cognify reported a failed pipeline.');
        }
        if ($this->pipelineCompleted($status)) {
            $this->finalizeWithContentLock($outbox, $link, $payload, $dataId);

            return true;
        }

        $startedAt = isset($payload['cognify_started_at'])
            ? Carbon::parse((string) $payload['cognify_started_at'])
            : now();
        if ($startedAt->lte(now()->subSeconds(self::MAX_BACKGROUND_SECONDS))) {
            // A long-running task is not assumed dead. Only a changed runtime
            // instance (checked before this method) proves Cognee's
            // process-local task disappeared and permits a new generation.
            $payload['deadline_exceeded_at'] ??= now()->toIso8601String();
            if (! $this->pipelineRunning($status)) {
                $payload['recovery_required_at'] ??= now()->toIso8601String();
            }
            $outbox->update(['payload' => $payload]);
        }

        $link?->update(['projection_status' => 'processing']);
        $this->defer($outbox, self::POLL_INTERVAL_SECONDS);

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
        $dedupe = hash('sha256', implode('|', [
            'delete',
            $source->dataset,
            $link ? $link->id : 'none',
            $dataId,
        ]));
        $delete = MemoryProjectionOutbox::query()->firstOrCreate(['dedupe_key' => $dedupe], [
            'memory_link_id' => $link?->id,
            'user_id' => $source->user_id,
            'action' => 'delete',
            'dataset' => $source->dataset,
            'payload' => [
                'cognee_memory_id' => $dataId,
                'content_hash' => $link
                    ? $link->content_hash
                    : (($source->payload ?? [])['content_hash'] ?? null),
            ],
            'status' => 'pending',
        ]);
        if (! $delete->wasRecentlyCreated && $delete->status !== 'failed') {
            return;
        }

        $delete->update(['status' => 'queued', 'next_attempt_at' => null]);
        ProcessMemoryProjection::dispatch($delete->id);
    }

    private function ownsDatasetTurn(MemoryProjectionOutbox $outbox): bool
    {
        return DB::transaction(function () use ($outbox) {
            $rows = MemoryProjectionOutbox::query()
                ->where('dataset', $outbox->dataset)
                ->whereIn('action', ['upsert', 'delete', 'improve'])
                ->whereIn('status', ['pending', 'queued', 'processing', 'failed'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $owner = $rows->first(function (MemoryProjectionOutbox $row) {
                $phase = ($row->payload ?? [])['phase'] ?? null;

                return in_array($phase, [
                    'adding',
                    'ingested',
                    'cognify_launching',
                    'polling',
                    'improve_launching',
                    'improve_polling',
                ], true);
            }) ?? $rows->first();

            return $owner?->id === $outbox->id;
        });
    }

    private function defer(MemoryProjectionOutbox $outbox, int $seconds): void
    {
        $outbox->update([
            'status' => 'pending',
            'next_attempt_at' => now()->addSeconds($seconds),
            'last_error' => null,
        ]);
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

    private function isProjectionEligible(?MemoryLink $link, ?\DateTimeInterface $now = null): bool
    {
        return $link !== null && MemoryProjectionPolicy::isEligible($link, $now);
    }

    private function finalProjectionStatus(
        MemoryProjectionOutbox $outbox,
        MemoryLink $link,
        ?\DateTimeInterface $now = null,
    ): string {
        $requested = (string) (($outbox->payload ?? [])['final_projection_status'] ?? '');
        if ($outbox->memory_link_id === $link->id
            && in_array($requested, ['legacy_review_required', 'not_required'], true)) {
            return $requested;
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

    /**
     * @template T
     *
     * @param  \Closure():T  $callback
     * @return T
     */
    private function withContentLock(string $dataset, string $contentIdentity, \Closure $callback): mixed
    {
        $key = 'luczor:memory-projection:'.hash('sha256', $dataset."\0".$contentIdentity);

        return Cache::lock($key, 60)->block(20, $callback);
    }
}
