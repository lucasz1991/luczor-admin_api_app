<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMemoryProjection;
use App\Models\ApiKey;
use App\Models\MemoryLink;
use App\Models\MemoryProjectionOutbox;
use App\Models\MemoryWriteEvent;
use App\Models\User;
use App\Services\Cognee\CogneeClient;
use App\Services\MemoryOrchestrator;
use App\Services\MemoryProjectionService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Exercises Luczor's real production memory path with disposable synthetic data.
 *
 * The provider projection is processed synchronously in this process while the
 * matching queue job is faked. This prevents Horizon races and makes provider
 * cleanup a hard success condition instead of an eventually-consistent guess.
 */
final class MemoryProductionSmoke extends Command
{
    protected $signature = 'luczor:memory-production-smoke
        {--force : Confirm the temporary production write and provider projection}
        {--improve : Include one isolated real Cognee Improve/Memify run}
        {--timeout=1800 : Maximum seconds for projection, recall and cleanup}';

    protected $description = 'Run a synthetic Remember-Cognify-Recall-Forget production acceptance test, optionally with Improve';

    private string $stage = 'preflight';

    public function handle(
        CogneeClient $cognee,
        MemoryProjectionService $projections,
        MemoryOrchestrator $memory,
        HttpKernel $http,
    ): int {
        if (! $this->option('force')) {
            $this->components->error('Refusing a production write without --force.');

            return self::FAILURE;
        }

        $timeout = (int) $this->option('timeout');
        if ($timeout < 180 || $timeout > 3600) {
            $this->components->error('The timeout must be between 180 and 3600 seconds.');

            return self::FAILURE;
        }
        if (! $cognee->enabled()) {
            $this->components->error('Cognee is not configured.');

            return self::FAILURE;
        }

        try {
            $cognee->probeRuntime(true);
        } catch (Throwable) {
            $this->components->error('Cognee authentication or runtime identity failed.');

            return self::FAILURE;
        }

        $deadline = microtime(true) + $timeout;
        $suffix = strtolower((string) Str::ulid());
        $externalId = 'luczor-memory-smoke-'.$suffix;
        $projectId = 'luczor-project-smoke-'.$suffix;
        $marker = 'Luczor synthetischer Erinnerungsmarker '.$suffix.' mit violettem Nordlicht.';
        $user = null;
        $apiKey = null;
        $plainToken = '';
        $linkId = null;
        $dataset = null;
        $contentHash = null;
        $dataId = null;
        $passed = false;
        $cleanupPassed = true;

        // Intercept only this command process. Web workers and Horizon keep
        // their normal queue configuration; the synthetic outbox is driven
        // synchronously below to guarantee a race-free exact cleanup.
        Queue::fake([ProcessMemoryProjection::class]);

        try {
            $this->stage = 'temporary_identity';
            $user = User::create([
                'name' => 'Luczor Memory Smoke',
                'email' => 'luczor-memory-smoke-'.$suffix.'@example.invalid',
                'email_verified_at' => now(),
                'password' => Str::random(64),
                'role' => 'customer',
                'status' => true,
            ]);
            $minted = ApiKey::mint([
                'user_id' => $user->id,
                'name' => 'Temporary memory production smoke',
                'abilities' => ['brain.read', 'brain.write'],
                'active' => true,
                'expires_at' => now()->addMinutes(60),
                'device_id' => 'memory-smoke-'.$suffix,
                'device_name' => 'Memory production smoke',
            ]);
            $apiKey = $minted['model'];
            $plainToken = $minted['plain'];

            $this->stage = 'remember';
            $remember = $this->api($http, $plainToken, 'POST', '/api/v1/memory/remember', [
                'content' => $marker,
                'scope' => 'project',
                'project_id' => $projectId,
                'external_id' => $externalId,
                'write_id' => 'luczor-memory-smoke-write-'.$suffix,
                'client_id' => 'memory-smoke-'.$suffix,
                'type' => 'note',
                'visibility' => 'syncable',
                'write_intent' => 'confirmed',
                'retention' => 'durable',
                'sensitivity' => 'normal',
                'source_type' => 'system',
                'expires_at' => now()->addHours(2)->toIso8601String(),
            ]);
            $this->requireStatus($remember, 201, 'Remember');
            if (($remember['body']['decision'] ?? null) !== 'accepted'
                || ($remember['body']['persisted'] ?? null) !== true
                || ! in_array('sql', (array) ($remember['body']['targets'] ?? []), true)
                || ! in_array('cognee_outbox', (array) ($remember['body']['targets'] ?? []), true)) {
                throw new RuntimeException('Remember did not choose SQL plus Cognee outbox.');
            }

            $linkId = (int) ($remember['body']['memory_link_id'] ?? 0);
            $link = MemoryLink::query()->find($linkId);
            if (! $link || $link->user_id !== $user->id) {
                throw new RuntimeException('Remember returned no owned canonical memory.');
            }
            $dataset = (string) $link->dataset;
            $contentHash = (string) $link->content_hash;

            $this->stage = 'projection';
            $upsert = MemoryProjectionOutbox::query()
                ->where('memory_link_id', $linkId)
                ->where('action', 'upsert')
                ->orderByDesc('id')
                ->firstOrFail();
            $this->driveProjection($upsert->id, $projections, $deadline);
            $link = MemoryLink::query()->findOrFail($linkId)->fresh();
            $dataId = trim((string) $link->cognee_memory_id);
            if ($link->projection_status !== 'ready' || ! $this->isUuid($dataId)) {
                throw new RuntimeException('Cognee projection did not become ready with an exact Data UUID.');
            }

            $this->stage = 'semantic_recall';
            $semantic = $this->recallUntil(
                $http,
                $plainToken,
                $projectId,
                'violettes Nordlicht '.$suffix,
                $externalId,
                'cognee_revalidated',
                $deadline,
            );
            if (! $semantic) {
                throw new RuntimeException('Semantic recall was not revalidated against canonical SQL.');
            }

            if ($this->option('improve')) {
                // This opt-in affects only the smoke command process. It lets an
                // operator prove the real pinned Cognee contract before the
                // persistent production feature flag is enabled for API users.
                config(['luczor.cognee.improve_enabled' => true]);
                $this->stage = 'improve_schedule';
                $improve = $this->api($http, $plainToken, 'POST', '/api/v1/memory/improve', [
                    'scope' => 'project',
                    'project_id' => $projectId,
                ]);
                $this->requireStatus($improve, 200, 'Improve');
                if (($improve['body']['scheduled'] ?? null) !== true) {
                    throw new RuntimeException('Improve did not schedule the synthetic ready dataset.');
                }

                $this->stage = 'improve_projection';
                $improveOutbox = MemoryProjectionOutbox::query()
                    ->where('dataset', $dataset)
                    ->where('action', 'improve')
                    ->orderByDesc('id')
                    ->firstOrFail();
                $this->driveProjection($improveOutbox->id, $projections, $deadline);
                $improveOutbox->refresh();
                $improvePayload = $improveOutbox->payload ?? [];
                if ($improveOutbox->status !== 'done'
                    || ($improvePayload['phase'] ?? null) !== 'improve_polling'
                    || ! $this->isUuid((string) ($improvePayload['pipeline_run_id'] ?? ''))
                    || ! $this->isUuid((string) ($improvePayload['cognee_dataset_id'] ?? ''))) {
                    throw new RuntimeException('Improve did not finish through one exact guarded background run.');
                }
            }

            $this->stage = 'dlp_sql_fallback';
            $fallback = $this->api($http, $plainToken, 'POST', '/api/v1/memory/recall', [
                'query' => 'Erinnerungsmarker '.$suffix.' smoke@example.invalid',
                'scope' => 'project',
                'project_id' => $projectId,
                'limit' => 6,
            ]);
            $this->requireStatus($fallback, 200, 'DLP SQL recall');
            if (! $this->containsRecall($fallback['body'], $externalId, 'sql')) {
                throw new RuntimeException('DLP query did not fall back to canonical SQL recall.');
            }

            $this->stage = 'forget';
            $forget = $this->api($http, $plainToken, 'POST', '/api/v1/memory/forget', [
                'external_id' => $externalId,
                'scope' => 'project',
                'project_id' => $projectId,
            ]);
            $this->requireStatus($forget, 200, 'Forget');
            if (($forget['body']['forgotten'] ?? null) !== true) {
                throw new RuntimeException('Forget did not remove the canonical memory.');
            }

            $immediate = $this->api($http, $plainToken, 'POST', '/api/v1/memory/recall', [
                'query' => 'violettes Nordlicht '.$suffix,
                'scope' => 'project',
                'project_id' => $projectId,
                'limit' => 6,
            ]);
            $this->requireStatus($immediate, 200, 'Recall after Forget');
            if ($this->containsRecall($immediate['body'], $externalId)) {
                throw new RuntimeException('Forgotten memory remained readable from SQL.');
            }

            $this->stage = 'provider_cleanup';
            $delete = $this->findDeleteOutbox($dataset, $dataId);
            // Forget can reopen the completed Upsert as a source-ineligible
            // recovery turn before its exact Delete may claim the dataset.
            // Drain this unique synthetic dataset in row order so the smoke
            // never depends on the scheduler's five-minute abandoned-queue
            // recovery window.
            $this->driveDatasetProjections($dataset, $projections, $deadline);
            $delete->refresh();
            if (trim((string) (($delete->payload ?? [])['exact_forget_ack_at'] ?? '')) === '') {
                throw new RuntimeException('Cognee did not durably acknowledge exact Forget.');
            }
            $providerData = $cognee->findData($dataset, $linkId, $contentHash, true);
            if ($providerData['data_ids'] !== []) {
                throw new RuntimeException('Cognee still returned synthetic provider data after Forget.');
            }

            $secondForget = $this->api($http, $plainToken, 'POST', '/api/v1/memory/forget', [
                'external_id' => $externalId,
                'scope' => 'project',
                'project_id' => $projectId,
            ]);
            $this->requireStatus($secondForget, 200, 'Idempotent Forget');
            if (($secondForget['body']['already_absent'] ?? null) !== true) {
                throw new RuntimeException('Second Forget was not idempotently absent.');
            }

            $passed = true;
        } catch (Throwable $error) {
            Log::error('Memory production smoke failed', [
                'stage' => $this->stage,
                'exception_class' => $error::class,
            ]);
            $this->components->error('Memory production smoke failed during '.$this->stage.'.');
        } finally {
            $plainToken = '';
            try {
                $this->cleanup(
                    $user,
                    $apiKey,
                    $memory,
                    $projections,
                    $cognee,
                    $externalId,
                    $projectId,
                    $linkId,
                    $dataset,
                    $contentHash,
                    $marker,
                    max($deadline, microtime(true) + 300),
                );
            } catch (Throwable $cleanupError) {
                $cleanupPassed = false;
                Log::critical('Memory production smoke cleanup failed', [
                    'stage' => $this->stage,
                    'exception_class' => $cleanupError::class,
                ]);
                $this->components->error('Synthetic memory cleanup could not be proven complete.');
            }
        }

        if (! $passed || ! $cleanupPassed) {
            return self::FAILURE;
        }

        $steps = $this->option('improve')
            ? 'Remember, Cognify, Improve, semantic recall, SQL fallback, Forget and provider cleanup.'
            : 'Remember, Cognify, semantic recall, SQL fallback, Forget and provider cleanup.';
        $this->components->info('Memory production smoke passed: '.$steps);

        return self::SUCCESS;
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function api(HttpKernel $kernel, string $token, string $method, string $path, array $payload): array
    {
        $request = Request::create(
            $path,
            $method,
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_API_KEY' => $token,
                'HTTP_IDEMPOTENCY_KEY' => (string) ($payload['write_id'] ?? ''),
                'REMOTE_ADDR' => '127.0.0.1',
            ],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $response = $kernel->handle($request);
        try {
            $body = json_decode((string) $response->getContent(), true);

            return [
                'status' => $response->getStatusCode(),
                'body' => is_array($body) ? $body : [],
            ];
        } finally {
            $kernel->terminate($request, $response);
        }
    }

    /** @param array{status:int,body:array<string,mixed>} $response */
    private function requireStatus(array $response, int $expected, string $operation): void
    {
        if ($response['status'] !== $expected) {
            throw new RuntimeException($operation.' returned HTTP '.$response['status'].'.');
        }
    }

    private function driveProjection(
        int $outboxId,
        MemoryProjectionService $projections,
        float $deadline,
    ): MemoryProjectionOutbox {
        $lastFailureCount = -1;
        while (microtime(true) < $deadline) {
            $row = MemoryProjectionOutbox::query()->findOrFail($outboxId);
            if ($row->status === 'done') {
                return $row;
            }
            if ($row->status === 'failed' && (int) $row->attempts >= 5) {
                throw new RuntimeException('Projection exhausted its retry budget.');
            }
            if ($row->next_attempt_at?->isFuture()) {
                usleep(500_000);

                continue;
            }
            if ($row->status === 'processing' && $row->updated_at->gt(now()->subSeconds(90))) {
                usleep(500_000);

                continue;
            }

            try {
                $projections->process($row->id);
            } catch (Throwable) {
                $row->refresh();
                if ((int) $row->attempts === $lastFailureCount) {
                    usleep(500_000);
                }
                $lastFailureCount = (int) $row->attempts;
            }
        }

        throw new RuntimeException('Projection did not finish within the smoke-test timeout.');
    }

    private function recallUntil(
        HttpKernel $http,
        string $token,
        string $projectId,
        string $query,
        string $externalId,
        string $source,
        float $deadline,
    ): bool {
        $attempts = 0;
        while (microtime(true) < $deadline && $attempts < 5) {
            $response = $this->api($http, $token, 'POST', '/api/v1/memory/recall', [
                'query' => $query,
                'scope' => 'project',
                'project_id' => $projectId,
                'limit' => 6,
            ]);
            $this->requireStatus($response, 200, 'Semantic recall');
            if ($this->containsRecall($response['body'], $externalId, $source)) {
                return true;
            }
            $attempts++;
            sleep(2);
        }

        return false;
    }

    /** @param array<string,mixed> $body */
    private function containsRecall(array $body, string $externalId, ?string $source = null): bool
    {
        foreach ((array) ($body['data'] ?? []) as $item) {
            if (! is_array($item) || ($item['id'] ?? null) !== $externalId) {
                continue;
            }
            if ($source === null || ($item['source'] ?? null) === $source) {
                return true;
            }
        }

        return false;
    }

    private function findDeleteOutbox(string $dataset, string $dataId): MemoryProjectionOutbox
    {
        $row = MemoryProjectionOutbox::query()
            ->where('dataset', $dataset)
            ->where('action', 'delete')
            ->orderByDesc('id')
            ->get()
            ->first(fn (MemoryProjectionOutbox $candidate): bool => hash_equals(
                $dataId,
                trim((string) (($candidate->payload ?? [])['cognee_memory_id'] ?? '')),
            ));

        if (! $row) {
            throw new RuntimeException('Forget created no exact provider-delete outbox.');
        }

        return $row;
    }

    private function cleanup(
        ?User $user,
        ?ApiKey $apiKey,
        MemoryOrchestrator $memory,
        MemoryProjectionService $projections,
        CogneeClient $cognee,
        string $externalId,
        string $projectId,
        ?int $linkId,
        ?string $dataset,
        ?string $contentHash,
        string $marker,
        float $deadline,
    ): void {
        $this->stage = 'final_cleanup';
        $liveLink = $linkId ? MemoryLink::query()->find($linkId) : null;
        $dataset ??= $liveLink?->dataset;
        $contentHash ??= $liveLink?->content_hash;
        $providerCleanupFailed = false;

        try {
            if ($user?->exists && $liveLink) {
                $memory->forget('project', $externalId, [
                    'user_id' => $user->id,
                    'tenant_id' => $user->tenant_id,
                    'project_id' => $projectId,
                ]);
            }

            if ($dataset) {
                // A failure can happen after Add but before the command receives a
                // Data UUID. Drain the unique synthetic dataset until the Upsert
                // has recovered any ambiguous Add and every compensating Delete is
                // exact and terminal.
                $this->driveDatasetProjections($dataset, $projections, $deadline);

                if ($linkId && $contentHash) {
                    $providerData = $cognee->findData($dataset, $linkId, $contentHash, true);
                    if ($providerData['data_ids'] !== []) {
                        throw new RuntimeException('Synthetic provider data survived cleanup.');
                    }
                }
            }
        } catch (Throwable) {
            // Provider cleanup remains durable in the content-free outbox.
            // Never retain a temporary account or API key merely because the
            // projection is unavailable; the command still fails below.
            $providerCleanupFailed = true;
        }

        $userId = $user?->id;
        $apiKey?->delete();
        if ($user?->exists) {
            $user->delete();
        }
        if ($dataset !== null) {
            // Account erasure deliberately reopens an otherwise completed
            // Upsert when its provider launch was still in a recoverable
            // phase. Queue jobs are faked by this command, so finish that
            // final content-free compensation synchronously as well instead
            // of leaving it for the five-minute abandoned-queue recovery.
            $this->driveDatasetProjections($dataset, $projections, $deadline);
        }
        if ($userId !== null
            && (User::query()->whereKey($userId)->exists()
                || ApiKey::query()->where('user_id', $userId)->exists()
                || MemoryLink::query()->where('user_id', $userId)->exists()
                || MemoryWriteEvent::query()->where('user_id', $userId)->exists()
                || MemoryProjectionOutbox::query()->where('user_id', $userId)->exists())) {
            throw new RuntimeException('Synthetic account attribution survived cleanup.');
        }
        if ($dataset !== null) {
            if (MemoryProjectionOutbox::query()
                ->where('dataset', $dataset)
                ->where('status', '!=', 'done')
                ->exists()) {
                throw new RuntimeException('Synthetic projection cleanup remained non-terminal.');
            }
            $containsContent = MemoryProjectionOutbox::query()
                ->where('dataset', $dataset)
                ->get(['payload'])
                ->contains(fn (MemoryProjectionOutbox $row): bool => str_contains(
                    json_encode($row->payload ?? [], JSON_THROW_ON_ERROR),
                    $marker,
                ));
            if ($containsContent) {
                throw new RuntimeException('Synthetic content survived in the projection outbox.');
            }
        }
        if ($providerCleanupFailed) {
            throw new RuntimeException('Synthetic provider cleanup remains pending.');
        }
    }

    private function driveDatasetProjections(
        string $dataset,
        MemoryProjectionService $projections,
        float $deadline,
    ): void {
        do {
            $pending = MemoryProjectionOutbox::query()
                ->where('dataset', $dataset)
                ->where('status', '!=', 'done')
                ->orderBy('id')
                ->get();
            foreach ($pending as $outbox) {
                $this->driveProjection($outbox->id, $projections, $deadline);
            }
        } while ($pending->isNotEmpty()
            && MemoryProjectionOutbox::query()
                ->where('dataset', $dataset)
                ->where('status', '!=', 'done')
                ->exists());
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
