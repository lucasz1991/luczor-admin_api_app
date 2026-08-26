<?php

namespace App\Services;

use App\Exceptions\MemoryVersionConflictException;
use App\Jobs\ProcessMemoryProjection;
use App\Models\MemoryLink;
use App\Models\MemoryProjectionOutbox;
use App\Models\MemoryWriteEvent;
use App\Models\User;
use App\Services\Cognee\CogneeClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Canonical memory store and retrieval adapter.
 *
 * SQL is the system of record. Cognee is a rebuildable semantic projection and
 * may only influence ranking after a hit has been revalidated against SQL.
 */
class LuczorMemoryService
{
    private const MAX_LEXICAL_TERMS = 24;

    public function __construct(private CogneeClient $cognee) {}

    public function cogneeEnabled(): bool
    {
        return $this->cognee->enabled();
    }

    /** Namespaced dataset key. Dataset boundaries are authorization boundaries. */
    public function datasetFor(string $scope, array $ids = []): string
    {
        if ($scope === 'global') {
            return 'global:curated';
        }

        $keys = $this->memoryNamespaceKeys();

        return $this->opaqueDatasetFor($scope, $ids, $keys[0]);
    }

    /** @return array<int,string> */
    private function datasetsFor(string $scope, array $ids): array
    {
        $opaque = $scope === 'global'
            ? ['global:curated']
            : array_map(
                fn (string $key): string => $this->opaqueDatasetFor($scope, $ids, $key),
                $this->memoryNamespaceKeys(),
            );
        $user = $ids['user_id'] ?? 'server';
        $tenant = $ids['tenant_id'] ?? 'personal';
        $versionOne = match ($scope) {
            'project' => "tenant:{$tenant}:user:{$user}:project:".($ids['project_id'] ?? 'default'),
            'workspace' => "tenant:{$tenant}:workspace",
            'skill' => "tenant:{$tenant}:user:{$user}:skills",
            'agent' => "tenant:{$tenant}:user:{$user}:agent:".($ids['agent_id'] ?? 'default').':runs',
            'session' => "tenant:{$tenant}:user:{$user}:session:".($ids['session_id'] ?? 'default'),
            'global' => 'global:curated',
            default => "tenant:{$tenant}:user:{$user}:private",
        };
        $legacy = match ($scope) {
            'project' => "user:{$user}:projects:".($ids['project_id'] ?? 'default'),
            'skill' => "user:{$user}:skills",
            'agent' => 'agent:'.($ids['agent_id'] ?? 'default').':runs',
            'global' => 'global:knowledge',
            'workspace', 'session' => null,
            default => "user:{$user}:private",
        };

        return array_values(array_unique(array_filter(
            [...$opaque, $versionOne, $legacy],
            fn (?string $dataset) => $dataset !== null,
        )));
    }

    /** @param array<string,mixed> $ids */
    private function opaqueDatasetFor(string $scope, array $ids, string $key): string
    {
        $scopeKey = $this->normalizedScope($scope);
        $opaqueId = hash_hmac('sha256', json_encode([
            'version' => 2,
            'scope' => $scopeKey,
            'identity' => $this->logicalNamespaceIdentity($scopeKey, $ids),
        ], JSON_THROW_ON_ERROR), $key);

        return "luczor:v2:{$scopeKey}:{$opaqueId}";
    }

    /** @return array<string,string|int|null> */
    private function logicalNamespaceIdentity(string $scope, array $ids): array
    {
        return match ($scope) {
            'workspace' => ['tenant_id' => $ids['tenant_id'] ?? 'personal'],
            'project' => [
                'tenant_id' => $ids['tenant_id'] ?? 'personal',
                'user_id' => $ids['user_id'] ?? 'server',
                'project_id' => $ids['project_id'] ?? 'default',
            ],
            'agent' => [
                'tenant_id' => $ids['tenant_id'] ?? 'personal',
                'user_id' => $ids['user_id'] ?? 'server',
                'agent_id' => $ids['agent_id'] ?? 'default',
            ],
            'session' => [
                'tenant_id' => $ids['tenant_id'] ?? 'personal',
                'user_id' => $ids['user_id'] ?? 'server',
                'session_id' => $ids['session_id'] ?? 'default',
            ],
            default => [
                'tenant_id' => $ids['tenant_id'] ?? 'personal',
                'user_id' => $ids['user_id'] ?? 'server',
            ],
        };
    }

    private function normalizedScope(string $scope): string
    {
        return match ($scope) {
            'project', 'workspace', 'skill', 'agent', 'session', 'device', 'private' => $scope,
            default => 'private',
        };
    }

    /** @return non-empty-list<string> */
    private function memoryNamespaceKeys(): array
    {
        $primary = trim((string) config('luczor.memory.namespace_key', ''));
        if ($primary === '') {
            throw new RuntimeException('LUCZOR_MEMORY_NAMESPACE_KEY must be configured independently of APP_KEY.');
        }
        $previous = config('luczor.memory.previous_namespace_keys', []);
        $previous = is_array($previous) ? $previous : [];
        $keys = array_values(array_unique(array_filter(
            [$primary, ...array_map(static fn (mixed $key): string => trim((string) $key), $previous)],
            static fn (string $key): bool => $key !== '',
        )));

        return $keys;
    }

