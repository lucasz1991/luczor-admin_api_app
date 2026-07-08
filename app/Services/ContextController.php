<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Context Controller (Masterplan v3, §5): the token-saving policy/ranking layer.
 * For Luczor's current scope it ranks MEMORY items (Graphify/Git come later)
 * into a small, budgeted context package using the context_score formula.
 */
class ContextController
{
    public function __construct(private LuczorMemoryService $memory)
    {
    }

    /**
     * @param  array<string,mixed>  $req
     * @return array<string,mixed>
     */
    public function ask(array $req): array
    {
        $projectId = $req['project_id'] ?? null;
        $taskType = $req['task_type'] ?? 'chat.general';
        $featureKey = $req['feature_key'] ?? null;
        $query = trim((string) ($req['query'] ?? ''));
        $maxInputTokens = (int) ($req['budget']['max_input_tokens'] ?? 800); // memory class budget
        $maxItems = (int) ($req['budget']['max_items'] ?? 6);

        $ids = ['user_id' => $req['user_id'] ?? null, 'project_id' => $projectId];

        // Recall a superset, then rank / dedupe / budget down.
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
            $graphProximity = 0.0; // no Graphify yet
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
        $estimated = 0;
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

        return [
            'context_id' => 'ctx_'.Str::random(10),
            'project_id' => $projectId,
            'task_type' => $taskType,
            'feature_key' => $featureKey,
            'budget' => ['max_input_tokens' => $maxInputTokens, 'estimated_tokens' => $estimated],
            'memory' => array_map(fn ($s) => [
                'id' => $s['m']['id'] ?? null,
                'content' => $s['content'],
                'type' => $s['m']['type'] ?? 'note',
                'staleness' => $s['m']['staleness'] ?? 'fresh',
                'score' => $s['score'],
            ], $selected),
            'instructions' => [
                'Nutze die Erinnerungen nur als Kontext; der aktuelle Zustand hat Vorrang.',
                'Antworte kurz und auf Deutsch.',
            ],
            'explain' => array_map(fn ($s) => [
                'preview' => mb_substr($s['content'], 0, 70),
                'score' => $s['score'],
                'tokens' => $s['tokens'],
                'duplicate' => $s['duplicate'],
            ], array_slice($scored, 0, 12)),
        ];
    }
}
