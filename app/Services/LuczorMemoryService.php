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
        $data = MemoryMetadata::normalizeInput($data);
        $data['importance'] = MemoryPriority::resolve($data);
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
                    'importance' => $data['importance'],
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
                    $existingQuery->where('user_id', $userId);
                    if (! array_key_exists('expected_previous_id', $data)) {
                        $existingQuery->where('client_id', $clientId);
                    }
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
                        $supersededQuery->where('user_id', $userId);
                        if (! array_key_exists('expected_previous_id', $data)) {
                            $supersededQuery->where('client_id', $clientId);
                        }
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
                $candidatePrevious = $status === 'candidate' && $existing?->status === 'candidate'
                    && hash_equals((string) $existing->content_hash, $hash) ? $existing : null;
                $currentVersion = $candidatePrevious ?? $superseded;

                if (array_key_exists('expected_previous_id', $data)) {
                    $expectedPreviousId = $data['expected_previous_id'] === null
                        ? null
                        : (int) $data['expected_previous_id'];
                    if ($currentVersion?->id !== $expectedPreviousId) {
                        throw new MemoryVersionConflictException($currentVersion?->id);
                    }
                }

                // Resolve legacy omission only after hashing the immutable caller request.
                // Otherwise a retry would inherit newer state and change its fingerprint.
                $previous = $currentVersion ?? $existing;
                $sameContent = $previous && hash_equals((string) $previous->content_hash, $hash);
                if ($sameContent) {
                    foreach (['observed_at', 'valid_from', 'valid_until', 'expires_at'] as $field) {
                        if (! array_key_exists($field, $data)) {
                            $data[$field] = $previous->$field;
                        }
                    }
                }
                $metadataProvided = array_key_exists('memory_metadata', $meta);
                if ($data['_tags_omitted'] ?? false) {
                    unset($meta['tags']);
                }
                if (! $metadataProvided && isset($previous?->meta['memory_metadata'])) {
                    $meta['memory_metadata'] = $sameContent ? $previous->meta['memory_metadata']
                        : MemoryMetadata::invalidate($previous->meta['memory_metadata']);
                }
                if ($previous && ! array_key_exists('tags', $meta) && isset($previous->meta['tags'])) {
                    $meta['tags'] = $sameContent || in_array('tags', $previous->meta['memory_metadata']['overrides'] ?? [], true)
                        ? $previous->meta['tags'] : [];
                }
                if ($previous && ! $data['_importance_provided']
                    && ($sameContent || in_array('importance', $previous->meta['memory_metadata']['overrides'] ?? [], true))) {
                    $data['importance'] = (float) $previous->importance;
                }
                abort_if(MemoryDlp::containsSecretInMemoryPayload(array_replace($data, ['meta' => $meta]))
                    || MemoryDlp::containsLocalOnlySourceInMemoryPayload(array_replace($data, ['meta' => $meta])), 422, 'Inherited metadata failed the DLP policy.');
                if ($sameContent && (($metadataProvided && $this->canonicalFingerprintValue($previous->meta['memory_metadata'] ?? null)
                    !== $this->canonicalFingerprintValue($meta['memory_metadata']))
                    || ($previous->meta['tags'] ?? []) !== ($meta['tags'] ?? [])
                    || (float) $previous->importance !== (float) $data['importance'])
                    && ! array_key_exists('expected_previous_id', $data)) {
                    throw new MemoryVersionConflictException($previous->id);
                }
                if ($existing && $this->isCurrentIdempotentRetry($existing, $status) && $sameContent
                    && $this->sameMemoryAttributes($existing, $data, $meta)) {
                    if (! $existing->idempotency_key || ! $existing->write_fingerprint) {
                        $existing->update(['idempotency_key' => $idempotencyKey, 'write_fingerprint' => $writeFingerprint, 'ledger_identity_version' => 2]);
                    }
                    $this->recordWriteEvent($idempotencyKey, $writeFingerprint, $existing);

                    return $existing;
                }

                if (! $metadataProvided && ! isset($meta['memory_metadata'])) {
                    $meta['memory_metadata'] = MemoryMetadata::initial(new MemoryLink(['source_type' => $data['source_type'] ?? ($data['source'] ?? 'user')]));
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
                    'importance' => $data['importance'],
                    'confidence' => (float) ($data['confidence'] ?? 0.5),
                    'summary' => mb_substr($content, 0, 8000),
                    'content_hash' => $hash,
                    'idempotency_key' => $idempotencyKey,
                    'write_fingerprint' => $writeFingerprint,
                    'ledger_identity_version' => 2,
                    'source_type' => $data['source_type'] ?? ($data['source'] ?? 'user'),
                    'source_ref' => $data['source_ref'] ?? null,
                    'provenance' => $data['provenance'] ?? null,
                    'observed_at' => $sameContent ? $data['observed_at'] : ($data['observed_at'] ?? now()),
                    'valid_from' => $sameContent ? $data['valid_from'] : ($data['valid_from'] ?? now()),
                    'valid_until' => $data['valid_until'] ?? null,
                    'recorded_at' => now(),
                    'expires_at' => $data['expires_at'] ?? ($retention === 'session' ? now()->addDay() : null),
                    'supersedes_id' => $currentVersion?->id,
                    'write_reason' => $data['write_reason'] ?? null,
                    'projection_status' => $projectionStatus,
                    'meta' => $meta,
                ]);
                $inputRevision = MemoryMetadata::inputRevision($link);
                if (($metadataProvided && ($meta['memory_metadata']['classification']['origin'] ?? null) === 'dream')
                    || ($sameContent && ($previous->provenance['memory_metadata_input_revision'] ?? null) === $inputRevision)) {
                    $link->update(['provenance' => array_replace($link->provenance ?? [], ['memory_metadata_input_revision' => $inputRevision])]);
                }
                $this->recordWriteEvent($idempotencyKey, $writeFingerprint, $link);

                if ($status === 'active') {
                    foreach ($supersededFamily as $oldVersion) {
                        if ($oldVersion->id === $link->id) {
                            continue;
                        }
                        $oldVersion->update(['status' => 'superseded', 'staleness' => 'stale']);
                        $this->enqueueDelete($oldVersion);
                    }
                } elseif ($candidatePrevious) {
                    $candidatePrevious->update(['status' => 'superseded', 'staleness' => 'stale']);
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
        $mysql = DB::connection()->getDriverName() === 'mysql';
        $castType = $mysql ? 'CHAR' : 'TEXT';
        $columns = ["COALESCE(summary, '')", "COALESCE(feature_key, '')"];
        foreach (['tags', 'memory_metadata.categories', 'memory_metadata.files'] as $path) {
            $columns[] = "COALESCE(CAST(JSON_EXTRACT(meta, '$.{$path}') AS {$castType}), '')";
        }
        $searchSql = $mysql ? 'LOWER(CONCAT('.implode(", ' ', ", $columns).'))'
            : 'LOWER('.implode(" || ' ' || ", $columns).')';
        if ($terms->isNotEmpty()) {
            // Keep every parsed term for final scoring and candidate discovery.
            // Chunk only the SQL expression so short technical identifiers are
            // never discarded merely because a query also contains many long
            // prose tokens or DLP identifiers.
            foreach ($terms->chunk(self::MAX_LEXICAL_TERMS) as $termChunk) {
                $patterns = $termChunk->flatMap(function (string $term): array {
                    $escape = fn (string $value) => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
                    $literal = $escape($term);
                    $encoded = $escape(substr(json_encode($term, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 1, -1));
                    // JSON may store Unicode as escapes. Match its codepoint slot here;
                    // decoded Unicode text is checked exactly in final relevance scoring.
                    $encoded = preg_replace('/\\\\u[0-9a-f]{4}/i', '\\u____', $encoded);

                    return array_values(array_unique([$literal, $encoded]));
                })->all();
                $matchExpression = implode(' + ', array_fill(
                    0,
                    count($patterns),
                    "CASE WHEN {$searchSql} LIKE ? ESCAPE '!' THEN 1 ELSE 0 END",
                ));
                $lexicalRows = $lexicalRows->concat((clone $base)
                    ->select('memory_links.*')
                    ->selectRaw("({$matchExpression}) AS lexical_match_count", $patterns)
                    ->where(function (Builder $builder) use ($patterns, $searchSql): void {
                        foreach ($patterns as $pattern) {
                            $builder->orWhereRaw("{$searchSql} LIKE ? ESCAPE '!'", [$pattern]);
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
            $haystack = MemoryMetadata::searchText($row);
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
                'priority' => MemoryPriority::name((float) $row->importance),
                'priority_label' => MemoryPriority::LABELS[MemoryPriority::name((float) $row->importance)],
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
        })->filter()->sort(fn (array $a, array $b) => ($b['retrieval_score'] <=> $a['retrieval_score'])
            ?: (($b['meta']['memory_metadata']['interest'] ?? -1) <=> ($a['meta']['memory_metadata']['interest'] ?? -1)))
            // Identical evidence occupies one context slot. Preserve every ledger
            // version; normalization never changes or deletes stored facts.
            ->unique(fn (array $item) => preg_replace('/\s+/u', ' ', trim($item['content'])))
            ->take($topK)->values()->all();
    }

    /** @return array<string,mixed> */
    public function analyze(string $scope, array $ids): array
    {
        // Scope is exact, including the actor even when legacy dataset aliases
        // are encountered. No tenant-wide or cross-project quality sweep.
        abort_unless(in_array($scope, ['user', 'project'], true) && ! empty($ids['user_id']), 422);
        $rows = MemoryLink::query()->whereIn('dataset', $this->datasetsFor($scope, $ids))
            ->where('user_id', $ids['user_id'])->orderByDesc('recorded_at')->orderByDesc('id')
            ->limit(1001)->get();
        $truncated = $rows->count() > 1000;
        $safe = $rows->take(1000)->filter(fn (MemoryLink $row) => ! MemoryDlp::containsSecretInMemoryPayload(['content' => $row->summary, 'meta' => $row->meta, 'provenance' => $row->provenance])
            && ! MemoryDlp::containsLocalOnlySourceInMemoryPayload(['source_type' => $row->source_type, 'meta' => $row->meta, 'provenance' => $row->provenance]));
        $active = $safe->where('status', 'active');
        $duplicates = $active->groupBy(fn (MemoryLink $row) => hash('sha256', preg_replace('/\s+/u', ' ', trim($row->summary)) ?? trim($row->summary)))
            ->filter(fn ($group) => $group->count() > 1)
            ->map(fn ($group) => ['ids' => $group->pluck('id')->map(fn ($id) => (string) $id)->values()->all(), 'count' => $group->count()])
            ->values()->take(20)->all();
        $conflicts = $active->filter(fn (MemoryLink $row) => ! empty($row->feature_key))
            ->groupBy('feature_key')->filter(fn ($group) => $group->pluck('content_hash')->unique()->count() > 1)
            ->map(fn ($group) => ['ids' => $group->pluck('id')->map(fn ($id) => (string) $id)->values()->all(), 'count' => $group->count()])
            ->values()->take(20)->all();
        $expired = $active->filter(fn (MemoryLink $row) => $row->expires_at?->isPast() || $row->valid_until?->isPast());
        $review = $active->filter(fn (MemoryLink $row) => ! $expired->contains('id', $row->id)
            && ($row->staleness !== 'fresh' || $row->recorded_at?->lt(now()->subDays(90))));

        return [
            'scope' => $scope, 'analyzed' => $safe->count(), 'truncated' => $truncated,
            'priorities' => $active->groupBy(fn (MemoryLink $row) => MemoryPriority::name((float) $row->importance))->map->count()->all(),
            'duplicates' => $duplicates, 'possible_conflicts' => $conflicts,
            'expired_count' => $expired->count(), 'review_count' => $review->count(),
            'candidate_count' => $safe->where('status', 'candidate')->count(),
            'changed_records' => 0,
            'recommendations' => [
                'Identische Inhalte werden beim Abruf einmal verwendet; alle Versionen bleiben erhalten.',
                'Unbestätigte Kandidaten und verschiedene Aussagen zum selben Merkmal zuerst prüfen.',
                'Alter ist nur ein Prüfhinweis und kein Beleg für eine falsche Erinnerung.',
            ],
        ];
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

                $eligibleProjection = MemoryLink::query()
                    ->where('dataset', $dataset)
                    ->where('status', 'active')
                    ->where('projection_status', 'ready')
                    ->lockForUpdate()
                    ->get()
                    ->filter(fn (MemoryLink $link) => MemoryProjectionPolicy::isEligible($link));
                if ($eligibleProjection->isEmpty()) {
                    continue;
                }
                $sourceRevision = hash('sha256', $eligibleProjection->sortBy('id')->map(fn (MemoryLink $link) => [
                    $link->id, $link->content_hash, $link->valid_from, $link->valid_until, $link->expires_at,
                ])->values()->toJson());
                $unchanged = MemoryProjectionOutbox::query()->where('dataset', $dataset)
                    ->where('action', 'improve')->where('status', 'done')
                    ->where('payload->source_revision', $sourceRevision)->exists();
                if ($unchanged) {
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
                    ['source_revision' => $sourceRevision],
                    'bucket:'.$bucket,
                ) || $scheduled;
            }

            return $scheduled;
        });
    }

    public function maintenanceSources(string $scope, array $ids, int $after = 0): array
    {
        $rows = MemoryLink::query()->whereIn('dataset', $this->datasetsFor($scope, $ids))
            ->where('user_id', $ids['user_id'])->where('status', 'active')->where('id', '>', $after)
            ->orderBy('id')->limit(51)->get();
        $page = $rows->take(50);

        return ['next' => $rows->count() > 50 ? $page->last()->id : null, 'records' => $page
            ->filter(fn (MemoryLink $link) => MemoryProjectionPolicy::isEligible($link))
            ->map(fn (MemoryLink $link) => [
                'id' => (string) $link->id, 'external_id' => $link->logicalExternalId(),
                'revision' => $this->maintenanceRevision($link), 'content' => $link->summary,
                'source' => $link->source_type, 'scope' => $link->scope, 'project_id' => $link->project_id,
                'confidence' => $link->confidence, 'visibility' => $link->visibility,
                'metadata' => $link->meta['memory_metadata'] ?? null, 'tags' => $link->meta['tags'] ?? [],
                'importance' => (float) $link->importance, 'provenance' => $link->provenance,
                'input_revision' => MemoryMetadata::inputRevision($link),
                'metadata_needed' => ($link->provenance['memory_metadata_input_revision'] ?? null) !== MemoryMetadata::inputRevision($link),
                'rewrite_eligible' => $link->source_type === 'user' && empty(($link->provenance ?? [])['maintenance_policy']),
                'valid_from' => $link->valid_from?->toISOString(), 'valid_until' => $link->valid_until?->toISOString(),
            ])->values()->all()];
    }

    private function maintenanceRevision(MemoryLink $link): string
    {
        return hash('sha256', json_encode([$link->id, $link->content_hash, $link->updated_at?->toISOString(),
            $link->status, $link->visibility, $link->valid_from, $link->valid_until, $link->expires_at,
            $link->summary, $link->source_type, $link->confidence, $link->sensitivity, $link->retention, $link->provenance,
            $link->importance, $link->meta], JSON_THROW_ON_ERROR));
    }

    /** Called only through the orchestrator. Replacements delete source text, not a whole historical archive. */
    public function applyMaintenance(array $data, array $ids, callable $remember): array
    {
        return DB::transaction(function () use ($data, $ids, $remember) {
            // All ordinary writes also lock this owner. Competing devices serialize before source CAS.
            User::query()->whereKey($ids['user_id'])->lockForUpdate()->firstOrFail();
            $key = hash('sha256', $data['request_id']);
            $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $receipt = DB::table('memory_maintenance_receipts')->where('user_id', $ids['user_id'])->where('request_key', $key)->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->fingerprint, $fingerprint), 409, 'Maintenance identity conflict.');

                return json_decode($receipt->metadata, true, 512, JSON_THROW_ON_ERROR);
            }
            $sources = [];
            // Same event-before-link lock order as Forget. Legacy rows without a write ledger stay read-only.
            MemoryWriteEvent::query()->whereIn('memory_link_id', array_column($data['sources'], 'id'))
                ->where('user_id', $ids['user_id'])->orderBy('id')->lockForUpdate()->get();
            foreach ($data['sources'] as $reference) {
                $link = MemoryLink::query()->whereKey($reference['id'])->where('user_id', $ids['user_id'])
                    ->whereIn('dataset', $this->datasetsFor($data['scope'], $ids))->lockForUpdate()->first();
                abort_unless($link && MemoryProjectionPolicy::isEligible($link) &&
                    hash_equals($this->maintenanceRevision($link), $reference['revision']), 409, 'Memory source changed.');
                $sources[(string) $link->id] = $link;
            }
            $changed = 0;
            $retired = [];
            $targetsSeen = [];
            foreach ($data['operations'] as $index => $operation) {
                if ($operation['operation'] === 'noop') {
                    continue;
                }
                $references = array_map(fn ($id) => $sources[(string) $id] ?? null, $operation['sources']);
                abort_if(empty($references) || in_array(null, $references, true), 422, 'Unknown maintenance source.');
                $targets = $operation['targets'];
                $kind = $operation['operation'];
                abort_unless(in_array($kind, ['add', 'rewrite', 'merge', 'conflict', 'annotate'], true), 422);
                abort_if($kind !== 'annotate' && trim($operation['content']) === '', 422, 'Empty maintenance content.');
                abort_if($kind === 'annotate' && (count($targets) !== 1 || count($references) !== 1
                    || (string) $targets[0] !== (string) $references[0]->id || trim($operation['content'] ?? '') !== ''
                    || ! is_array($operation['metadata'] ?? null)), 422, 'Annotation requires exactly one unchanged source.');
                abort_if(($kind === 'rewrite' && count($targets) !== 1) || ($kind === 'merge' && count($targets) < 2)
                    || (in_array($kind, ['add', 'conflict'], true) && count($targets) !== 0), 422);
                foreach ($targets as $target) {
                    abort_unless(isset($sources[(string) $target]) && in_array((string) $target, array_map('strval', $operation['sources']), true)
                        && ! isset($targetsSeen[(string) $target]), 422);
                    $targetsSeen[(string) $target] = true;
                }
                abort_if(MemoryDlp::containsSecretInMemoryPayload($operation)
                    || MemoryDlp::containsLocalOnlySourceInMemoryPayload($operation), 422, 'Local-only maintenance content.');
                if ($kind === 'annotate') {
                    $link = $references[0];
                    $patched = MemoryMetadata::patch($link, $operation['metadata'], $data['model_id']);
                    $result = $remember(array_merge($ids, $patched, [
                        'scope' => $link->scope, 'content' => $link->summary, 'external_id' => $link->logicalExternalId(),
                        'write_id' => 'maintenance:'.$key.':'.$index, 'expected_previous_id' => $link->id,
                        'client_id' => $link->client_id, 'project_ref_id' => $link->project_ref_id,
                        'feature_key' => $link->feature_key, 'type' => $link->type, 'session_id' => $link->session_id,
                        'source_type' => $link->source_type, 'source_ref' => $link->source_ref,
                        'write_intent' => 'system', 'visibility' => $link->visibility, 'retention' => $link->retention,
                        'sensitivity' => $link->sensitivity, 'confidence' => $link->confidence,
                        'provenance' => $link->provenance, 'observed_at' => $link->observed_at,
                        'valid_from' => $link->valid_from, 'valid_until' => $link->valid_until, 'expires_at' => $link->expires_at,
                    ]));
                    abort_unless($result->link !== null, 422, 'Metadata rejected by privacy policy.');
                    $changed++;

                    continue;
                }
                abort_if(collect($references)->contains(fn (MemoryLink $reference) => $reference->source_type !== 'user'
                    || ! empty($reference->provenance['maintenance_policy'])), 422, 'This source is available for metadata annotation only.');
                $merged = MemoryMetadata::merge($references);
                foreach ($targets as $target) {
                    $link = $sources[(string) $target];
                    abort_unless($link->idempotency_key && $link->write_fingerprint && MemoryWriteEvent::query()
                        ->where('memory_link_id', $link->id)->where('idempotency_key', $link->idempotency_key)
                        ->where('state', 'committed')->exists(), 422, 'Source lacks a durable write ledger.');
                    $retired[] = ['external_id' => $link->logicalExternalId(), 'version_id' => $link->id];
                    // Exact rows only; family-wide Forget would erase unrelated historical versions.
                    $this->enqueueDelete($link);
                    MemoryWriteEvent::query()->where('memory_link_id', $link->id)->update(['state' => 'forgotten', 'forgotten_at' => now()]);
                    $link->delete();
                }
                $result = $remember(array_merge($ids, $merged, [
                    'scope' => $data['scope'], 'content' => $operation['content'],
                    'write_id' => 'maintenance:'.$key.':'.$index, 'external_id' => 'maintenance:'.$key.':'.$index,
                    'source_type' => 'assistant', 'write_intent' => 'system', 'visibility' => 'syncable',
                    'retention' => 'durable', 'confidence' => min(0.35, ...array_map(fn ($link) => $link->confidence, $references)),
                    'valid_from' => collect($references)->pluck('valid_from')->filter()->max(),
                    'valid_until' => collect($references)->pluck('valid_until')->filter()->min(),
                    'expires_at' => collect($references)->pluck('expires_at')->filter()->min(),
                    'type' => $kind === 'conflict' ? 'memory_conflict' : 'memory_consolidation',
                    'provenance' => ['source_memory_ids' => $operation['sources'], 'source_revisions' => $data['sources'],
                        'maintenance_policy' => 'luczor-maintenance-v1', 'model_id' => $data['model_id'], 'reason' => $operation['reason'],
                        'derived' => true, 'reviewed_locally' => true],
                ]));
                abort_unless($result->link !== null, 422, 'Maintenance write rejected by privacy policy.');
                $changed += max(1, count($targets));
            }
            $metadata = ['scope' => $data['scope'], 'project_id' => $ids['project_id'] ?? null,
                'changed' => $changed, 'retired' => $retired, 'sources' => $data['sources'], 'model_id' => $data['model_id'], 'policy' => 'luczor-maintenance-v1', 'reviewed_locally' => true];
            DB::table('memory_maintenance_receipts')->insert(['user_id' => $ids['user_id'], 'request_key' => $key,
                'fingerprint' => $fingerprint, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return $metadata;
        });
    }

    /** Owner-scoped metadata only: never expose provider endpoints, payloads or raw exceptions. */
    public function maintenanceReceipt(string $scope, array $ids, string $requestId): ?array
    {
        $receipt = DB::table('memory_maintenance_receipts')->where('user_id', $ids['user_id'])
            ->where('request_key', hash('sha256', $requestId))->first();
        if (! $receipt) {
            return null;
        }
        $metadata = json_decode($receipt->metadata, true, 512, JSON_THROW_ON_ERROR);

        return ($metadata['scope'] ?? null) === $scope && ($metadata['project_id'] ?? null) === ($ids['project_id'] ?? null) ? $metadata : null;
    }

    public function maintenanceStatus(string $scope, array $ids): array
    {
        $rows = MemoryProjectionOutbox::query()->whereIn('dataset', $this->datasetsFor($scope, $ids))
            ->where('user_id', $ids['user_id'])->where('action', 'improve')->latest('id')->limit(20)->get();

        return $rows->map(function (MemoryProjectionOutbox $row): array {
            $phase = (string) (($row->payload ?? [])['phase'] ?? 'queued');
            $allowed = ['new', 'queued', 'improve_launching', 'improve_polling', 'improve_disabled', 'done'];
            $run = ($row->payload ?? [])['pipeline_run_id'] ?? null;

            return [
                'id' => (string) $row->id,
                'run_id' => is_string($run) && preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $run) ? $run : null,
                'status' => $row->status,
                'phase' => in_array($phase, $allowed, true) ? $phase : 'provider_processing',
                'draining' => in_array($row->status, ['processing', 'queued'], true),
                'attempts' => $row->attempts,
                'failed' => $row->last_error !== null,
                'updated_at' => $row->updated_at?->toISOString(),
                'completed_at' => $row->processed_at?->toISOString(),
            ];
        })->all();
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
                'payload' => $reEnableDisabledImprove ? ($payload ?: null) : $outbox->payload,
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
    private function sameMemoryAttributes(MemoryLink $link, array $data, array $meta): bool
    {
        foreach (['type' => 'note', 'visibility' => 'syncable', 'sensitivity' => 'normal', 'retention' => 'durable',
            'source_type' => 'user', 'source_ref' => null, 'importance' => 0.5, 'confidence' => 0.5] as $field => $default) {
            $incoming = $data[$field] ?? $default;
            $stored = $link->$field;
            if (in_array($field, ['importance', 'confidence'], true)) {
                if ((float) $stored !== (float) $incoming) {
                    return false;
                }
            } elseif ($stored !== $incoming) {
                return false;
            }
        }
        foreach (['observed_at', 'valid_from', 'valid_until', 'expires_at'] as $field) {
            if (array_key_exists($field, $data) && ($data[$field] === null ? $link->$field !== null
                : ! $link->$field?->equalTo($data[$field]))) {
                return false;
            }
        }
        foreach ($meta as $key => $value) {
            if ($this->canonicalFingerprintValue($link->meta[$key] ?? null) !== $this->canonicalFingerprintValue($value)) {
                return false;
            }
        }
        foreach ($data['provenance'] ?? [] as $key => $value) {
            if ($key !== 'captured_at' && $this->canonicalFingerprintValue($link->provenance[$key] ?? null) !== $this->canonicalFingerprintValue($value)) {
                return false;
            }
        }

        return true;
    }

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
