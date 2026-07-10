<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/** Commit-scoped cache keys: advancing the repo version invalidates every dependent context deterministically. */
class ContextCache
{
    /** @param array<string,mixed> $request */
    public function remember(array $request, callable $resolver): array
    {
        $version = $this->version((int) ($request['user_id'] ?? 0), $request['repo_id'] ?? null, $request['branch'] ?? null);
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
        ], JSON_THROW_ON_ERROR));

        return Cache::remember('context:'.$fingerprint, now()->addMinutes(10), $resolver);
    }

    public function invalidate(int $userId, ?string $repoId, ?string $branch): void
    {
        $key = $this->versionKey($userId, $repoId, $branch);
        Cache::forever($key, $this->version($userId, $repoId, $branch) + 1);
    }

    private function version(int $userId, ?string $repoId, ?string $branch): int
    {
        return (int) Cache::get($this->versionKey($userId, $repoId, $branch), 1);
    }

    private function versionKey(int $userId, ?string $repoId, ?string $branch): string
    {
        return 'context-version:'.$userId.':'.($repoId ?: 'none').':'.($branch ?: 'none');
    }
}
