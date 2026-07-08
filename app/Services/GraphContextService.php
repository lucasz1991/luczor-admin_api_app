<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GraphContextService
{
    /**
     * Resolve code-context candidates. Graphify is used when configured; a
     * deterministic Git/query fallback keeps the MVP useful before Graphify is
     * deployed.
     *
     * @param  array<string,mixed>  $req
     * @param  array<string,mixed>  $git
     * @return array{code: array<int,array<string,mixed>>, source_status: array<string,mixed>}
     */
    public function resolve(array $req, array $git): array
    {
        $query = (string) ($req['query'] ?? '');
        $configured = trim((string) config('luczor.graphify.base_url', '')) !== '';
        $graphify = $configured ? $this->queryGraphify($req, $git) : [];

        $fallback = $this->fallbackCandidates($query, $git, $req['code'] ?? []);
        $items = collect(array_merge($graphify, $fallback))
            ->filter(fn ($item) => is_array($item) && ! empty($item['path']))
            ->map(fn ($item) => [
                'path' => str_replace('\\', '/', (string) $item['path']),
                'reason' => (string) ($item['reason'] ?? 'candidate'),
                'score' => round((float) ($item['score'] ?? 0.5), 4),
                'tokens' => (int) ($item['tokens'] ?? 0),
                'meta' => $item['meta'] ?? null,
            ])
            ->unique('path')
            ->sortByDesc('score')
            ->take((int) ($req['code_limit'] ?? 12))
            ->values()
            ->all();

        return [
            'code' => $items,
            'source_status' => [
                'graphify' => $configured ? (empty($graphify) ? 'queried_empty' : 'queried') : 'disabled',
                'fallback' => empty($fallback) ? 'none' : 'git_or_query',
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function queryGraphify(array $req, array $git): array
    {
        $base = rtrim((string) config('luczor.graphify.base_url', ''), '/');
        if ($base === '') {
            return [];
        }

        try {
            $res = Http::timeout((int) config('luczor.graphify.timeout', 10))
                ->post($base.'/api/v1/impact', [
                    'query' => $req['query'] ?? '',
                    'project_id' => $req['project_id'] ?? null,
                    'repo_id' => $git['repo_id'] ?? ($req['repo_id'] ?? null),
                    'branch' => $git['branch'] ?? null,
                    'commit_sha' => $git['commit_sha'] ?? null,
                    'changed_files' => $git['changed_files'] ?? [],
                ]);

            if (! $res->ok()) {
                return [];
            }

            $json = $res->json();
            $hits = $json['data'] ?? ($json['hits'] ?? []);
            if (! is_array($hits)) {
                return [];
            }

            return collect($hits)->map(fn ($hit) => [
                'path' => $hit['path'] ?? ($hit['file_path'] ?? null),
                'reason' => $hit['reason'] ?? 'graphify_impact',
                'score' => $hit['score'] ?? 0.85,
                'tokens' => $hit['tokens'] ?? 0,
                'meta' => $hit['meta'] ?? null,
            ])->filter(fn ($hit) => ! empty($hit['path']))->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function fallbackCandidates(string $query, array $git, mixed $explicitCode): array
    {
        $items = [];

        foreach (($git['changed_files'] ?? []) as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }
            $items[] = [
                'path' => $path,
                'reason' => 'git_changed_file',
                'score' => 0.74,
                'tokens' => 0,
            ];
        }

        if (is_array($explicitCode)) {
            foreach ($explicitCode as $item) {
                if (is_string($item)) {
                    $items[] = ['path' => $item, 'reason' => 'request_code_hint', 'score' => 0.68];
                } elseif (is_array($item) && ! empty($item['path'])) {
                    $items[] = [
                        'path' => $item['path'],
                        'reason' => $item['reason'] ?? 'request_code_hint',
                        'score' => $item['score'] ?? 0.68,
                        'tokens' => $item['tokens'] ?? 0,
                        'meta' => $item['meta'] ?? null,
                    ];
                }
            }
        }

        if (preg_match_all('/[\w.\-\/\\\\]+\.(php|ts|vue|rs|js|json|md|css)/i', $query, $matches)) {
            foreach ($matches[0] as $path) {
                $items[] = [
                    'path' => (string) Str::of($path)->replace('\\', '/'),
                    'reason' => 'query_file_mention',
                    'score' => 0.62,
                    'tokens' => 0,
                ];
            }
        }

        return $items;
    }
}
