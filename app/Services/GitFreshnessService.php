<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class GitFreshnessService
{
    /**
     * Build the Git freshness block for a context package. If no usable repo
     * path is provided, explicit request fields are returned and marked as
     * caller-provided so the endpoint remains deterministic and testable.
     *
     * @param  array<string,mixed>  $req
     * @return array<string,mixed>
     */
    public function inspect(array $req): array
    {
        $repoPath = is_string($req['repo_path'] ?? null) ? trim($req['repo_path']) : '';
        $fallback = [
            'repo_id' => $req['repo_id'] ?? null,
            'branch' => $req['branch'] ?? null,
            'commit_sha' => $req['commit_sha'] ?? null,
            'changed_files' => $this->normalizeFiles($req['changed_files'] ?? []),
            'available' => false,
            'source' => 'request',
        ];

        if ($repoPath === '' || ! is_dir($repoPath)) {
            return $fallback;
        }

        $inside = $this->git($repoPath, ['rev-parse', '--is-inside-work-tree']);
        if ($inside !== 'true') {
            return $fallback + ['source' => 'invalid_repo'];
        }

        $branch = $this->git($repoPath, ['rev-parse', '--abbrev-ref', 'HEAD']);
        $commit = $this->git($repoPath, ['rev-parse', 'HEAD']);
        $changed = $this->git($repoPath, ['diff', '--name-only', 'HEAD']);
        $changedFiles = $changed !== null && $changed !== ''
            ? preg_split('/\R+/', $changed) ?: []
            : $fallback['changed_files'];

        return [
            'repo_id' => $req['repo_id'] ?? basename($repoPath),
            'branch' => $branch ?: $fallback['branch'],
            'commit_sha' => $commit ?: $fallback['commit_sha'],
            'changed_files' => $this->normalizeFiles($changedFiles),
            'available' => true,
            'source' => 'git',
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

    /** @param array<int,string> $args */
    private function git(string $repoPath, array $args): ?string
    {
        $process = new Process(array_merge(['git', '-C', $repoPath], $args));
        $process->setTimeout(5);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }
}
