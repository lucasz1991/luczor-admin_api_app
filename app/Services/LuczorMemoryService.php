<?php

namespace App\Services;

use App\Jobs\ProcessMemoryProjection;
use App\Models\MemoryLink;
use App\Models\MemoryProjectionOutbox;
use App\Services\Cognee\CogneeClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Canonical memory store and retrieval adapter.
 *
 * SQL is the system of record. Cognee is a rebuildable semantic projection and
 * may only influence ranking after a hit has been revalidated against SQL.
 */
class LuczorMemoryService
{
    public function __construct(private CogneeClient $cognee) {}

    public function cogneeEnabled(): bool
    {
        return $this->cognee->enabled();
    }

    /** Namespaced dataset key. Dataset boundaries are authorization boundaries. */
    public function datasetFor(string $scope, array $ids = []): string
    {
        $tenant = $ids['tenant_id'] ?? 'personal';
        $user = $ids['user_id'] ?? 'server';
        $prefix = "tenant:{$tenant}";

        return match ($scope) {
            'project' => "{$prefix}:user:{$user}:project:".($ids['project_id'] ?? 'default'),
            'workspace' => "{$prefix}:workspace",
            'skill' => "{$prefix}:user:{$user}:skills",
            'agent' => "{$prefix}:user:{$user}:agent:".($ids['agent_id'] ?? 'default').':runs',
            'session' => "{$prefix}:user:{$user}:session:".($ids['session_id'] ?? 'default'),
            'global' => 'global:curated',
            default => "{$prefix}:user:{$user}:private",
        };
    }

