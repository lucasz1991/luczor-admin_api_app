<?php

namespace App\Services;

use App\Models\OAuthConnection;
use App\Models\Project;
use App\Models\Repository;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;

class GithubService
{
    private const API = 'https://api.github.com';

    /** @return array<int,array<string,mixed>> */
    public function repositories(OAuthConnection $connection): array
    {
        return $this->request($connection, 'GET', '/user/repos?per_page=100&sort=updated');
    }

    /** @return array<string,mixed> */
    public function repository(OAuthConnection $connection, string $fullName): array
    {
        return $this->request($connection, 'GET', '/repos/'.$this->path($fullName));
    }

    /** @return array<string,mixed> */
    public function createBranch(OAuthConnection $connection, Repository $repository, string $branch, string $fromBranch, GitWritePolicy $policy): array
    {
        $this->assertBranch($branch);
        $policy->assertBranchWritable($repository, $branch);
        $source = $this->request($connection, 'GET', '/repos/'.$this->path($repository->full_name).'/git/ref/heads/'.$this->path($fromBranch));
        $sha = Arr::get($source, 'object.sha');
        abort_unless(is_string($sha) && $sha !== '', 422, 'Source branch has no commit SHA.');

        return $this->request($connection, 'POST', '/repos/'.$this->path($repository->full_name).'/git/refs', [
            'ref' => 'refs/heads/'.$branch,
            'sha' => $sha,
        ]);
    }

    /** @return array<string,mixed> */
    public function createPullRequest(OAuthConnection $connection, Repository $repository, string $title, string $head, string $base, ?string $body = null): array
    {
        $this->assertBranch($head);
        $this->assertBranch($base);

        return $this->request($connection, 'POST', '/repos/'.$this->path($repository->full_name).'/pulls', [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
        ]);
    }

    /** @return array<string,mixed> */
    public function putFile(OAuthConnection $connection, Repository $repository, string $branch, string $path, string $content, string $message, ?string $sha, GitWritePolicy $policy): array
    {
        $policy->assertBranchWritable($repository, $branch);
        abort_unless($path !== '' && ! str_contains($path, '..') && strlen($path) <= 1000, 422, 'Invalid repository path.');

        return $this->request($connection, 'PUT', '/repos/'.$this->path($repository->full_name).'/contents/'.$this->path($path), array_filter([
            'message' => $message,
            'content' => base64_encode($content),
            'branch' => $branch,
            'sha' => $sha,
        ], fn ($value) => $value !== null));
    }

    /** @return array{path:string,sha:?string,content:string,encoding:string} */
    public function file(OAuthConnection $connection, Repository $repository, string $path, ?string $branch = null): array
    {
        abort_unless($path !== '' && ! str_contains($path, '..') && strlen($path) <= 1000, 422, 'Invalid repository path.');
        $suffix = $branch ? '?ref='.rawurlencode($branch) : '';
        $result = $this->request($connection, 'GET', '/repos/'.$this->path($repository->full_name).'/contents/'.$this->path($path).$suffix);
        abort_unless(($result['type'] ?? null) === 'file' && is_string($result['content'] ?? null), 422, 'Only individual text files can be read through this tool.');
        $content = base64_decode(str_replace("\n", '', (string) $result['content']), true);
        abort_unless($content !== false && strlen($content) <= 512 * 1024, 422, 'The requested file is invalid or exceeds the MCP file limit.');

        return [
            'path' => (string) ($result['path'] ?? $path),
            'sha' => isset($result['sha']) ? (string) $result['sha'] : null,
            'content' => $content,
            'encoding' => 'utf-8-or-binary',
        ];
    }

    /** @return array{status:string,ahead_by:int,behind_by:int,files:array<int,array<string,mixed>>} */
    public function compare(OAuthConnection $connection, Repository $repository, string $base, string $head): array
    {
        $this->assertBranch($base);
        $this->assertBranch($head);
        $result = $this->request($connection, 'GET', '/repos/'.$this->path($repository->full_name).'/compare/'.rawurlencode($base).'...'.rawurlencode($head));

        return [
            'status' => (string) ($result['status'] ?? 'unknown'),
            'ahead_by' => (int) ($result['ahead_by'] ?? 0),
            'behind_by' => (int) ($result['behind_by'] ?? 0),
            'files' => array_slice(array_values(array_filter((array) ($result['files'] ?? []), 'is_array')), 0, 300),
        ];
    }

    public function import(OAuthConnection $connection, int $userId, string $fullName, ?Project $project = null): Repository
    {
        $remote = $this->repository($connection, $fullName);

        return Repository::updateOrCreate(
            ['user_id' => $userId, 'provider' => 'github', 'full_name' => (string) $remote['full_name']],
            [
                'project_id' => $project?->id,
                'external_id' => (string) ($remote['id'] ?? ''),
                'default_branch' => (string) ($remote['default_branch'] ?? 'main'),
                'last_commit_sha' => $remote['pushed_at'] ?? null,
                'meta' => [
                    'private' => (bool) ($remote['private'] ?? true),
                    'html_url' => $remote['html_url'] ?? null,
                    'permissions' => $remote['permissions'] ?? [],
                ],
            ]
        );
    }

    /** @return array<string,mixed>|array<int,array<string,mixed>> */
    private function request(OAuthConnection $connection, string $method, string $path, ?array $json = null): array
    {
        try {
            $response = (new Client(['base_uri' => self::API, 'timeout' => 20]))->request($method, $path, [
                'headers' => [
                    'Authorization' => 'Bearer '.$connection->access_token,
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent' => 'Luczor',
                ],
                'json' => $json,
                'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            abort(503, 'GitHub is currently unreachable.');
        }

        $body = json_decode((string) $response->getBody(), true) ?: [];
        abort_unless($response->getStatusCode() >= 200 && $response->getStatusCode() < 300, $response->getStatusCode(), (string) ($body['message'] ?? 'GitHub request failed.'));

        return $body;
    }

    private function path(string $value): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($value, '/'))));
    }

    private function assertBranch(string $branch): void
    {
        abort_unless(preg_match('/^(?!.*\.\.)(?!.*[~^:\\?*\[ ]).{1,160}$/', $branch), 422, 'Invalid branch name.');
    }
}
