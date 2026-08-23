<?php

namespace App\Services;

use App\Models\Repository;

class GitFreshnessService
{
    /**
     * Builds freshness from user-owned, webhook-maintained repository metadata.
     * The API never executes Git against a request-controlled local path.
     *
     * @param  array<string,mixed>  $req
     * @return array<string,mixed>
     */
    public function inspect(array $req): array
    {
        $fallback = [
            'repo_id' => $req['repo_id'] ?? null,
            'branch' => $req['branch'] ?? null,
            'commit_sha' => $req['commit_sha'] ?? null,
            'changed_files' => $this->normalizeFiles($req['changed_files'] ?? []),
            'available' => false,
            'source' => 'request',
        ];

        $repoId = trim((string) ($req['repo_id'] ?? ''));
        if ($repoId === '' || empty($req['user_id'])) {
            return $fallback;
        }

        $repository = Repository::query()
            ->where('user_id', $req['user_id'])
            ->where(fn ($q) => $q->where('id', $repoId)->orWhere('external_id', $repoId)->orWhere('full_name', $repoId))
            ->first();
        if (! $repository) {
            return $fallback + ['source' => 'repository_not_found'];
        }
        $branch = (string) ($req['branch'] ?? $repository->default_branch);
        $head = $repository->branches()->where('name', $branch)->value('head_sha') ?: $repository->last_commit_sha;

        return [
            'repo_id' => (string) $repository->id,
            'branch' => $branch,
            'commit_sha' => $req['commit_sha'] ?? $head,
            'changed_files' => $fallback['changed_files'],
            'available' => $head !== null,
            'source' => 'repository_index',
        ];
    }

    /** @return array<int,string> */
    private function normalizeFiles(mixed $files): array
    {
        if (! is_array($files)) {
            return [];
        }

        return collect($files)
            ->filter(fn ($f) => is_string($f) && trim($f) !== '')
            ->map(fn ($f) => str_replace('\\', '/', trim($f)))
            ->unique()
            ->values()
            ->all();
    }
}
