<?php

namespace App\Services;

use App\Models\MemoryLink;
use App\Services\Cognee\CogneeClient;
use Illuminate\Support\Str;

/**
 * Server-side memory facade (Masterplan v3).
 *
 * System-of-Record: the `memory_links` table (stable pointers + summary +
 * staleness). Engine: Cognee (semantic recall / graph). remember() writes the
 * link row ALWAYS and Cognee best-effort; recall() prefers Cognee and falls
 * back to the summaries in memory_links so the system stays useful before
 * Cognee is deployed.
 */
class LuczorMemoryService
{
    public function __construct(private ?CogneeClient $cognee = null)
    {
        $this->cognee ??= CogneeClient::fromConfig();
    }

    public function cogneeEnabled(): bool
    {
        return $this->cognee->enabled();
    }

    /** Namespaced dataset key. Scopes: private|project|skill|agent|global. */
    public function datasetFor(string $scope, array $ids = []): string
    {
        $user = $ids['user_id'] ?? 'server';

        return match ($scope) {
            'project' => "user:{$user}:projects:".($ids['project_id'] ?? 'default'),
            'skill' => "user:{$user}:skills",
            'agent' => 'agent:'.($ids['agent_id'] ?? 'default').':runs',
            'global' => 'global:knowledge',
            default => "user:{$user}:private",
        };
    }

    /** @param array<string,mixed> $data */
    public function remember(array $data): MemoryLink
    {
        $scope = $data['scope'] ?? 'project';
        $ids = [
            'user_id' => $data['user_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'agent_id' => $data['agent_id'] ?? null,
        ];
        $dataset = $this->datasetFor($scope, $ids);
        $content = trim((string) ($data['content'] ?? ''));
        $visibility = $data['visibility'] ?? 'syncable';

        $cogneeId = null;
        if ($content !== '' && $visibility !== 'private' && $this->cognee->enabled()) {
            $res = $this->cognee->add($dataset, $content, [
                'scope' => $scope,
                'project_id' => $data['project_id'] ?? null,
                'feature_key' => $data['feature_key'] ?? null,
                'type' => $data['type'] ?? 'note',
            ]);
            $cogneeId = $res['id'] ?? ($res['memory_id'] ?? null);
        }

        return MemoryLink::updateOrCreate(
            [
                'client_id' => $data['client_id'] ?? null,
                'external_id' => $data['external_id'] ?? (string) Str::uuid(),
            ],
            [
                'scope' => $scope,
                'dataset' => $dataset,
                'project_id' => $data['project_id'] ?? null,
                'feature_key' => $data['feature_key'] ?? null,
                'session_id' => $data['session_id'] ?? null,
                'cognee_memory_id' => $cogneeId,
                'type' => $data['type'] ?? 'note',
                'visibility' => $visibility,
                'staleness' => 'fresh',
                'importance' => (float) ($data['importance'] ?? 0.5),
                'summary' => mb_substr($content, 0, 500),
                'meta' => $data['meta'] ?? null,
            ]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function recall(string $query, string $scope, array $ids = [], int $topK = 6): array
    {
        $dataset = $this->datasetFor($scope, $ids);
        $topK = max(1, min(20, $topK));

        if ($this->cognee->enabled()) {
            $hits = $this->cognee->search($dataset, $query, $topK);
            if (! empty($hits)) {
                return array_map(fn ($h) => [
                    'id' => $h['id'] ?? null,
                    'content' => $h['text'] ?? ($h['content'] ?? ($h['metadata']['content'] ?? '')),
                    'type' => $h['metadata']['type'] ?? 'note',
                    'importance' => (float) ($h['metadata']['importance'] ?? ($h['score'] ?? 0.5)),
                    'staleness' => 'fresh',
                    'feature_key' => $h['metadata']['feature_key'] ?? null,
                    'source' => 'cognee',
                ], $hits);
            }
        }

        // Fallback: degraded recall from the memory_links summaries.
        $q = trim($query);
        $base = MemoryLink::query()->where('dataset', $dataset)->where('visibility', '!=', 'private');

        $rows = (clone $base)
            ->when($q !== '', fn ($qb) => $qb->where('summary', 'like', '%'.$q.'%'))
            ->orderByDesc('importance')->orderByDesc('updated_at')
            ->limit($topK)->get();

        if ($rows->isEmpty()) {
            $rows = (clone $base)->orderByDesc('importance')->orderByDesc('updated_at')->limit($topK)->get();
        }

        return $rows->map(fn (MemoryLink $r) => [
            'id' => $r->external_id ?: (string) $r->id,
            'content' => $r->summary,
            'type' => $r->type,
            'importance' => (float) $r->importance,
            'staleness' => $r->staleness,
            'feature_key' => $r->feature_key,
            'source' => 'links',
        ])->all();
    }

    public function forget(string $scope, string $externalId, array $ids = [], ?string $clientId = null): void
    {
        $dataset = $this->datasetFor($scope, $ids);

        $link = MemoryLink::query()
            ->where('dataset', $dataset)
            ->where('external_id', $externalId)
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->first();

        if ($link) {
            if ($this->cognee->enabled() && $link->cognee_memory_id) {
                $this->cognee->delete($dataset, $link->cognee_memory_id);
            }
            $link->delete();
        }
    }

    public function improve(string $scope, array $ids = []): void
    {
        if ($this->cognee->enabled()) {
            $this->cognee->cognify($this->datasetFor($scope, $ids));
        }
    }

    public function pendingCount(?string $clientId = null): int
    {
        return MemoryLink::query()
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->count();
    }
}
