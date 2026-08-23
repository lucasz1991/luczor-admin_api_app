<?php

namespace App\Services;

use App\Models\RetrievalCacheEntry;
use Illuminate\Support\Facades\Cache;

/** Commit-scoped cache keys: advancing the repo version invalidates every dependent context deterministically. */
class ContextCache
{
    /** @param array<string,mixed> $request */
    public function remember(array $request, callable $resolver): array
    {
        // Repository-graph hints are approved for one request only. Never put
        // a response containing them into a reusable server cache.
        if (! empty($request['code'])) {
            return $resolver();
        }

        $version = $this->version((int) ($request['user_id'] ?? 0), $request['repo_id'] ?? null, $request['branch'] ?? null);
        $memoryVersion = $this->memoryVersion((int) ($request['user_id'] ?? 0), $request['project_id'] ?? null);
        $fingerprint = hash('sha256', json_encode([
            'user' => $request['user_id'] ?? null,
            'project' => $request['project_id'] ?? null,
            'repo' => $request['repo_id'] ?? null,
            'branch' => $request['branch'] ?? null,
            'commit' => $request['commit_sha'] ?? null,
            'query' => $request['query'] ?? null,
            'task' => $request['task_type'] ?? null,
            'feature' => $request['feature_key'] ?? null,
            'budget' => $request['budget'] ?? null,
            'changed' => $request['changed_files'] ?? null,
            'version' => $version,
            'memory_version' => $memoryVersion,
        ], JSON_THROW_ON_ERROR));

        $cacheKey = 'context:'.$fingerprint;
        $hit = Cache::has($cacheKey);
        $result = Cache::remember($cacheKey, now()->addMinutes(10), $resolver);
        $entry = RetrievalCacheEntry::firstOrNew(['cache_key' => $fingerprint]);
        $entry->fill([
            'cache_type' => 'context',
            'user_id' => $request['user_id'] ?? null,
            'repository_id' => is_numeric($request['repo_id'] ?? null) ? (int) $request['repo_id'] : null,
            'commit_sha' => $request['commit_sha'] ?? null,
            'content_hash' => hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'payload' => ['task_type' => $request['task_type'] ?? null, 'feature_key' => $request['feature_key'] ?? null],
            'hit_count' => (int) ($entry->hit_count ?? 0) + ($hit ? 1 : 0),
            'last_hit_at' => $hit ? now() : $entry->last_hit_at,
            'expires_at' => now()->addMinutes(10),
        ])->save();

        return $result;
    }

    public function invalidate(int $userId, ?string $repoId, ?string $branch): void
    {
        $key = $this->versionKey($userId, $repoId, $branch);
        Cache::forever($key, $this->version($userId, $repoId, $branch) + 1);
    }

    public function invalidateMemory(int $userId, ?string $projectId): void
    {
        $key = $this->memoryVersionKey($userId, $projectId);
        Cache::forever($key, $this->memoryVersion($userId, $projectId) + 1);
    }

    private function version(int $userId, ?string $repoId, ?string $branch): int
    {
        return (int) Cache::get($this->versionKey($userId, $repoId, $branch), 1);
    }

    private function versionKey(int $userId, ?string $repoId, ?string $branch): string
    {
        return 'context-version:'.$userId.':'.($repoId ?: 'none').':'.($branch ?: 'none');
    }

    private function memoryVersion(int $userId, ?string $projectId): int
    {
        return (int) Cache::get($this->memoryVersionKey($userId, $projectId), 1);
    }

    private function memoryVersionKey(int $userId, ?string $projectId): string
    {
        return 'context-memory-version:'.$userId.':'.($projectId ?: 'none');
    }
}
