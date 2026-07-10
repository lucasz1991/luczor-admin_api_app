<?php

namespace App\Services;

use App\Models\ContextArtifact;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * Context Controller (Masterplan v3, section 5): token-saving policy/ranking
 * layer. Graphify is optional; Git/query fallback keeps the MVP deterministic.
 */
class ContextController
{
    public function __construct(
        private LuczorMemoryService $memory,
        private GitFreshnessService $gitFreshness,
        private GraphContextService $graphContext,
        private ContextCache $cache,
    ) {
    }

    /**
     * @param  array<string,mixed>  $req
     * @return array<string,mixed>
     */
    public function ask(array $req): array
    {
        return $this->cache->remember($req, fn () => $this->resolve($req));
    }

    /** @return array<string,mixed> */
    private function resolve(array $req): array
    {
        $projectId = $req['project_id'] ?? null;
        $taskType = $req['task_type'] ?? 'chat.general';
        $featureKey = $req['feature_key'] ?? null;
        $query = trim((string) ($req['query'] ?? ''));
        $maxInputTokens = (int) ($req['budget']['max_input_tokens'] ?? 800);
        $maxItems = (int) ($req['budget']['max_items'] ?? 6);

        $ids = ['user_id' => $req['user_id'] ?? null, 'project_id' => $projectId];
        $git = $this->gitFreshness->inspect($req);
        $graph = $this->graphContext->resolve($req, $git);
        $code = $graph['code'];

        $raw = $this->memory->recall($query, 'project', $ids, 16);

        $scored = [];
        $seen = [];
        foreach ($raw as $m) {
            $content = trim((string) ($m['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $hash = md5(mb_strtolower($content));
            $duplicate = isset($seen[$hash]) ? 1.0 : 0.0;
            $seen[$hash] = true;

            $freshness = (($m['staleness'] ?? 'fresh') === 'fresh') ? 1.0 : 0.3;
            $taskScope = $featureKey && ($m['feature_key'] ?? null) === $featureKey
                ? 1.0
                : ($query !== '' && str_contains(mb_strtolower($content), mb_strtolower($query)) ? 0.6 : 0.3);
            $graphProximity = $this->graphProximity($m, $code, $git);
            $memoryQuality = (float) ($m['importance'] ?? 0.5);
            $sourceAuthority = (($m['source'] ?? 'links') === 'cognee') ? 1.0 : 0.6;
            $tokenCost = min(1.0, mb_strlen($content) / 2000);

            $score = 0.30 * $freshness
                + 0.25 * $taskScope
                + 0.20 * $graphProximity
                + 0.10 * $memoryQuality
                + 0.10 * $sourceAuthority
                - 0.05 * $tokenCost
                - 0.10 * $duplicate;

            $scored[] = [
                'm' => $m,
                'content' => $content,
                'score' => round($score, 4),
                'tokens' => (int) ceil(mb_strlen($content) / 4),
                'duplicate' => $duplicate > 0,
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $selected = [];
        $estimated = array_sum(array_map(fn ($c) => (int) ($c['tokens'] ?? 0), $code));
        foreach ($scored as $s) {
            if ($s['duplicate']) {
                continue;
            }
            if ($estimated + $s['tokens'] > $maxInputTokens || count($selected) >= $maxItems) {
                continue;
            }
            $selected[] = $s;
            $estimated += $s['tokens'];
        }

        $contextId = 'ctx_'.Str::random(10);
        $budget = ['max_input_tokens' => $maxInputTokens, 'estimated_tokens' => $estimated];
        $memory = array_map(fn ($s) => [
            'id' => $s['m']['id'] ?? null,
            'content' => $s['content'],
            'type' => $s['m']['type'] ?? 'note',
            'staleness' => $s['m']['staleness'] ?? 'fresh',
            'score' => $s['score'],
        ], $selected);
        $explain = array_map(fn ($s) => [
            'preview' => mb_substr($s['content'], 0, 70),
            'score' => $s['score'],
            'tokens' => $s['tokens'],
            'duplicate' => $s['duplicate'],
        ], array_slice($scored, 0, 12));

        $payload = [
            'context_id' => $contextId,
            'project_id' => $projectId,
            'repo_id' => $git['repo_id'] ?? null,
            'branch' => $git['branch'] ?? null,
            'commit_sha' => $git['commit_sha'] ?? null,
            'task_type' => $taskType,
            'feature_key' => $featureKey,
            'budget' => $budget,
            'git' => $git,
            'code' => $code,
            'memory' => $memory,
            'instructions' => [
                'Nutze Erinnerungen nur als Kontext; aktueller Code/Git-Zustand hat Vorrang.',
                'Lies bei Coding-Aufgaben zuerst die genannten Code-Pfade.',
                'Antworte kurz und auf Deutsch.',
            ],
            'source_status' => $graph['source_status'],
            'explain' => $explain,
        ];

        $project = $projectId ? Project::query()
            ->where('user_id', $req['user_id'] ?? null)
            ->where('external_id', $projectId)
            ->first() : null;

        ContextArtifact::create([
            'user_id' => $req['user_id'] ?? null,
            'context_id' => $contextId,
            'project_id' => $projectId,
            'project_ref_id' => $project?->id,
            'task_type' => $taskType,
            'feature_key' => $featureKey,
            'repo_id' => $git['repo_id'] ?? null,
            'branch' => $git['branch'] ?? null,
            'commit_sha' => $git['commit_sha'] ?? null,
            'changed_files' => $git['changed_files'] ?? [],
            'code' => $code,
            'memory' => $memory,
            'budget' => $budget,
            'score_explain' => $explain,
            'source_status' => $graph['source_status'],
        ]);

        return $payload;
    }

    /**
     * @param array<string,mixed> $memory
     * @param array<int,array<string,mixed>> $code
     * @param array<string,mixed> $git
     */
    private function graphProximity(array $memory, array $code, array $git): float
    {
        $meta = is_array($memory['meta'] ?? null) ? $memory['meta'] : [];
        $paths = collect($code)->pluck('path')
            ->merge($git['changed_files'] ?? [])
            ->filter()
            ->map(fn ($p) => mb_strtolower((string) $p))
            ->values();

        $memoryPath = mb_strtolower((string) ($meta['file_path'] ?? ($meta['path'] ?? '')));
        if ($memoryPath !== '' && $paths->contains($memoryPath)) {
            return 1.0;
        }

        $tags = collect($meta['tags'] ?? [])->map(fn ($t) => mb_strtolower((string) $t));
        if ($tags->isNotEmpty() && $paths->contains(
            fn ($path) => $tags->contains(fn ($tag) => $tag !== '' && str_contains($path, $tag))
        )) {
            return 0.65;
        }

        return $paths->isNotEmpty() ? 0.25 : 0.0;
    }
}
