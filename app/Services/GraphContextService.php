<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Server boundary for local repository evidence.
 *
 * The repository graph is indexed and stored only by the desktop. The server
 * accepts bounded, content-free path hints for the current request and never
 * persists or re-indexes that local graph.
 */
class GraphContextService
{
    /** @param array<string,mixed> $snapshot */
    public function index(array $snapshot): void
    {
        // Deliberate no-op: GitHub metadata remains in repository history, while
        // ASTs, symbols, graph edges and local checkout paths stay on-device.
    }

    /**
     * @param  array<string,mixed>  $req
     * @param  array<string,mixed>  $git
     * @return array{code: array<int,array<string,mixed>>, persistent_code: array<int,array<string,mixed>>, source_status: array<string,mixed>}
     */
    public function resolve(array $req, array $git): array
    {
        $query = (string) ($req['query'] ?? '');
        $localHints = $this->localHints($req['code'] ?? []);
        $fallback = $this->fallbackCandidates($query, $git);
        $limit = max(1, min(30, (int) ($req['code_limit'] ?? 12)));

        $items = collect(array_merge($localHints, $fallback))
            ->filter(fn ($item) => ! empty($item['path']))
            ->unique('path')
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();

        return [
            'code' => $items,
            'persistent_code' => collect($items)->reject(fn ($item) => (bool) ($item['transient'] ?? false))->values()->all(),
            'source_status' => [
                'local_graph' => $localHints === [] ? 'not_provided' : 'transient_hints',
                'server_graph' => 'disabled_local_only',
                'fallback' => $fallback === [] ? 'none' : 'git_or_query',
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function localHints(mixed $explicitCode): array
    {
        if (! is_array($explicitCode)) {
            return [];
        }

        $items = [];
        foreach ($explicitCode as $item) {
            $item = is_string($item) ? ['path' => $item] : $item;
            if (! is_array($item)) {
                continue;
            }
            $path = $this->relativePath($item['path'] ?? null);
            if ($path === null) {
                continue;
            }
            $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
            $items[] = [
                'path' => $path,
                'reason' => mb_substr((string) ($item['reason'] ?? 'local_graph_hint'), 0, 80),
                'score' => round(max(0, min(1, (float) ($item['score'] ?? 0.68))), 4),
                'tokens' => 0,
                'meta' => array_filter([
                    'evidence_id' => $meta['evidence_id'] ?? null,
                    'content_hash' => $meta['content_hash'] ?? null,
                    'symbols' => is_array($meta['symbols'] ?? null) ? array_slice($meta['symbols'], 0, 20) : null,
                ], fn ($value) => $value !== null),
                'transient' => true,
            ];
        }

        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function fallbackCandidates(string $query, array $git): array
    {
        $items = [];
        foreach (($git['changed_files'] ?? []) as $candidate) {
            $path = $this->relativePath($candidate);
            if ($path !== null) {
                $items[] = [
                    'path' => $path,
                    'reason' => 'git_changed_file',
                    'score' => 0.74,
                    'tokens' => 0,
                    'meta' => [],
                    'transient' => false,
                ];
            }
        }

        $normalizedQuery = str_replace('\\', '/', $query);
        if (preg_match_all('~[\w./-]+\.(php|ts|vue|rs|js|json|md|css)~i', $normalizedQuery, $matches)) {
            foreach ($matches[0] as $candidate) {
                $path = $this->relativePath((string) Str::of($candidate)->replace('\\', '/'));
                if ($path !== null) {
                    $items[] = [
                        'path' => $path,
                        'reason' => 'query_file_mention',
                        'score' => 0.62,
                        'tokens' => 0,
                        'meta' => [],
                        'transient' => false,
                    ];
                }
            }
        }

        return $items;
    }

    private function relativePath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path)) {
            return null;
        }
        $segments = array_values(array_filter(explode('/', $path), fn ($segment) => $segment !== '' && $segment !== '.'));
        if ($segments === [] || in_array('..', $segments, true)) {
            return null;
        }

        return mb_substr(implode('/', $segments), 0, 500);
    }
}
