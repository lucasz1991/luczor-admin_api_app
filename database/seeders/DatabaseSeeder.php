<?php

namespace Database\Seeders;

use App\Models\AgentProfile;
use App\Models\ContextStrategy;
use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\NetworkPolicy;
use App\Models\PromptTemplate;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
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
        if (! $admin->tenant_id) {
            $admin->update(['tenant_id' => $tenant->id]);
        }

        // Current free OpenRouter models. The policy service ranks these candidates
        // from observed cost, latency, quality and success data after enough samples.
        $profiles = [
            ['name' => 'NVIDIA Nemotron Super Free', 'slug' => 'nvidia-nemotron-super-free', 'provider' => 'openrouter', 'model_id' => 'nvidia/nemotron-3-super-120b-a12b:free', 'purpose' => 'chat', 'temperature' => 0.20, 'max_tokens' => 2200],
            ['name' => 'NVIDIA Nemotron Ultra Free', 'slug' => 'nvidia-nemotron-ultra-free', 'provider' => 'openrouter', 'model_id' => 'nvidia/nemotron-3-ultra-550b-a55b:free', 'purpose' => 'planner', 'temperature' => 0.15, 'max_tokens' => 2800],
            ['name' => 'NVIDIA Nemotron Nano Omni Free', 'slug' => 'nvidia-nemotron-nano-omni-free', 'provider' => 'openrouter', 'model_id' => 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free', 'purpose' => 'chat', 'temperature' => 0.20, 'max_tokens' => 1800],
            ['name' => 'NVIDIA Nemotron Vision Free', 'slug' => 'nvidia-nemotron-vision-free', 'provider' => 'openrouter', 'model_id' => 'nvidia/nemotron-nano-12b-v2-vl:free', 'purpose' => 'vision', 'temperature' => 0.10, 'max_tokens' => 1800],
            ['name' => 'Qwen3 Coder Free', 'slug' => 'qwen3-coder-free', 'provider' => 'openrouter', 'model_id' => 'qwen/qwen3-coder:free', 'purpose' => 'coding', 'temperature' => 0.10, 'max_tokens' => 2800],
            ['name' => 'Cohere North Mini Code Free', 'slug' => 'cohere-north-mini-code-free', 'provider' => 'openrouter', 'model_id' => 'cohere/north-mini-code:free', 'purpose' => 'coding', 'temperature' => 0.10, 'max_tokens' => 2200],
            ['name' => 'OpenAI GPT-OSS 120B Free', 'slug' => 'gpt-oss-120b-free', 'provider' => 'openrouter', 'model_id' => 'openai/gpt-oss-120b:free', 'purpose' => 'verifier', 'temperature' => 0.05, 'max_tokens' => 2200],
            ['name' => 'Meta Llama 3.3 70B Free', 'slug' => 'llama-33-70b-free', 'provider' => 'openrouter', 'model_id' => 'meta-llama/llama-3.3-70b-instruct:free', 'purpose' => 'chat', 'temperature' => 0.20, 'max_tokens' => 1800],
            ['name' => 'Google Gemma 4 31B Free', 'slug' => 'gemma-4-31b-free', 'provider' => 'openrouter', 'model_id' => 'google/gemma-4-31b-it:free', 'purpose' => 'verifier', 'temperature' => 0.10, 'max_tokens' => 1800],
            ['name' => 'Local Speech To Text', 'slug' => 'local-stt-whisper', 'provider' => 'local', 'model_id' => 'whisper.cpp', 'purpose' => 'stt', 'temperature' => 0.00, 'max_tokens' => 1],
            ['name' => 'Local Text To Speech', 'slug' => 'local-tts-piper', 'provider' => 'local', 'model_id' => 'piper', 'purpose' => 'tts', 'temperature' => 0.00, 'max_tokens' => 1],
        ];

        foreach ($profiles as $profile) {
            ModelProfile::updateOrCreate(['slug' => $profile['slug']], $profile + ['active' => true]);
        }
        ModelProfile::whereIn('slug', ['chat-fast', 'coding-agent-primary', 'planner-deep', 'verifier-strict', 'vision-reasoner'])
            ->update(['active' => false]);

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
            'chat' => ['nvidia-nemotron-super-free', 'nvidia-nemotron-nano-omni-free', 'llama-33-70b-free'],
            'coding' => ['qwen3-coder-free', 'cohere-north-mini-code-free', 'nvidia-nemotron-super-free'],
            'planner' => ['nvidia-nemotron-ultra-free', 'nvidia-nemotron-super-free', 'gpt-oss-120b-free'],
            'verifier' => ['gpt-oss-120b-free', 'nvidia-nemotron-super-free', 'gemma-4-31b-free'],
            'vision' => ['nvidia-nemotron-vision-free', 'nvidia-nemotron-super-free'],
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
            ['key' => 'voice_stt_engine', 'value' => 'whisper_local', 'group' => 'voice', 'label' => 'STT-Engine (whisper_local|whisper_rs)', 'type' => 'string'],
            ['key' => 'hands_free_strategy', 'value' => 'safeword', 'group' => 'voice', 'label' => 'Freihand-Strategie (safeword|continuous)', 'type' => 'string'],
            ['key' => 'voice_trigger_phrase', 'value' => 'luczor start', 'group' => 'voice', 'label' => 'Aktivierungsphrase', 'type' => 'string'],
            ['key' => 'voice_end_phrase', 'value' => 'luczor stopp', 'group' => 'voice', 'label' => 'Beendigungsphrase', 'type' => 'string'],
            ['key' => 'voice_continuous_silence_ms', 'value' => 5000, 'group' => 'voice', 'label' => 'Dauerzuhören: Pause bis Abschluss (ms)', 'type' => 'number'],
            ['key' => 'voice_tts_rate', 'value' => 1.0, 'group' => 'voice', 'label' => 'TTS-Tempo (0.5–2.0)', 'type' => 'number'],
            ['key' => 'voice_tts_volume', 'value' => 90, 'group' => 'voice', 'label' => 'TTS-Lautstärke (0–100)', 'type' => 'number'],
            ['key' => 'voice_interrupt_mode', 'value' => 'on_speech', 'group' => 'voice', 'label' => 'TTS-Unterbrechung (on_speech|on_activation_phrase|off)', 'type' => 'string'],
            // P27 — global sandbox safety switch: forces every workflow run to
            // simulate mutating/device tasks instead of performing them.
            ['key' => 'sandbox_enabled', 'value' => false, 'group' => 'workflow', 'label' => 'Sandbox erzwingen (alle Läufe simulieren)', 'type' => 'bool'],
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
