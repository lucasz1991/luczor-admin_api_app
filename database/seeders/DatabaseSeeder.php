<?php

namespace Database\Seeders;

use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
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
    }
}
