<?php

namespace Database\Seeders;

use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@luczor.local'],
            [
                'name' => 'Luczor Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'status' => true,
                'email_verified_at' => now(),
            ]
        );

        $profiles = [
            ['name' => 'Luczor Default', 'slug' => 'luczor-default', 'provider' => 'openrouter', 'model_id' => '@preset/luczor', 'purpose' => 'general', 'temperature' => 0.20, 'max_tokens' => 1200],
            ['name' => 'Planner Primary', 'slug' => 'planner-primary', 'provider' => 'openrouter', 'model_id' => '@preset/luczor', 'purpose' => 'planner', 'temperature' => 0.15, 'max_tokens' => 1600],
            ['name' => 'Verifier Primary', 'slug' => 'verifier-primary', 'provider' => 'openrouter', 'model_id' => '@preset/luczor', 'purpose' => 'verifier', 'temperature' => 0.10, 'max_tokens' => 1000],
        ];

        foreach ($profiles as $profile) {
            ModelProfile::updateOrCreate(['slug' => $profile['slug']], $profile + ['active' => true]);
        }

        $useCases = [
            ['name' => 'Chat', 'slug' => 'chat', 'description' => 'Normale Assistenzantworten und Mini-Chat.'],
            ['name' => 'Planner', 'slug' => 'planner', 'description' => 'Aufgabenplanung und Tool-Entscheidungen.'],
            ['name' => 'Verifier', 'slug' => 'verifier', 'description' => 'Ergebnis- und Sicherheitsprüfung.'],
            ['name' => 'Vision', 'slug' => 'vision', 'description' => 'Bildschirm- und Screenshot-Auswertung.'],
            ['name' => 'Speech to Text', 'slug' => 'stt', 'description' => 'Transkription von Spracheingaben.'],
            ['name' => 'Text to Speech', 'slug' => 'tts', 'description' => 'Sprachausgabe.'],
        ];

        foreach ($useCases as $useCase) {
            $case = ModelUseCase::updateOrCreate(['slug' => $useCase['slug']], $useCase + ['active' => true]);
            $profileSlug = $useCase['slug'] === 'planner' ? 'planner-primary' : ($useCase['slug'] === 'verifier' ? 'verifier-primary' : 'luczor-default');
            $profile = ModelProfile::where('slug', $profileSlug)->first();

            if ($profile) {
                ModelUseCaseEntry::updateOrCreate(
                    ['model_use_case_id' => $case->id, 'model_profile_id' => $profile->id],
                    ['sort_order' => 1, 'active' => true]
                );
            }
        }

        // Server-managed client defaults (editable in the dashboard).
        $settings = [
            ['key' => 'assistant_name', 'value' => 'Luczor', 'group' => 'client', 'label' => 'Assistenten-Name', 'type' => 'string'],
            ['key' => 'default_mode', 'value' => 'observe', 'group' => 'client', 'label' => 'Standard-Modus (observe|act|unrestricted)', 'type' => 'string'],
            ['key' => 'allow_unrestricted', 'value' => true, 'group' => 'client', 'label' => 'Vollzugriff erlauben', 'type' => 'bool'],
            ['key' => 'ui_accent', 'value' => 'cyan', 'group' => 'client', 'label' => 'Akzentfarbe', 'type' => 'string'],
            ['key' => 'memory_inject', 'value' => true, 'group' => 'client', 'label' => 'Erinnerungen einblenden', 'type' => 'bool'],
            ['key' => 'memory_inject_count', 'value' => 5, 'group' => 'client', 'label' => 'Anzahl Erinnerungen', 'type' => 'number'],
            ['key' => 'sync_auto', 'value' => false, 'group' => 'client', 'label' => 'Auto-Sync', 'type' => 'bool'],
            ['key' => 'sync_auto_threshold', 'value' => 20, 'group' => 'client', 'label' => 'Auto-Sync Schwelle', 'type' => 'number'],
        ];

        foreach ($settings as $s) {
            Setting::updateOrCreate(
                ['key' => $s['key']],
                ['value' => ['v' => $s['value']], 'group' => $s['group'], 'label' => $s['label'], 'type' => $s['type']]
            );
        }
    }
}
