<?php

namespace App\Services;

use App\Models\Persona;
use App\Models\Setting;
use App\Models\Skill;

class AssistantProfileService
{
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