    /** @param array<string,mixed> $data */
    public function remember(array $data): MemoryLink
    {
        if (MemoryDlp::containsSecretInMemoryPayload($data)
            || MemoryDlp::containsLocalOnlySourceInMemoryPayload($data)) {
            throw ValidationException::withMessages([
                'memory' => 'Memory payload failed the DLP policy and was not persisted.',
            ]);
        }

        $resolvedIdempotencyKey = null;
        $resolvedContentHash = null;
        $resolvedStatus = null;
        $resolvedWriteFingerprint = null;
        $resolvedLegacyIdempotencyKey = null;
        $resolvedLegacyWriteFingerprint = null;

        try {
            return DB::transaction(function () use (
                $data,
                &$resolvedIdempotencyKey,
                &$resolvedContentHash,
                &$resolvedStatus,
                &$resolvedWriteFingerprint,
                &$resolvedLegacyIdempotencyKey,
                &$resolvedLegacyWriteFingerprint,
            ) {
                $scope = (string) ($data['scope'] ?? 'project');
                $ids = [
                    'tenant_id' => $data['tenant_id'] ?? null,
                    'user_id' => $data['user_id'] ?? null,
                    'project_id' => $data['project_id'] ?? null,
                    'agent_id' => $data['agent_id'] ?? null,
                    'session_id' => $data['session_id'] ?? null,
                ];
                $datasets = $this->datasetsFor($scope, $ids);
                $dataset = $datasets[0];
                $content = trim((string) ($data['content'] ?? ''));
                $hash = hash('sha256', preg_replace('/\s+/u', ' ', $content) ?? $content);
                $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
                $providedExternalId = trim((string) ($data['external_id'] ?? ''));
                $requestedExternalId = mb_substr(
                    $providedExternalId !== '' ? $providedExternalId : (string) Str::uuid(),
                    0,
                    190,
                );
                $clientId = $data['client_id'] ?? null;
                $userId = $data['user_id'] ?? null;
                if ($userId !== null) {
                    // Serialize account deletion and memory creation on the
                    // account row. The delete observer holds this same lock,
                    // so no write can commit after erasure has started.
                    $owner = User::query()->whereKey((int) $userId)->lockForUpdate()->first();
                    if (! $owner || ! $owner->isActive()) {
                        throw ValidationException::withMessages([
                            'user_id' => 'Memory owner is missing or inactive.',
                        ]);
                    }
                }
                // Global memory is one curated logical namespace. The actor is
                // retained on the row for audit, but must not partition locks,
                // idempotency or version selection between administrators.
                $sharedScope = $this->isSharedScope($scope);
                $identityUserId = $sharedScope ? null : $userId;
                $identityClientId = $sharedScope ? null : $clientId;
                $memoryKey = trim((string) ($data['memory_key'] ?? ($meta['memory_key'] ?? ($data['feature_key'] ?? ''))));
                if ($memoryKey !== '') {
                    $meta['memory_key'] = $memoryKey;
                }
                $status = (string) ($data['status'] ?? 'active');
                $retention = (string) ($data['retention'] ?? 'durable');
                $writeId = mb_substr(trim((string) ($data['write_id'] ?? '')), 0, 190);
                // Keep the write identity stable across opaque dataset-key
                // rotation. The independent ledger HMAC prevents offline
                // enumeration of durable write and Forget tombstones.
                $idempotencyIdentity = [
                    'user_id' => $identityUserId,
                    'scope' => $this->normalizedScope($scope),
                    'namespace' => $this->logicalNamespaceIdentity($this->normalizedScope($scope), $ids),
                ];
                $idempotencyDigest = hash('sha256', json_encode($writeId !== ''
                    // A durable write UUID belongs to the user/dataset, not a
                    // device installation. A delayed multi-device replay must
                    // therefore hit the same tombstone after Forget.
                    ? $idempotencyIdentity + ['write_id' => $writeId]
                    : $idempotencyIdentity + [
                        'client_id' => $identityClientId,
                        'source_external_id' => $requestedExternalId,
                        'content_hash' => $hash,
                    ], JSON_THROW_ON_ERROR));
                $idempotencyKey = MemoryLedgerIdentity::idempotency($idempotencyDigest);
                $resolvedIdempotencyKey = $idempotencyKey;
                $resolvedLegacyIdempotencyKey = $idempotencyDigest;
                $resolvedContentHash = $hash;
                $resolvedStatus = $status;
                $fingerprintProvenance = $data['provenance'] ?? null;
                if (is_array($fingerprintProvenance)) {
                    // The orchestrator refreshes this server timestamp on every
                    // HTTP retry; it is not part of the client write intent.
                    unset($fingerprintProvenance['captured_at']);
                }
                $writeFingerprintDigest = hash('sha256', json_encode($this->canonicalFingerprintValue([
                    // A generated storage identity must not make an otherwise
                    // identical write-event retry look like a different request.
                    'external_id' => $providedExternalId !== '' ? $requestedExternalId : '__not_supplied__',
                    'memory_key' => $memoryKey,
                    'feature_key' => $data['feature_key'] ?? null,
                    'content_hash' => $hash,
                    'status' => $status,
                    'retention' => $retention,
                    'visibility' => $data['visibility'] ?? 'syncable',
                    'sensitivity' => $data['sensitivity'] ?? 'normal',
                    'type' => $data['type'] ?? 'note',
                    'source_type' => $data['source_type'] ?? ($data['source'] ?? 'user'),
                    'source_ref' => $data['source_ref'] ?? null,
                    'importance' => (float) ($data['importance'] ?? 0.5),
                    'confidence' => (float) ($data['confidence'] ?? 0.5),
                    'tenant_id' => $data['tenant_id'] ?? null,
                    'project_id' => $data['project_id'] ?? null,
                    'project_ref_id' => $data['project_ref_id'] ?? null,
                    'agent_id' => $data['agent_id'] ?? null,
                    'session_id' => $data['session_id'] ?? null,
                    'meta' => $meta,
                    'provenance' => $fingerprintProvenance,
                    'observed_at' => array_key_exists('observed_at', $data)
                        ? $data['observed_at']
                        : '__not_supplied__',
                    'write_reason' => $data['write_reason'] ?? null,
                    'expected_previous_id' => array_key_exists('expected_previous_id', $data)
                        ? $data['expected_previous_id']
                        : '__not_supplied__',
                    'valid_from' => array_key_exists('valid_from', $data)
                        ? $data['valid_from']
                        : '__not_supplied__',
                    'valid_until' => array_key_exists('valid_until', $data)
                        ? $data['valid_until']
                        : '__not_supplied__',
                    'expires_at' => array_key_exists('expires_at', $data)
                        ? $data['expires_at']
                        : '__not_supplied__',
                ]), JSON_THROW_ON_ERROR));
                $writeFingerprint = MemoryLedgerIdentity::fingerprint($writeFingerprintDigest);
                $resolvedWriteFingerprint = $writeFingerprint;
                $resolvedLegacyWriteFingerprint = $writeFingerprintDigest;

                $this->lockMemoryAliasFamily(
                    $datasets,
                    $identityUserId,
                    $identityClientId,
                    $requestedExternalId,
                    $memoryKey,
                );

                $events = MemoryWriteEvent::query()
                    ->whereIn('idempotency_key', [$idempotencyKey, $idempotencyDigest])
                    ->orderByDesc('ledger_identity_version')
                    ->lockForUpdate()
                    ->get();
                if ($events->isNotEmpty()) {
                    foreach ($events as $knownEvent) {
                        if (! $this->writeFingerprintMatches(
                            (string) $knownEvent->write_fingerprint,
                            $writeFingerprint,
                            $writeFingerprintDigest,
                        )) {
                            throw new ConflictHttpException('The memory write ID was already used for a different request.');
                        }
                    }
                    if ($events->contains(fn (MemoryWriteEvent $knownEvent): bool => $knownEvent->state === 'forgotten')) {
                        throw new ConflictHttpException('The memory write event belongs to a forgotten memory.');
                    }
                    $event = $events->first(
                        fn (MemoryWriteEvent $knownEvent): bool => $knownEvent->memory_link_id !== null
                    ) ?? $events->first();

                    $retry = $event->memory_link_id
                        ? MemoryLink::query()->whereKey($event->memory_link_id)->lockForUpdate()->first()
                        : null;
                    if (! $retry || ! $this->isCurrentIdempotentRetry($retry, $status)) {
                        throw new ConflictHttpException('The memory write event is no longer the active version.');
                    }

                    return $retry;
                }

                $retry = MemoryLink::query()
                    ->whereIn('idempotency_key', [$idempotencyKey, $idempotencyDigest])
                    ->lockForUpdate()
                    ->first();
                if ($retry) {
                    if (! $retry->write_fingerprint
                        || ! $this->writeFingerprintMatches(
                            (string) $retry->write_fingerprint,
                            $writeFingerprint,
                            $writeFingerprintDigest,
                        )) {
                        throw new ConflictHttpException('The memory write ID was already used for a different request.');
                    }
                    if (! $this->isCurrentIdempotentRetry($retry, $status)) {
                        throw new ConflictHttpException('The memory write event was committed previously but is no longer the active version.');
                    }

                    $this->recordWriteEvent($idempotencyKey, $writeFingerprint, $retry);

                    return $retry;
                }

                $existingQuery = MemoryLink::query()->whereIn('dataset', $datasets);
                if (! $sharedScope) {
                    $existingQuery->where('user_id', $userId)->where('client_id', $clientId);
                }
                $existing = $existingQuery
                    ->where(function (Builder $query) use ($requestedExternalId, $memoryKey) {
                        $query->where('external_id', $requestedExternalId)
                            ->orWhere('meta->source_external_id', $requestedExternalId);
                        if ($memoryKey !== '') {
                            $query->orWhere('feature_key', $memoryKey)
                                ->orWhere('meta->memory_key', $memoryKey);
                        }
                    })
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($existing
                    && $this->isCurrentIdempotentRetry($existing, $status)
                    && hash_equals((string) $existing->content_hash, $hash)) {
                    if (! $existing->idempotency_key || ! $existing->write_fingerprint) {
                        $existing->update([
                            'idempotency_key' => $idempotencyKey,
                            'write_fingerprint' => $writeFingerprint,
                            'ledger_identity_version' => 2,
                        ]);
                    }

                    $this->recordWriteEvent($idempotencyKey, $writeFingerprint, $existing);

                    return $existing;
                }

                /** @var Collection<int,MemoryLink> $supersededFamily */
                $supersededFamily = new Collection;
                if ($memoryKey !== '') {
                    $supersededQuery = MemoryLink::query()->whereIn('dataset', $datasets);
                    if (! $sharedScope) {
                        $supersededQuery->where('user_id', $userId);
                    }
                    $supersededFamily = $supersededQuery
                        ->where('status', 'active')
                        ->where(function (Builder $query) use ($memoryKey) {
                            $query->where('feature_key', $memoryKey)
                                ->orWhere('meta->memory_key', $memoryKey);
                        })
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->get();
                }
                if ($supersededFamily->isEmpty()) {
                    $supersededQuery = MemoryLink::query()->whereIn('dataset', $datasets);
                    if (! $sharedScope) {
                        $supersededQuery->where('user_id', $userId)->where('client_id', $clientId);
                    }
                    $supersededFamily = $supersededQuery
                        ->where('status', 'active')
                        ->where(function (Builder $query) use ($requestedExternalId) {
                            $query->where('external_id', $requestedExternalId)
                                ->orWhere('meta->source_external_id', $requestedExternalId);
                        })
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->get();
                }
                $superseded = $supersededFamily->first();

                if (array_key_exists('expected_previous_id', $data)) {
                    $expectedPreviousId = $data['expected_previous_id'] === null
                        ? null
                        : (int) $data['expected_previous_id'];
                    if ($superseded?->id !== $expectedPreviousId) {
                        throw new MemoryVersionConflictException($superseded?->id);
                    }
                }

                $projectionRequired = (bool) ($data['project_to_cognee'] ?? false) && $status === 'active';
                $projectionStatus = $this->projectionStatus(
                    $projectionRequired,
                    $data['valid_from'] ?? null,
                    $data['valid_until'] ?? null,
                    $data['expires_at'] ?? null,
                    (bool) ($data['defer_cognee_projection'] ?? false) && $status === 'active',
                );
                $versionSuffix = '.v.'.Str::lower((string) Str::ulid());
                $externalId = $existing
                    ? mb_substr($requestedExternalId, 0, 190 - strlen($versionSuffix)).$versionSuffix
                    : $requestedExternalId;
                $meta['source_external_id'] = $requestedExternalId;

                $link = MemoryLink::create([
                    'user_id' => $userId,
                    'tenant_id' => $data['tenant_id'] ?? null,
                    'client_id' => $clientId,
                    'external_id' => $externalId,
                    'scope' => $scope,
                    'dataset' => $dataset,
                    'project_id' => $data['project_id'] ?? null,
                    'project_ref_id' => $data['project_ref_id'] ?? null,
                    'feature_key' => $data['feature_key'] ?? null,
                    'session_id' => $data['session_id'] ?? null,
                    'type' => $data['type'] ?? 'note',
                    'visibility' => $data['visibility'] ?? 'syncable',
                    'staleness' => 'fresh',
                    'status' => $status,
                    'retention' => $retention,
                    'sensitivity' => $data['sensitivity'] ?? 'normal',
                    'importance' => (float) ($data['importance'] ?? 0.5),
                    'confidence' => (float) ($data['confidence'] ?? 0.5),
                    'summary' => mb_substr($content, 0, 8000),
                    'content_hash' => $hash,
                    'idempotency_key' => $idempotencyKey,
                    'write_fingerprint' => $writeFingerprint,
                    'ledger_identity_version' => 2,
                    'source_type' => $data['source_type'] ?? ($data['source'] ?? 'user'),
                    'source_ref' => $data['source_ref'] ?? null,
                    'provenance' => $data['provenance'] ?? null,
                    'observed_at' => $data['observed_at'] ?? now(),
                    'valid_from' => $data['valid_from'] ?? now(),
                    'valid_until' => $data['valid_until'] ?? null,
                    'recorded_at' => now(),
                    'expires_at' => $data['expires_at'] ?? ($retention === 'session' ? now()->addDay() : null),
                    'supersedes_id' => $superseded?->id,
                    'write_reason' => $data['write_reason'] ?? null,
                    'projection_status' => $projectionStatus,
                    'meta' => $meta,
                ]);
                $this->recordWriteEvent($idempotencyKey, $writeFingerprint, $link);

                if ($status === 'active') {
                    foreach ($supersededFamily as $oldVersion) {
                        if ($oldVersion->id === $link->id) {
                            continue;
                        }
                        $oldVersion->update(['status' => 'superseded', 'staleness' => 'stale']);
                        $this->enqueueDelete($oldVersion);
                    }
                }

                if ($projectionStatus === 'pending') {
                    $this->enqueue('upsert', $link->dataset, $link->id, $link->user_id, [
                        'content_hash' => $hash,
                    ], $hash);
                }

                return $link;
            });
        } catch (QueryException $error) {
            // Concurrent delivery of the same idempotent write may race up to
            // the unique constraint. After the losing transaction rolls back,
            // resolve it to the winner instead of returning a transient 500.
            $resolvedKeys = array_values(array_filter([
                $resolvedIdempotencyKey,
                $resolvedLegacyIdempotencyKey,
            ], fn (mixed $key): bool => is_string($key) && $key !== ''));
            $events = $resolvedKeys === []
                ? collect()
                : MemoryWriteEvent::query()->whereIn('idempotency_key', $resolvedKeys)->get();
            if ($events->isNotEmpty()) {
                if ($events->contains(fn (MemoryWriteEvent $knownEvent): bool => $knownEvent->state === 'forgotten')) {
                    throw new ConflictHttpException('The memory write event belongs to a forgotten memory.', $error);
                }
                $event = $events->first(
                    fn (MemoryWriteEvent $knownEvent): bool => $knownEvent->memory_link_id !== null
                ) ?? $events->first();
                $retry = $event->memory_link_id ? MemoryLink::query()->find($event->memory_link_id) : null;
                if ($resolvedWriteFingerprint
                    && $this->writeFingerprintMatches(
                        (string) $event->write_fingerprint,
                        $resolvedWriteFingerprint,
                        $resolvedLegacyWriteFingerprint,
                    )
                    && $event->state === 'committed'
                    && $retry
                    && $resolvedStatus
                    && $this->isCurrentIdempotentRetry($retry, $resolvedStatus)) {
                    return $retry;
                }

                throw new ConflictHttpException('The memory write event could not be replayed safely.', $error);
            }

            $retry = $resolvedKeys === []
                ? null
                : MemoryLink::query()->whereIn('idempotency_key', $resolvedKeys)->first();
            if ($retry
                && $resolvedContentHash
                && hash_equals((string) $retry->content_hash, $resolvedContentHash)
                && $resolvedWriteFingerprint
                && $this->writeFingerprintMatches(
                    (string) $retry->write_fingerprint,
                    $resolvedWriteFingerprint,
                    $resolvedLegacyWriteFingerprint,
                )
                && $resolvedStatus
                && $this->isCurrentIdempotentRetry($retry, $resolvedStatus)) {
                return $retry;
            }
            if ($retry) {
                throw new ConflictHttpException(
                    'The memory write event was committed previously but is no longer the active version.',
                    $error,
                );
            }

            throw $error;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function recall(string $query, string $scope, array $ids = [], int $topK = 6): array
    {
        $datasets = $this->datasetsFor($scope, $ids);
        $topK = max(1, min(20, $topK));
        $now = now();

        $base = MemoryLink::query()
            ->whereIn('dataset', $datasets)
            ->where('status', 'active')
            ->where(function (Builder $builder) use ($now) {
                $builder->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->where(function (Builder $builder) use ($now) {
                $builder->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function (Builder $builder) use ($now) {
                $builder->whereNull('valid_until')->orWhere('valid_until', '>', $now);
            });

        if ($scope === 'global') {
            // Global memories are curated and intentionally not tied to the reader.
        } elseif ($scope === 'workspace') {
            $base->where('tenant_id', $ids['tenant_id'] ?? null);
        } else {
            $base->where('user_id', $ids['user_id'] ?? null);
        }

        $terms = collect(preg_split('/[^\pL\pN_\.\-]+/u', mb_strtolower(trim($query))) ?: [])
            ->filter(fn ($term) => mb_strlen($term) >= 2)
            ->unique()
            ->values();

        $semanticRanks = [];
        if ($this->cognee->enabled()
            && $scope !== 'session'
            && MemoryDlp::allowsExternalSemanticQuery($query)) {
            // Search only authorized aliases that can actually rehydrate an
            // active SQL row. This preserves semantic recall across HMAC-key,
            // v1 and legacy migrations without querying unrelated datasets.
            $semanticDatasets = (clone $base)
                ->whereNotNull('cognee_memory_id')
                ->distinct()
                ->pluck('dataset');
            try {
                foreach ($this->cognee->searchDatasetsOrFail(
                    $semanticDatasets->map(static fn ($dataset): string => (string) $dataset)->all(),
                    trim($query),
                    min(40, $topK * 4),
                ) as $rank => $hit) {
                    $dataId = trim((string) ($hit['document_id'] ?? ($hit['documentId'] ?? '')));
                    if ($dataId !== '') {
                        $semanticRanks[$dataId] = min($semanticRanks[$dataId] ?? PHP_INT_MAX, $rank + 1);
                    }
                }
            } catch (RuntimeException) {
                // Cognee is an optional projection. A single bounded batch
                // failure invalidates all partial semantic ranking and recall
                // immediately continues from the canonical SQL candidates.
                $semanticRanks = [];
            }
        }

        // Rehydrate semantic candidates through the already authorized SQL
        // scope before they can influence ranking. This also prevents older,
        // highly relevant memories from being cut off by the lexical window.
        $semanticRows = $semanticRanks === []
            ? collect()
            : (clone $base)->whereIn('cognee_memory_id', array_keys($semanticRanks))->get();
        $lexicalRows = collect();
        if ($terms->isNotEmpty()) {
            // Keep every parsed term for final scoring and candidate discovery.
            // Chunk only the SQL expression so short technical identifiers are
            // never discarded merely because a query also contains many long
            // prose tokens or DLP identifiers.
            foreach ($terms->chunk(self::MAX_LEXICAL_TERMS) as $termChunk) {
                $patterns = $termChunk->map(fn (string $term): string => '%'.str_replace(
                    ['!', '%', '_'],
                    ['!!', '!%', '!_'],
                    $term,
                ).'%')->all();
                $matchExpression = implode(' + ', array_fill(
                    0,
                    count($patterns),
                    "CASE WHEN LOWER(summary) LIKE ? ESCAPE '!' THEN 1 ELSE 0 END",
                ));
                $lexicalRows = $lexicalRows->concat((clone $base)
                    ->select('memory_links.*')
                    ->selectRaw("({$matchExpression}) AS lexical_match_count", $patterns)
                    ->where(function (Builder $builder) use ($patterns): void {
                        foreach ($patterns as $pattern) {
                            $builder->orWhereRaw("LOWER(summary) LIKE ? ESCAPE '!'", [$pattern]);
                        }
                    })
                    ->orderByDesc('lexical_match_count')
                    ->orderByDesc('importance')
                    ->orderByDesc('recorded_at')
                    ->limit(100)
                    ->get());
            }
            $lexicalRows = $lexicalRows->unique('id')->values();
        }
        $rows = (clone $base)
            ->orderByDesc('importance')
            ->orderByDesc('recorded_at')
            ->limit(100)
            ->get()
            ->concat($lexicalRows)
            ->concat($semanticRows)
            ->unique('id')
            ->values();

        return $rows->map(function (MemoryLink $row) use ($semanticRanks, $terms) {
            $semanticRank = $row->cognee_memory_id
                ? ($semanticRanks[$row->cognee_memory_id] ?? null)
                : null;
            $haystack = mb_strtolower($row->summary);
            $lexicalHits = $terms->filter(fn ($term) => str_contains($haystack, $term))->count();
            $lexical = $terms->isEmpty() ? 0.0 : $lexicalHits / $terms->count();
            $lexicalPresence = $lexicalHits > 0 ? 1.0 : 0.0;
            $semantic = $semanticRank ? 1 / (60 + $semanticRank) : 0.0;
            // A direct authorized SQL term match must beat high-importance
            // recency noise, even when other query terms were DLP identifiers
            // which intentionally cannot appear in a returned memory.
            $score = 0.4 * $lexical
                + 0.2 * $lexicalPresence
                + 0.15 * (float) $row->importance
                + 0.15 * (float) $row->confidence
                + 0.1 * min(1, $semantic * 60);

            $payload = [
                'id' => $row->logicalExternalId() ?: (string) $row->id,
                'content' => $row->summary,
                'content_hash' => $row->content_hash,
                'type' => $row->type,
                'scope' => $row->scope,
                'importance' => (float) $row->importance,
                'confidence' => (float) $row->confidence,
                'staleness' => $row->staleness,
                'feature_key' => $row->feature_key,
                'source' => $semanticRank ? 'cognee_revalidated' : 'sql',
                'source_record_id' => (string) $row->id,
                'provenance' => $row->provenance,
                'valid_from' => $row->valid_from?->toIso8601String(),
                'valid_until' => $row->valid_until?->toIso8601String(),
                'recorded_at' => $row->recorded_at?->toIso8601String(),
                'retrieval_score' => round($score, 5),
                'meta' => $row->meta,
            ];

            return MemoryDlp::containsSecretInMemoryPayload($payload)
                || MemoryDlp::containsLocalOnlySourceInMemoryPayload($payload)
                    ? null
                    : $payload;
        })->filter()->sortByDesc('retrieval_score')->take($topK)->values()->all();
    }

    public function forget(string $scope, string $externalId, array $ids = []): bool
    {
        return DB::transaction(function () use ($scope, $externalId, $ids) {
            $datasets = $this->datasetsFor($scope, $ids);
            $seedQuery = MemoryLink::query()
                ->whereIn('dataset', $datasets)
                ->where(function (Builder $builder) use ($externalId) {
                    $builder->where('external_id', $externalId)
                        ->orWhere('meta->source_external_id', $externalId);
                });
            if (! $this->isSharedScope($scope)) {
                $seedQuery->where('user_id', $ids['user_id'] ?? null);
            }
            $seed = $seedQuery->first();
            if (! $seed) {
                return false;
            }

            $logicalExternalId = $seed->logicalExternalId();
            $seedMeta = is_array($seed->meta) ? $seed->meta : [];
            $memoryKey = trim((string) ($seed->feature_key ?: ($seedMeta['memory_key'] ?? '')));
            $this->lockMemoryAliasFamily(
                $datasets,
                $this->isSharedScope($scope) ? null : $seed->user_id,
                null,
                $logicalExternalId,
                $memoryKey,
            );

            $familyQuery = MemoryLink::query()
                ->whereIn('dataset', $datasets)
                ->where(function (Builder $builder) use ($logicalExternalId, $memoryKey) {
                    $builder->where('external_id', $logicalExternalId)
                        ->orWhere('meta->source_external_id', $logicalExternalId);
                    if ($memoryKey !== '') {
                        $builder->orWhere('feature_key', $memoryKey)
                            ->orWhere('meta->memory_key', $memoryKey);
                    }
                });
            if (! $this->isSharedScope($scope)) {
                $familyQuery->where('user_id', $ids['user_id'] ?? null);
            }
            $snapshot = (clone $familyQuery)->get();
            if ($snapshot->isEmpty()) {
                return false;
            }

            // The global lock order is identity -> write event -> memory link.
            // Backfill legacy events before taking all event row locks, then
            // acquire the family links. Remember uses the same event-before-link
            // order, preventing a privacy Delete from deadlocking a late retry.
            foreach ($snapshot as $link) {
                if ($link->idempotency_key && $link->write_fingerprint) {
                    MemoryWriteEvent::query()->firstOrCreate(
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
                }
            }
            MemoryWriteEvent::query()
                ->whereIn('memory_link_id', $snapshot->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $links = (clone $familyQuery)->orderBy('id')->lockForUpdate()->get();
            if ($links->isEmpty()) {
                return false;
            }
            MemoryWriteEvent::query()
                ->whereIn('memory_link_id', $links->pluck('id'))
                ->update(['state' => 'forgotten', 'forgotten_at' => now()]);

            foreach ($links as $link) {
                $this->enqueueDelete($link);
                $link->delete();
            }

            return true;
        });
    }

    public function promote(string $externalId, array $ids = []): ?MemoryLink
    {
        return DB::transaction(function () use ($externalId, $ids) {
            $scope = (string) ($ids['scope'] ?? 'project');
            $datasets = $this->datasetsFor($scope, $ids);
            $seedQuery = MemoryLink::query()->whereIn('dataset', $datasets)
                ->where(function (Builder $query) use ($externalId) {
                    $query->where('external_id', $externalId)
                        ->orWhere('meta->source_external_id', $externalId);
                })
                ->where('status', 'candidate');
            if (! $this->isSharedScope($scope)) {
                $seedQuery->where('user_id', $ids['user_id'] ?? null);
            }
            if (! $this->isSharedScope($scope) && array_key_exists('client_id', $ids)) {
                $seedQuery->where('client_id', $ids['client_id']);
            }
            // A logical ID means "promote the newest proposal". Supplying a
            // physical version ID still selects that exact candidate. Older
            // siblings are retired below so the queue cannot later revert it.
            $seed = $seedQuery->orderByDesc('id')->first();
            if (! $seed) {
                return null;
            }

            $seedMeta = is_array($seed->meta) ? $seed->meta : [];
            $seedLogicalExternalId = $seed->logicalExternalId();
            $seedMemoryKey = trim((string) ($seed->feature_key ?: ($seedMeta['memory_key'] ?? '')));
            $this->lockMemoryAliasFamily(
                $datasets,
                $this->isSharedScope($scope) ? null : ($ids['user_id'] ?? null),
                $this->isSharedScope($scope) ? null : ($ids['client_id'] ?? null),
                $seedLogicalExternalId,
                $seedMemoryKey,
            );
            $lockedCandidateQuery = MemoryLink::query()
                ->whereIn('dataset', $datasets)
                ->where('status', 'candidate');
            if (! $this->isSharedScope($scope)) {
                $lockedCandidateQuery->where('user_id', $ids['user_id'] ?? null);
            }
            if (! $this->isSharedScope($scope) && array_key_exists('client_id', $ids)) {
                $lockedCandidateQuery->where('client_id', $ids['client_id']);
            }
            if (hash_equals($seedLogicalExternalId, $externalId)) {
                // Re-read latest only after owning the logical identity locks.
                // A candidate committed while this promotion was waiting must
                // win instead of being reverted by the stale pre-lock seed.
                $lockedCandidateQuery->where(function (Builder $query) use ($externalId) {
                    $query->where('external_id', $externalId)
                        ->orWhere('meta->source_external_id', $externalId);
                })->orderByDesc('id');
            } else {
                // A physical version ID is an explicit selection.
                $lockedCandidateQuery->whereKey($seed->id);
            }
            $link = $lockedCandidateQuery->lockForUpdate()->first();
            if (! $link) {
                return null;
            }

            $meta = is_array($link->meta) ? $link->meta : [];
            $logicalExternalId = $link->logicalExternalId();
            $memoryKey = trim((string) ($link->feature_key ?: ($meta['memory_key'] ?? '')));

            $candidateSiblingsQuery = MemoryLink::query()
                ->whereIn('dataset', $datasets)
                ->where('status', 'candidate')
                ->whereKeyNot($link->id);
            if (! $this->isSharedScope($scope)) {
                $candidateSiblingsQuery->where('user_id', $link->user_id);
            }
            $candidateSiblings = $candidateSiblingsQuery
                ->where(function (Builder $query) use ($logicalExternalId, $memoryKey) {
                    $query->where('external_id', $logicalExternalId)
                        ->orWhere('meta->source_external_id', $logicalExternalId);
                    if ($memoryKey !== '') {
                        $query->orWhere('feature_key', $memoryKey)
                            ->orWhere('meta->memory_key', $memoryKey);
                    }
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $policyPayload = [
                'content' => $link->summary,
                'external_id' => $link->external_id,
                'feature_key' => $link->feature_key,
                'source_type' => $link->source_type,
                'source_ref' => $link->source_ref,
                'project_id' => $link->project_id,
                'session_id' => $link->session_id,
                'provenance' => $link->provenance,
                'meta' => $meta,
            ];
            if ($link->sensitivity === 'secret'
                || MemoryDlp::containsSecretInMemoryPayload($policyPayload)
                || MemoryDlp::containsLocalOnlySourceInMemoryPayload($policyPayload)) {
                throw ValidationException::withMessages([
                    'memory' => 'Memory candidate failed the DLP policy and cannot be promoted.',
                ]);
            }

            $supersededQuery = MemoryLink::query()
                ->whereIn('dataset', $datasets)
                ->where('status', 'active')
                ->whereKeyNot($link->id);
            if (! $this->isSharedScope($scope)) {
                $supersededQuery->where('user_id', $link->user_id);
            }
            $supersededFamily = $supersededQuery
                ->where(function (Builder $query) use ($link, $logicalExternalId, $memoryKey) {
                    $query->where('external_id', $logicalExternalId)
                        ->orWhere('meta->source_external_id', $logicalExternalId);
                    if ($memoryKey !== '') {
                        $query->orWhere('feature_key', $memoryKey)
                            ->orWhere('meta->memory_key', $memoryKey);
                    }
                    if ($link->supersedes_id !== null) {
                        $query->orWhere('id', $link->supersedes_id);
                    }
                })
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();
            $superseded = $supersededFamily->first();

            foreach ($supersededFamily as $oldVersion) {
                $oldVersion->update(['status' => 'superseded', 'staleness' => 'stale']);
                $this->enqueueDelete($oldVersion);
            }
            foreach ($candidateSiblings as $olderCandidate) {
                $olderCandidate->update(['status' => 'superseded', 'staleness' => 'stale']);
            }

            $projectionRequested = in_array($link->retention, ['durable', 'permanent'], true)
                && in_array($link->visibility, ['syncable', 'public'], true);
            $projectionStatus = $this->projectionStatus(
                $projectionRequested && $this->cognee->enabled(),
                $link->valid_from,
                $link->valid_until,
                $link->expires_at,
                $projectionRequested && ! $this->cognee->enabled(),
            );
            $link->update([
                'status' => 'active',
                'supersedes_id' => $superseded?->id,
                'write_reason' => 'explicit_promotion',
                'projection_status' => $projectionStatus,
            ]);
            if ($link->projection_status === 'pending') {
                $this->enqueue('upsert', $link->dataset, $link->id, $link->user_id, [
                    'content_hash' => (string) $link->content_hash,
                ], (string) $link->content_hash);
            }

            return $link->fresh();
        });
    }

    public function improve(string $scope, array $ids = []): bool
    {
        if (! $this->cognee->enabled() || ! config('luczor.cognee.improve_enabled', false)) {
            return false;
        }
        $datasets = $this->datasetsFor($scope, $ids);
        sort($datasets, SORT_STRING);
        $interval = max(300, (int) config('luczor.cognee.improve_min_interval_seconds', 3600));

        return DB::transaction(function () use ($datasets, $ids, $interval) {
            $bucket = (string) intdiv(now()->timestamp, $interval);
            $scheduled = false;

            foreach ($datasets as $dataset) {
                // PostgreSQL serializes the empty-table case; the time-bucketed
                // dedupe key remains a portable second line of defence in tests
                // and non-production database drivers. Lock aliases in sorted
                // order so key rotation cannot invert concurrent lock order.
                $this->lockMemoryIdentity($dataset, null, null, '', 'dataset-improve');

                $hasEligibleProjection = MemoryLink::query()
                    ->where('dataset', $dataset)
                    ->where('status', 'active')
                    ->where('projection_status', 'ready')
                    ->lockForUpdate()
                    ->get()
                    ->contains(fn (MemoryLink $link) => MemoryProjectionPolicy::isEligible($link));
                if (! $hasEligibleProjection) {
                    continue;
                }

                $alreadyActive = MemoryProjectionOutbox::query()
                    ->where('dataset', $dataset)
                    ->where('action', 'improve')
                    ->whereIn('status', ['pending', 'queued', 'processing', 'failed'])
                    ->lockForUpdate()
                    ->first(['id']) !== null;
                if ($alreadyActive) {
                    continue;
                }

                $cooldownStartedAt = now()->subSeconds($interval);
                $recentlyCompleted = MemoryProjectionOutbox::query()
                    ->where('dataset', $dataset)
                    ->where('action', 'improve')
                    ->where('status', 'done')
                    ->where(function (Builder $query) use ($cooldownStartedAt) {
                        $query->where('processed_at', '>=', $cooldownStartedAt)
                            ->orWhere(function (Builder $query) use ($cooldownStartedAt) {
                                $query->whereNull('processed_at')->where('updated_at', '>=', $cooldownStartedAt);
                            });
                    })
                    ->lockForUpdate()
                    ->get(['id', 'payload'])
                    ->contains(fn (MemoryProjectionOutbox $row) => (($row->payload ?? [])['phase'] ?? null) !== 'improve_disabled');
                if ($recentlyCompleted) {
                    continue;
                }

                $scheduled = $this->enqueue(
                    'improve',
                    $dataset,
                    null,
                    $ids['user_id'] ?? null,
                    [],
                    'bucket:'.$bucket,
                ) || $scheduled;
            }

            return $scheduled;
        });
    }

    public function pendingCount(?string $clientId = null): int
    {
        return MemoryProjectionOutbox::query()
            ->whereIn('status', ['pending', 'queued', 'processing', 'failed'])
            ->when($clientId, function (Builder $query) use ($clientId) {
                $query->whereIn('memory_link_id', MemoryLink::query()->select('id')->where('client_id', $clientId));
            })
            ->count();
    }

    private function enqueueDelete(MemoryLink $link): void
    {
        $dataId = trim((string) $link->cognee_memory_id);
        $upsert = MemoryProjectionOutbox::query()
            ->where('memory_link_id', $link->id)
            ->where('action', 'upsert')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get()
            ->first(fn (MemoryProjectionOutbox $row): bool => $row->status !== 'done'
                || ! $this->terminalUpsertIsSafe($row->payload ?? []));
        if ($upsert) {
            $payload = $upsert->payload ?? [];
            $phase = (string) ($payload['phase'] ?? 'new');
            $payloadDataId = trim((string) ($payload['cognee_memory_id'] ?? ''));
            if ($dataId === ''
                && $payloadDataId !== ''
                && in_array($phase, [
                    'ingested',
                    'cognify_rejected',
                    'cognify_failed',
                    'launch_ack_pending_terminal',
                ], true)) {
                $dataId = $payloadDataId;
            }

            // The canonical link is deleted in this transaction. Preserve the
            // non-personal filename component needed to reconcile a possibly
            // accepted Add response, while removing the encrypted source text
            // immediately and monotonically marking the erasure.
            $providerIdentity = MemoryProviderIdentity::resolve($payload, $link->id);
            if ($providerIdentity['error'] !== null || $providerIdentity['identity'] === null) {
                throw new RuntimeException(
                    'Memory Forget blocked: the deterministic Cognee provider filename identity is invalid or conflicting.'
                );
            }
            $contentIdentity = MemoryProviderIdentity::resolveContentHash($payload, $link->content_hash);
            if ($contentIdentity['error'] !== null || $contentIdentity['content_hash'] === null) {
                throw new RuntimeException(
                    'Memory Forget blocked: the deterministic Cognee content identity is invalid or conflicting.'
                );
            }
            $payload['provider_memory_link_id'] = $providerIdentity['identity'];
            $payload['content_hash'] = $contentIdentity['content_hash'];
            $payload['source_erasure_reason'] = 'memory_forgotten';
            $payload['content_snapshot_erased_at'] ??= now()->toIso8601String();
            unset(
                $payload['content'],
                $payload['content_ciphertext'],
                $payload['content_snapshot_expires_at'],
            );

            // A failed/backed-off upsert may be the only place holding the Data
            // UUID. Wake it immediately after Forget so it can reconcile a live
            // launch or finish its already-safe compensating delete path.
            if ($upsert->status !== 'processing') {
                $upsert->update([
                    'payload' => $payload,
                    'status' => 'queued',
                    'next_attempt_at' => null,
                ]);
                ProcessMemoryProjection::dispatch($upsert->id)->afterCommit();
            } else {
                $upsert->update(['payload' => $payload]);
            }
        }

        if ($dataId === '') {
            return;
        }

        $this->enqueue('delete', $link->dataset, $link->id, $link->user_id, [
            'cognee_memory_id' => $dataId,
            'content_hash' => $link->content_hash,
        ], $dataId);
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

    private function isCurrentIdempotentRetry(MemoryLink $link, string $requestedStatus): bool
    {
        return $link->status === $requestedStatus
            || ($requestedStatus === 'candidate' && $link->status === 'active');
    }

    private function writeFingerprintMatches(
        string $stored,
        ?string $current,
        ?string $legacy,
    ): bool {
        return ($current !== null && hash_equals($stored, $current))
            || ($legacy !== null && hash_equals($stored, $legacy));
    }

    private function recordWriteEvent(
        string $idempotencyKey,
        string $writeFingerprint,
        MemoryLink $link,
    ): void {
        MemoryWriteEvent::create([
            'idempotency_key' => $idempotencyKey,
            'write_fingerprint' => $writeFingerprint,
            'ledger_identity_version' => 2,
            'memory_link_id' => $link->id,
            'user_id' => $link->user_id,
            'dataset' => $link->dataset,
            'state' => 'committed',
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function enqueue(string $action, string $dataset, ?int $linkId, ?int $userId, array $payload, string $version): bool
    {
        $dedupe = hash('sha256', implode('|', [$action, $dataset, $linkId ?? 'none', $version]));
        $outbox = MemoryProjectionOutbox::query()->firstOrCreate(['dedupe_key' => $dedupe], [
            'memory_link_id' => $linkId,
            'user_id' => $userId,
            'action' => $action,
            'dataset' => $dataset,
            'payload' => $payload ?: null,
            'status' => 'pending',
        ]);

        $reEnableDisabledImprove = $action === 'improve'
            && $outbox->status === 'done'
            && (($outbox->payload ?? [])['phase'] ?? null) === 'improve_disabled';
        if ($outbox->wasRecentlyCreated || $outbox->status === 'failed' || $reEnableDisabledImprove) {
            $outbox->update([
                'payload' => $reEnableDisabledImprove ? null : $outbox->payload,
                'status' => 'queued',
                'attempts' => $reEnableDisabledImprove ? 0 : (int) ($outbox->attempts ?? 0),
                'last_error' => null,
                'processed_at' => null,
                'next_attempt_at' => null,
            ]);
            ProcessMemoryProjection::dispatch($outbox->id)->afterCommit();

            return true;
        }

        return false;
    }

    private function projectionStatus(
        bool $projectionRequested,
        mixed $validFrom,
        mixed $validUntil,
        mixed $expiresAt,
        bool $providerDeferred = false,
    ): string {
        if (! $projectionRequested && ! $providerDeferred) {
            return 'not_required';
        }

        $now = now();
        if ($validFrom !== null && Carbon::parse($validFrom)->gt($now)) {
            return 'deferred';
        }
        if (($validUntil !== null && Carbon::parse($validUntil)->lte($now))
            || ($expiresAt !== null && Carbon::parse($expiresAt)->lte($now))) {
            return 'not_required';
        }

        if ($providerDeferred) {
            return 'deferred';
        }

        return 'pending';
    }

    private function isSharedScope(string $scope): bool
    {
        return in_array($scope, ['workspace', 'global'], true);
    }

    /** Lock every historical alias in one deterministic global order. */
    private function lockMemoryAliasFamily(
        array $datasets,
        mixed $userId,
        mixed $clientId,
        string $externalId,
        string $memoryKey,
    ): void {
        $datasets = array_values(array_unique($datasets));
        sort($datasets, SORT_STRING);
        foreach ($datasets as $dataset) {
            $this->lockMemoryIdentity($dataset, $userId, $clientId, $externalId, $memoryKey);
        }
    }

    /** Serialize first writes through a database-portable identity row. */
    private function lockMemoryIdentity(
        string $dataset,
        mixed $userId,
        mixed $clientId,
        string $externalId,
        string $memoryKey,
    ): void {
        $identities = [];
        if ($externalId !== '') {
            $identities[] = "external-user\0{$dataset}\0{$userId}\0{$externalId}";
            $identities[] = "external\0{$dataset}\0{$userId}\0{$clientId}\0{$externalId}";
        }
        if ($memoryKey !== '') {
            $identities[] = "feature\0{$dataset}\0{$userId}\0{$memoryKey}";
        }
        sort($identities, SORT_STRING);

        foreach ($identities as $identity) {
            $hash = hash('sha256', $identity);
            $locked = false;
            for ($attempt = 0; $attempt < 3; $attempt++) {
                DB::table('memory_identity_locks')->insertOrIgnore([
                    'identity_hash' => $hash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('memory_identity_locks')
                    ->where('identity_hash', $hash)
                    ->lockForUpdate()
                    ->first();
                if ($row) {
                    DB::table('memory_identity_locks')
                        ->where('identity_hash', $hash)
                        ->update(['updated_at' => now()]);
                    $locked = true;
                    break;
                }
                // A concurrent maintenance pass can remove an idle row after
                // insertOrIgnore but before SELECT. Retry creation so the
                // critical section never proceeds without an owned lock row.
            }
            if (! $locked) {
                throw new RuntimeException('The memory identity lock could not be acquired safely.');
            }
        }
    }

    /** Canonicalize caller-controlled write fields before hashing them. */
    private function canonicalFingerprintValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->canonicalFingerprintValue($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalFingerprintValue($item);
        }

        return $value;
    }
}
