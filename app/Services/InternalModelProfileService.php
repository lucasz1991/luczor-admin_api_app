<?php

namespace App\Services;

use App\Models\Setting;

class InternalModelProfileService
{
    public const SETTING_KEY = 'internal_model_profiles';

    public const MODES = ['standard', 'external_agents'];

    public const PERSONALITY_LIMIT = 2000;

    public const SYSTEM_PROMPT_LIMIT = 4000;

    public function profiles(): array
    {
        $stored = Setting::getValue(self::SETTING_KEY, []);
        $profiles = [];
        foreach (self::MODES as $mode) {
            $profile = is_array($stored) && is_array($stored[$mode] ?? null) ? $stored[$mode] : [];
            $profiles[$mode] = [
                'enabled' => ($profile['enabled'] ?? false) === true,
                'personality' => (string) ($profile['personality'] ?? ''),
                'system_prompt' => (string) ($profile['system_prompt'] ?? ''),
            ];
        }

        return $profiles;
    }

    public function save(array $profiles): void
    {
        $normalized = [];
        foreach (self::MODES as $mode) {
            $normalized[$mode] = [
                'enabled' => (bool) $profiles[$mode]['enabled'],
                'personality' => $profiles[$mode]['personality'] ?? '',
                'system_prompt' => $profiles[$mode]['system_prompt'] ?? '',
            ];
        }
        Setting::putValue(self::SETTING_KEY, $normalized, [
            'group' => 'internal_models', 'label' => 'Profile interner Modelle', 'type' => 'json',
        ]);
    }
}