    /** @return array<int,string> */
    private function datasetsFor(string $scope, array $ids): array
    {
        $current = $this->datasetFor($scope, $ids);
        $user = $ids['user_id'] ?? 'server';
        $legacy = match ($scope) {
            'project' => "user:{$user}:projects:".($ids['project_id'] ?? 'default'),
            'skill' => "user:{$user}:skills",
            'agent' => 'agent:'.($ids['agent_id'] ?? 'default').':runs',
            'global' => 'global:knowledge',
            'workspace', 'session' => null,
            default => "user:{$user}:private",
        };

        return array_values(array_unique(array_filter([$current, $legacy], fn (?string $dataset) => $dataset !== null)));
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

        try {
            return DB::transaction(function () use ($data, &$resolvedIdempotencyKey) {
                $scope = (string) ($data['scope'] ?? 'project');
                $ids = [
                    'tenant_id' => $data['tenant_id'] ?? null,
                    'user_id' => $data['user_id'] ?? null,
                    'project_id' => $data['project_id'] ?? null,
                    'agent_id' => $data['agent_id'] ?? null,
                    'session_id' => $data['session_id'] ?? null,
                ];
                $dataset = $this->datasetFor($scope, $ids);
                $content = trim((string) ($data['content'] ?? ''));
                $hash = hash('sha256', preg_replace('/\s+/u', ' ', $content) ?? $content);
                $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
                $requestedExternalId = mb_substr(trim((string) ($data['external_id'] ?? '')) ?: (string) Str::uuid(), 0, 190);
                $clientId = $data['client_id'] ?? null;
                $userId = $data['user_id'] ?? null;
                $memoryKey = trim((string) ($data['memory_key'] ?? ($meta['memory_key'] ?? ($data['feature_key'] ?? ''))));
                $idempotencyKey = hash('sha256', json_encode([
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'dataset' => $dataset,
                    'source_external_id' => $requestedExternalId,
                    'content_hash' => $hash,
                ], JSON_THROW_ON_ERROR));
                $resolvedIdempotencyKey = $idempotencyKey;

                $this->lockMemoryIdentity($dataset, $userId, $clientId, $requestedExternalId, $memoryKey);

                $retry = MemoryLink::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($retry) {
                    return $retry;
                }

                $existing = MemoryLink::query()
                    ->where('user_id', $userId)
                    ->where('client_id', $clientId)
                    ->where('dataset', $dataset)
                    ->where('external_id', $requestedExternalId)
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($existing && hash_equals((string) $existing->content_hash, $hash)) {
                    if (! $existing->idempotency_key) {
                        $existing->update(['idempotency_key' => $idempotencyKey]);
                    }

                    return $existing;
                }

                $superseded = null;
                if ($memoryKey !== '') {
                    $superseded = MemoryLink::query()
                        ->where('user_id', $userId)
                        ->where('dataset', $dataset)
                        ->where('status', 'active')
                        ->lockForUpdate()
                        ->where(function (Builder $query) use ($memoryKey) {
                            $query->where('feature_key', $memoryKey)
                                ->orWhere('meta->memory_key', $memoryKey);
                        })
                        ->latest('id')
                        ->first();
                }
                if (! $superseded) {
                    $superseded = MemoryLink::query()
                        ->where('user_id', $userId)
                        ->where('client_id', $clientId)
                        ->where('dataset', $dataset)
                        ->where('status', 'active')
                        ->lockForUpdate()
                        ->where(function (Builder $query) use ($requestedExternalId) {
                            $query->where('external_id', $requestedExternalId)
                                ->orWhere('meta->source_external_id', $requestedExternalId);
                        })
                        ->latest('id')
                        ->first();
                }

                $status = (string) ($data['status'] ?? 'active');
                $retention = (string) ($data['retention'] ?? 'durable');
                $projectionRequired = (bool) ($data['project_to_cognee'] ?? false) && $status === 'active';
                $projectionStatus = $this->projectionStatus(
                    $projectionRequired,
                    $data['valid_from'] ?? null,
                    $data['valid_until'] ?? null,
                    $data['expires_at'] ?? null,
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

                if ($status === 'active' && $superseded && $superseded->id !== $link->id) {
                    $superseded->update(['status' => 'superseded', 'staleness' => 'stale']);
                    $this->enqueueDelete($superseded);
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
            $retry = $resolvedIdempotencyKey
                ? MemoryLink::query()->where('idempotency_key', $resolvedIdempotencyKey)->first()
                : null;
            if ($retry) {
                return $retry;
            }

            throw $error;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function recall(string $query, string $scope, array $ids = [], int $topK = 6): array
    {
        $dataset = $this->datasetFor($scope, $ids);
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

        $semanticRanks = [];
        if ($this->cognee->enabled() && $scope !== 'session') {
            foreach ($this->cognee->search($dataset, trim($query), min(40, $topK * 4)) as $rank => $hit) {
                $dataId = trim((string) ($hit['document_id'] ?? ($hit['documentId'] ?? '')));
                if ($dataId !== '') {
                    $semanticRanks[$dataId] = min($semanticRanks[$dataId] ?? PHP_INT_MAX, $rank + 1);
                }
            }
        }

        // Rehydrate semantic candidates through the already authorized SQL
        // scope before they can influence ranking. This also prevents older,
        // highly relevant memories from being cut off by the lexical window.
        $semanticRows = $semanticRanks === []
            ? collect()
            : (clone $base)->whereIn('cognee_memory_id', array_keys($semanticRanks))->get();
        $rows = (clone $base)
            ->orderByDesc('importance')
            ->orderByDesc('recorded_at')
            ->limit(100)
            ->get()
            ->concat($semanticRows)
            ->unique('id')
            ->values();

        $terms = collect(preg_split('/[^\pL\pN_\.\-]+/u', mb_strtolower(trim($query))) ?: [])
            ->filter(fn ($term) => mb_strlen($term) >= 2)
            ->unique()
            ->values();

        return $rows->map(function (MemoryLink $row) use ($semanticRanks, $terms) {
            $semanticRank = $row->cognee_memory_id
                ? ($semanticRanks[$row->cognee_memory_id] ?? null)
                : null;
            $haystack = mb_strtolower($row->summary);
            $lexicalHits = $terms->filter(fn ($term) => str_contains($haystack, $term))->count();
            $lexical = $terms->isEmpty() ? 0.0 : $lexicalHits / $terms->count();
            $semantic = $semanticRank ? 1 / (60 + $semanticRank) : 0.0;
            $score = 0.5 * $lexical
                + 0.2 * (float) $row->importance
                + 0.2 * (float) $row->confidence
                + 0.1 * min(1, $semantic * 60);

            $payload = [
                'id' => $row->external_id ?: (string) $row->id,
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
            $query = MemoryLink::query()
                ->whereIn('dataset', $this->datasetsFor($scope, $ids))
                ->where(function (Builder $builder) use ($externalId) {
                    $builder->where('external_id', $externalId)
                        ->orWhere('meta->source_external_id', $externalId);
                });
            if ($scope !== 'global') {
                $query->where('user_id', $ids['user_id'] ?? null);
            }
            $links = $query->lockForUpdate()->get();
            if ($links->isEmpty()) {
                return false;
            }

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
            $dataset = $this->datasetFor($scope, $ids);
            $this->lockMemoryIdentity(
                $dataset,
                $ids['user_id'] ?? null,
                $ids['client_id'] ?? null,
                $externalId,
                '',
            );
            $query = MemoryLink::query()
                ->where('user_id', $ids['user_id'] ?? null)
                ->where('dataset', $dataset)
                ->where('external_id', $externalId)
                ->where('status', 'candidate');
            if (array_key_exists('client_id', $ids)) {
                $query->where('client_id', $ids['client_id']);
            }
            $link = $query->lockForUpdate()->first();
            if (! $link) {
                return null;
            }

            $meta = is_array($link->meta) ? $link->meta : [];
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

            $memoryKey = trim((string) ($link->feature_key ?: ($meta['memory_key'] ?? '')));
            $this->lockMemoryIdentity(
                $link->dataset,
                $link->user_id,
                $link->client_id,
                '',
                $memoryKey,
            );
            $superseded = null;
            if ($memoryKey !== '') {
                $superseded = MemoryLink::query()
                    ->where('user_id', $link->user_id)
                    ->where('dataset', $link->dataset)
                    ->where('status', 'active')
                    ->whereKeyNot($link->id)
                    ->where(function (Builder $query) use ($memoryKey) {
                        $query->where('feature_key', $memoryKey)
                            ->orWhere('meta->memory_key', $memoryKey);
                    })
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();
            }

            if ($superseded) {
                $superseded->update(['status' => 'superseded', 'staleness' => 'stale']);
                $this->enqueueDelete($superseded);
            }

            $projectionStatus = $this->projectionStatus(
                $this->cognee->enabled()
                    && in_array($link->retention, ['durable', 'permanent'], true)
                    && in_array($link->visibility, ['syncable', 'public'], true),
                $link->valid_from,
                $link->valid_until,
                $link->expires_at,
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

    public function improve(string $scope, array $ids = []): void
    {
        if (! $this->cognee->enabled()) {
            return;
        }
        $dataset = $this->datasetFor($scope, $ids);
        $this->enqueue('improve', $dataset, null, $ids['user_id'] ?? null, [], (string) now()->timestamp);
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
        if (! $this->cognee->enabled() || ! $link->cognee_memory_id) {
            return;
        }
        $this->enqueue('delete', $link->dataset, $link->id, $link->user_id, [
            'cognee_memory_id' => $link->cognee_memory_id,
            'content_hash' => $link->content_hash,
        ], (string) $link->cognee_memory_id);
    }

    /** @param array<string,mixed> $payload */
    private function enqueue(string $action, string $dataset, ?int $linkId, ?int $userId, array $payload, string $version): void
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

        if ($outbox->wasRecentlyCreated || $outbox->status === 'failed') {
            $outbox->update(['status' => 'queued', 'next_attempt_at' => null]);
            ProcessMemoryProjection::dispatch($outbox->id)->afterCommit();
        }
    }

    private function projectionStatus(
        bool $projectionRequested,
        mixed $validFrom,
        mixed $validUntil,
        mixed $expiresAt,
    ): string {
        if (! $projectionRequested) {
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

        return 'pending';
    }

    /**
     * Serialize first writes for the same logical identity. Row locks cannot
     * protect a feature/external ID before its first row exists, so PostgreSQL
     * uses transaction-scoped advisory locks. Other drivers retain the unique
     * constraint/idempotency fallback without executing vendor-specific SQL.
     */
    private function lockMemoryIdentity(
        string $dataset,
        mixed $userId,
        mixed $clientId,
        string $externalId,
        string $memoryKey,
    ): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $identities = [];
        if ($externalId !== '') {
            $identities[] = "external\0{$dataset}\0{$userId}\0{$clientId}\0{$externalId}";
        }
        if ($memoryKey !== '') {
            $identities[] = "feature\0{$dataset}\0{$userId}\0{$memoryKey}";
        }
        sort($identities, SORT_STRING);

        foreach ($identities as $identity) {
            $parts = unpack('Nfirst/Nsecond', substr(hash('sha256', $identity, true), 0, 8));
            $first = $this->signedInt32((int) $parts['first']);
            $second = $this->signedInt32((int) $parts['second']);
            DB::select(
                'SELECT pg_advisory_xact_lock(CAST(? AS integer), CAST(? AS integer))',
                [$first, $second],
            );
        }
    }

    private function signedInt32(int $value): int
    {
        return $value > 0x7FFFFFFF ? $value - 0x100000000 : $value;
    }
}
