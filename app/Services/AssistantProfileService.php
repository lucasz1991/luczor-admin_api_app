<?php

namespace App\Services;

use App\Models\Persona;
use App\Models\Setting;
use App\Models\Skill;

class AssistantProfileService
{
    /** Select guidance by explicit tags and task metadata; never alter tool authority. */
    public function forTask(?int $userId, string $query, string $taskType): array
    {
        $profile = $this->forUser($userId);
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($query.' '.($taskType === 'chat.general' ? '' : $taskType)), $matches);
        $terms = array_unique($matches[0]);
        $ranked = [];
        foreach ($profile['skills'] as $skill) {
            $metadata = mb_strtolower(implode(' ', [$skill['slug'], $skill['name'], $skill['description'], implode(' ', $skill['tags'])]));
            $universal = array_intersect(array_map('mb_strtolower', $skill['tags']), ['always', 'global', 'immer']) !== [];
            $score = ($universal ? 100 : 0) + count(array_filter($terms, fn ($term) => str_contains($metadata, $term)));
            if ($score > 0) {
                $ranked[] = ['skill' => $skill, 'score' => $score];
            }
        }
        usort($ranked, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['skill']['id'] <=> $b['skill']['id']));
        $profile['skills'] = [];
        $used = 0;
        if ($profile['persona']) {
            $profile['persona']['prompt'] = mb_substr($profile['persona']['prompt'], 0, 2000);
            $used = mb_strlen($profile['persona']['prompt']);
        }
        foreach (array_slice($ranked, 0, 3) as $entry) {
            $skill = $entry['skill'];
            $skill['prompt'] = mb_substr($skill['prompt'], 0, 1200);
            if ($used + mb_strlen($skill['prompt']) > 4800) {
                break;
            }
            $used += mb_strlen($skill['prompt']);
            $profile['skills'][] = $skill;
        }
        return $profile;
    }

    /** The same actor-scoped instructions are used by desktop and server proxy. */
    public function forUser(?int $userId): array
    {
        $persona = Persona::query()
            ->where('slug', (string) Setting::getValue('active_persona', ''))
            ->where('active', true)
            ->first(['slug', 'name', 'prompt']);
        $skills = Skill::active()
            ->where('kind', 'prompt')
            ->where(fn ($query) => $query->whereNull('user_id')->when($userId !== null, fn ($owned) => $owned->orWhere('user_id', $userId)))
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'slug', 'name', 'description', 'kind', 'prompt', 'tags'])
            ->filter(fn (Skill $skill) => trim((string) $skill->prompt) !== '')
            ->map(fn (Skill $skill) => array_replace($skill->toArray(), [
                'description' => (string) ($skill->description ?? ''),
                'tags' => array_values(array_filter($skill->tags ?? [], 'is_string')),
            ]))
            ->values()->toArray();
        $profile = ['persona' => $persona?->toArray(), 'skills' => $skills];

        return $profile + ['revision' => hash('sha256', json_encode($profile, JSON_THROW_ON_ERROR))];
    }
}
