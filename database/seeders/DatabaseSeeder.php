<?php

namespace Database\Seeders;

use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\Setting;
use App\Models\ContextStrategy;
use App\Models\NetworkPolicy;
use App\Models\PromptTemplate;
use App\Models\AgentProfile;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(['slug' => 'luczor-system'], ['name' => 'Luczor System', 'plan' => 'internal', 'status' => 'active']);
        $admin = User::firstOrCreate(
            ['email' => 'admin@luczor.local'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Luczor Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'status' => true,
                'email_verified_at' => now(),
            ]
        );
        if (! $admin->tenant_id) $admin->update(['tenant_id' => $tenant->id]);

        $profiles = [
            ['name' => 'Chat Fast', 'slug' => 'chat-fast', 'provider' => 'openrouter', 'model_id' => 'google/gemini-3-flash-preview', 'purpose' => 'chat', 'temperature' => 0.25, 'max_tokens' => 1400],
            ['name' => 'Coding Agent Primary', 'slug' => 'coding-agent-primary', 'provider' => 'openrouter', 'model_id' => 'anthropic/claude-sonnet-5', 'purpose' => 'coding', 'temperature' => 0.15, 'max_tokens' => 2400],
            ['name' => 'Planner Deep', 'slug' => 'planner-deep', 'provider' => 'openrouter', 'model_id' => 'google/gemini-3-pro-preview', 'purpose' => 'planner', 'temperature' => 0.20, 'max_tokens' => 2600],
            ['name' => 'Verifier Strict', 'slug' => 'verifier-strict', 'provider' => 'openrouter', 'model_id' => 'openai/gpt-5.1', 'purpose' => 'verifier', 'temperature' => 0.05, 'max_tokens' => 1600],
            ['name' => 'Vision Reasoner', 'slug' => 'vision-reasoner', 'provider' => 'openrouter', 'model_id' => 'google/gemini-3-pro-preview', 'purpose' => 'vision', 'temperature' => 0.15, 'max_tokens' => 2200],
            ['name' => 'Local Speech To Text', 'slug' => 'local-stt-whisper', 'provider' => 'local', 'model_id' => 'whisper.cpp', 'purpose' => 'stt', 'temperature' => 0.00, 'max_tokens' => 1],
            ['name' => 'Local Text To Speech', 'slug' => 'local-tts-piper', 'provider' => 'local', 'model_id' => 'piper', 'purpose' => 'tts', 'temperature' => 0.00, 'max_tokens' => 1],
        ];

        foreach ($profiles as $profile) {
            ModelProfile::updateOrCreate(['slug' => $profile['slug']], $profile + ['active' => true]);
        }

        $useCases = [
            ['name' => 'Chat', 'slug' => 'chat', 'description' => 'Normale Assistenzantworten und Mini-Chat.'],
            ['name' => 'Coding Agent', 'slug' => 'coding', 'description' => 'Coding, Tool-Use, Bugfixes und Refactoring.'],
            ['name' => 'Planner', 'slug' => 'planner', 'description' => 'Aufgabenplanung und Tool-Entscheidungen.'],
            ['name' => 'Verifier', 'slug' => 'verifier', 'description' => 'Ergebnis- und Sicherheitspruefung.'],
            ['name' => 'Vision', 'slug' => 'vision', 'description' => 'Bildschirm- und Screenshot-Auswertung.'],
            ['name' => 'Speech to Text', 'slug' => 'stt', 'description' => 'Lokale Transkription mit whisper.cpp.'],
            ['name' => 'Text to Speech', 'slug' => 'tts', 'description' => 'Lokale Sprachausgabe mit Piper.'],
        ];

        $fallbacks = [
            'chat' => ['chat-fast', 'verifier-strict'],
            'coding' => ['coding-agent-primary', 'verifier-strict', 'planner-deep'],
            'planner' => ['planner-deep', 'coding-agent-primary', 'verifier-strict'],
            'verifier' => ['verifier-strict', 'coding-agent-primary'],
            'vision' => ['vision-reasoner', 'verifier-strict'],
            'stt' => ['local-stt-whisper'],
            'tts' => ['local-tts-piper'],
        ];

        foreach ($useCases as $useCase) {
            $case = ModelUseCase::updateOrCreate(['slug' => $useCase['slug']], $useCase + ['active' => true]);
            foreach (($fallbacks[$useCase['slug']] ?? ['chat-fast']) as $idx => $profileSlug) {
                $profile = ModelProfile::where('slug', $profileSlug)->first();
                if (! $profile) {
                    continue;
                }

                ModelUseCaseEntry::updateOrCreate(
                    ['model_use_case_id' => $case->id, 'model_profile_id' => $profile->id],
                    ['sort_order' => $idx + 1, 'active' => true]
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
            ['key' => 'client_history_token_budget', 'value' => 2400, 'group' => 'context', 'label' => 'Chat-Historie Tokenbudget', 'type' => 'number'],
            ['key' => 'sync_auto', 'value' => false, 'group' => 'client', 'label' => 'Auto-Sync', 'type' => 'bool'],
            ['key' => 'sync_auto_threshold', 'value' => 20, 'group' => 'client', 'label' => 'Auto-Sync Schwelle', 'type' => 'number'],
            ['key' => 'chat_auto_speech', 'value' => false, 'group' => 'voice', 'label' => 'Antworten automatisch vorlesen', 'type' => 'bool'],
            ['key' => 'voice_mode', 'value' => 'wakeword', 'group' => 'voice', 'label' => 'Lokaler Eingabemodus', 'type' => 'string'],
            ['key' => 'voice_wake_word', 'value' => 'luczor', 'group' => 'voice', 'label' => 'Wake-Word', 'type' => 'string'],
            ['key' => 'voice_local_stt_language', 'value' => 'de', 'group' => 'voice', 'label' => 'STT-Sprache', 'type' => 'string'],
        ];

        foreach ($settings as $s) {
            Setting::updateOrCreate(
                ['key' => $s['key']],
                ['value' => ['v' => $s['value']], 'group' => $s['group'], 'label' => $s['label'], 'type' => $s['type']]
            );
        }

        PromptTemplate::updateOrCreate(
            ['key' => 'luczor.system', 'version' => 1],
            ['task_type' => null, 'body' => 'Du bist Luczor. Nutze den bereitgestellten Kontext sparsam, beachte Policies und dokumentiere Tool-Aktionen.', 'status' => 'active']
        );

        ContextStrategy::updateOrCreate(
            ['key' => 'context.memory_code_budgeted'],
            ['name' => 'Memory + Code budgetiert', 'status' => 'active', 'config' => [
                'git_tokens' => 250, 'graph_tokens' => 1000, 'memory_tokens' => 600,
                'raw_file_tokens' => 3500, 'tool_output_tokens' => 1000, 'deduplicate' => true,
            ]]
        );

        NetworkPolicy::updateOrCreate(
            ['key' => 'proxy.openrouter.default'],
            ['name' => 'OpenRouter Default', 'status' => 'active', 'connect_timeout_ms' => 10000,
                'request_timeout_ms' => 90000, 'max_attempts' => 3, 'backoff_ms' => 250,
                'max_input_tokens' => 24000, 'max_output_tokens' => 8192,
                'config' => ['retry_statuses' => [408, 429, 500, 502, 503, 504]]]
        );

        foreach ([
            ['planner', 'Planner Agent', ['cognee', 'project']],
            ['code_research', 'Code Research Agent', ['graphify', 'github']],
            ['backend', 'Backend Agent', ['graphify', 'github', 'mcp']],
            ['frontend', 'Frontend Agent', ['graphify', 'github', 'browser']],
            ['review', 'Review Agent', ['git_diff', 'tests', 'cognee']],
            ['admin', 'Admin Agent', ['mcp', 'server_logs', 'cognee']],
            ['research', 'Research Agent', ['web', 'docs', 'cognee']],
            ['optimizer', 'Optimizer Agent', ['llm_runs', 'evaluations', 'rankings']],
        ] as [$key, $name, $sources]) {
            AgentProfile::updateOrCreate(['key' => $key], [
                'name' => $name, 'type' => $key, 'status' => 'active',
                'prompt_template_key' => 'luczor.system', 'required_sources' => $sources,
                'capabilities' => [], 'config' => ['parallel_safe' => in_array($key, ['review', 'research', 'optimizer'], true)],
            ]);
        }
    }
}
