<?php

namespace App\Services;

use App\Models\LocalModelCatalog;

class LocalModelTierService
{
    private const LEGACY_27B_ID = 'orcarouter-qwen3.8-27b-uncensored-q4-k-m';

    /**
     * The tier order is ascending by resource demand. Routing prefers the
     * last tier and walks this list backwards when a model is unavailable.
     */
    private const REQUESTED_TIERS = [
        ['bartowski-qwen2.5-coder-3b-abliterated-gguf', 'Stufe 1 · bartowski/Qwen2.5-Coder-3B-Instruct-abliterated-GGUF'],
        ['mradermacher-whiterabbitneo-v3-7b-gguf', 'Stufe 2 · mradermacher/WhiteRabbitNeo-V3-7B-GGUF · Cybersecurity'],
        ['blossomsai-qwen2.5-coder-14b-instruct-uncensored-gguf', 'Stufe 3 · BlossomsAI/Qwen2.5-Coder-14B-Instruct-Uncensored-GGUF'],
        [self::LEGACY_27B_ID, 'Stufe 4 · OrcaRouter Qwen3.8-27B Uncensored Q4_K_M'],
        ['tobiaslogic-qwen2.5-coder-32b-abliterated-gguf', 'Stufe 5 · TobiasLogic/Qwen2.5-Coder-32B-abliterated-GGUF'],
    ];

    private const PREVIOUS_REQUESTED_IDS = [
        'alxis955-qwe2.5-coder-uncensored',
        'darkmaniac7-qwen3.5-4b-uncensored-mnn',
        'blossomsai-qwen2.5-coder-14b-instruct-uncensored',
        self::LEGACY_27B_ID,
        'thebloke-wizardlm-uncensored-falcon-40b-gptq',
    ];

    /** Download metadata is pinned to the HF revision and file hash; it is not an activation claim. */
    private const CANDIDATE_METADATA = [
        'bartowski-qwen2.5-coder-3b-abliterated-gguf' => [
            'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding'],
            'context_limit' => 32768,
            'artifact' => [
                'url' => 'https://huggingface.co/bartowski/Qwen2.5-Coder-3B-Instruct-abliterated-GGUF/resolve/d3030e6c3380c87316eeb6b35cdf5c94a68f1986/Qwen2.5-Coder-3B-Instruct-abliterated-Q4_K_M.gguf',
                'sha256' => 'd5c108dfbdac44c738e45a84d5624716cfb8522d1410f46a7108167ee4bd0cac',
                'size_bytes' => 1929903488,
                'format' => 'gguf',
                'quantization' => 'Q4_K_M',
                'storage_class' => 'fixed_storage',
            ],
            'license' => 'Qwen Research License (HF card: qwen-research)',
        ],
        'mradermacher-whiterabbitneo-v3-7b-gguf' => [
            'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding', 'cybersecurity'],
            'context_limit' => 32768,
            'artifact' => [
                'url' => 'https://huggingface.co/mradermacher/WhiteRabbitNeo-V3-7B-GGUF/resolve/272df46bc0e282a378f67bac725ce8481cc48d2f/WhiteRabbitNeo-V3-7B.Q4_K_M.gguf',
                'sha256' => 'b19da8c6aacffdedc7bcd6b7f7d7d4db900f7d5c49f5473de18bb58111b432e5',
                'size_bytes' => 4683075232,
                'format' => 'gguf',
                'quantization' => 'Q4_K_M',
                'storage_class' => 'fixed_storage',
            ],
            'license' => 'Apache-2.0',
        ],
        'blossomsai-qwen2.5-coder-14b-instruct-uncensored-gguf' => [
            'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding'],
            'context_limit' => 32768,
            'artifact' => [
                'url' => 'https://huggingface.co/BlossomsAI/Qwen2.5-Coder-14B-Instruct-Uncensored-GGUF/resolve/b15f5f5bf2c2ccaa66f82b58a1a410a5b74715d1/q4_k_m.gguf',
                'sha256' => '75062a7ba3575573cc421a2cbafbf69fb48d0ecb28da2684b577eb609097fbe4',
                'size_bytes' => 8988110272,
                'format' => 'gguf',
                'quantization' => 'Q4_K_M',
                'storage_class' => 'fixed_storage',
            ],
            'license' => 'MIT',
        ],
        self::LEGACY_27B_ID => [
            'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding'],
            'context_limit' => 8192,
            'artifact' => [
                'url' => 'https://huggingface.co/bartowski/orcarouter_Qwen3.8-27B-Uncensored-GGUF/resolve/87d37daf5e5eb72a926d8b413e08809a57f1a120/orcarouter_Qwen3.8-27B-Uncensored-Q4_K_M.gguf',
                'sha256' => '6c8c7658fe13eef22666aa89862f7fb70aa72109838cf19989e59b15875e5e08',
                'size_bytes' => 17772538112,
                'format' => 'gguf',
                'quantization' => 'Q4_K_M',
                'storage_class' => 'fixed_storage',
            ],
            'license' => 'Apache-2.0',
        ],
        'tobiaslogic-qwen2.5-coder-32b-abliterated-gguf' => [
            'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding'],
            'context_limit' => 32768,
            'artifact' => [
                'url' => 'https://huggingface.co/TobiasLogic/Qwen2.5-Coder-32B-abliterated-GGUF/resolve/b58cb0c8c2f8e9903be8b3c68974df1d6e13374e/qwen2.5-coder-32b-abliterated-Q4_K_M.gguf',
                'sha256' => '593e9be6fae0c8c4008bb279f6380154afea89aeed90d9e3f2130d0becc84908',
                'size_bytes' => 19851336416,
                'format' => 'gguf',
                'quantization' => 'Q4_K_M',
                'storage_class' => 'fixed_storage',
            ],
            'license' => 'Apache-2.0',
        ],
    ];

    /** Official Qwen metadata pinned to a repository revision; runtime evaluation remains required. */
    public function laptopProfile(array $slot, ?array $published = null): array
    {
        // Repeated preparation must not erase verified runtime/template evidence.
        foreach ([$slot, $published] as $candidate) {
            if (($candidate['id'] ?? null) === ($slot['id'] ?? null)
                && ($candidate['artifact']['sha256'] ?? null) === '7485fe6f11af29433bc51cab58009521f205840f5b4ae3a32fa7f92e8534fdf5'
                && is_array($candidate['runtime'] ?? null)
                && ! empty($candidate['chat_template_hash'])
                && ! empty($candidate['evaluation_report_hash'])
                && is_array($candidate['capacity_policy']['benchmark_thresholds'] ?? null)) {
                return $candidate;
            }
        }

        return array_replace($slot, [
            'display_name' => 'Laptop · Qwen3-4B Q4_K_M',
            'enabled' => false,
            'context_limit' => 8192,
            'artifact' => [
                'url' => 'https://huggingface.co/Qwen/Qwen3-4B-GGUF/resolve/bc640142c66e1fdd12af0bd68f40445458f3869b/Qwen3-4B-Q4_K_M.gguf',
                'sha256' => '7485fe6f11af29433bc51cab58009521f205840f5b4ae3a32fa7f92e8534fdf5',
                'size_bytes' => 2497280256,
                'format' => 'gguf',
                'quantization' => 'Q4_K_M',
                'storage_class' => 'fixed_storage',
            ],
            'runtime' => null,
            // Evidence for other weights must not survive a model replacement.
            'platform_profiles' => [],
            'capacity_policy' => [
                // Conservative proposal, not measured device acceptance.
                'min_total_ram_bytes' => 8 * 1024 ** 3,
                'min_available_ram_bytes' => 4 * 1024 ** 3,
                'min_vram_bytes' => 0,
                'min_storage_free_bytes' => 6 * 1024 ** 3,
                'max_startup_seconds' => 180,
                'benchmark_thresholds' => null,
            ],
            'chat_template_hash' => null,
            'evaluation_report_hash' => null,
            'license' => 'Apache-2.0',
        ]);
    }

    public function defaults(): array
    {
        $configured27b = collect(config('local_models.models'))->firstWhere('id', self::LEGACY_27B_ID);
        $empty = $this->emptyModel();
        $models = [];
        foreach (self::REQUESTED_TIERS as $index => [$id, $displayName]) {
            // Retain the existing verified 27B artifact only in its requested
            // stage. All new repos stay metadata-only until their exact
            // llama.cpp-compatible artifact and evaluation evidence exist.
            $model = $index === 3 && is_array($configured27b)
                ? $configured27b
                : array_replace($empty, self::CANDIDATE_METADATA[$id] ?? []);
            $model['id'] = $id;
            $model['display_name'] = mb_substr($displayName, 0, 160);
            $model['promoted'] = true;
            $model['release_channel'] = 'stable';
            $model['routing_role'] = $index === 4 ? 'preferred' : 'fallback';
            if ($index !== 3) {
                $model['enabled'] = false;
                $model['artifact'] = self::CANDIDATE_METADATA[$id]['artifact'] ?? null;
                $model['runtime'] = null;
                $model['context_limit'] = self::CANDIDATE_METADATA[$id]['context_limit'] ?? null;
                $model['capacity_policy'] = $empty['capacity_policy'];
                $model['chat_template_hash'] = null;
                $model['evaluation_report_hash'] = null;
                $model['license'] = self::CANDIDATE_METADATA[$id]['license'] ?? null;
            }
            $models[] = $model;
        }
        $routing = config('local_models.routing');
        $routing['preferred_model_id'] = self::REQUESTED_TIERS[4][0];
        $routing['default_model_id'] = self::REQUESTED_TIERS[4][0];
        $routing['fallback_model_ids'] = array_reverse(array_column(array_slice(self::REQUESTED_TIERS, 0, 4), 0));
        $routing['experimental_model_ids'] = [];

        return ['schema_version' => 2, 'models' => $models, 'routing' => $routing];
    }

    /** @return array<string,mixed> */
    private function emptyModel(): array
    {
        return [
            'id' => 'placeholder',
            'display_name' => 'Lokales Modell · noch nicht eingerichtet',
            'execution_target' => 'local_llama_cpp',
            'release_channel' => 'stable',
            'routing_role' => 'fallback',
            'promoted' => true,
            'enabled' => false,
            'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation'],
            'context_limit' => null,
            'artifact' => null,
            'runtime' => null,
            'capacity_policy' => [
                'min_total_ram_bytes' => null,
                'min_available_ram_bytes' => null,
                'min_vram_bytes' => null,
                'min_storage_free_bytes' => null,
                'max_startup_seconds' => null,
                'benchmark_thresholds' => null,
            ],
            'health_policy' => ['cooldown_ms' => 300000, 'max_consecutive_failures' => 2],
            'chat_template_hash' => null,
            'evaluation_report_hash' => null,
            'license' => null,
        ];
    }

    /**
     * Convert the old duplicated starter slots to the requested lineup.
     * Published data is deliberately not touched by the migration caller.
     */
    public function configureRequestedLadder(array $draft): ?array
    {
        $models = $draft['models'] ?? [];
        $legacyIds = ['local-tier-light', 'local-tier-compact', 'local-tier-balanced', 'local-tier-performance'];
        if (count($models) !== 5 || array_column($models, 'id') !== [...$legacyIds, self::LEGACY_27B_ID]) {
            return null;
        }

        $source = $models[4];
        foreach (array_slice($models, 0, 4) as $model) {
            if (($model['artifact'] ?? null) !== ($source['artifact'] ?? null)
                || ($model['runtime'] ?? null) !== ($source['runtime'] ?? null)
                || ($model['capacity_policy'] ?? null) !== ($source['capacity_policy'] ?? null)
                || ($model['enabled'] ?? null) !== ($source['enabled'] ?? null)) {
                return null;
            }
        }

        $configured = $this->emptyModel();
        $replacement = [];
        foreach (self::REQUESTED_TIERS as $index => [$id, $displayName]) {
            $model = $index === 3
                ? $source
                : array_replace($configured, self::CANDIDATE_METADATA[$id] ?? []);
            $model['id'] = $id;
            $model['display_name'] = $displayName;
            $model['promoted'] = true;
            $model['release_channel'] = 'stable';
            $model['routing_role'] = $index === 4 ? 'preferred' : 'fallback';
            if ($index !== 3) {
                $model['enabled'] = false;
                $model['artifact'] = self::CANDIDATE_METADATA[$id]['artifact'] ?? null;
                $model['runtime'] = null;
                $model['context_limit'] = self::CANDIDATE_METADATA[$id]['context_limit'] ?? null;
                $model['capacity_policy'] = $configured['capacity_policy'];
                $model['chat_template_hash'] = null;
                $model['evaluation_report_hash'] = null;
                $model['license'] = self::CANDIDATE_METADATA[$id]['license'] ?? null;
            }
            $replacement[] = $model;
        }
        $draft['models'] = $replacement;
        $draft['schema_version'] = 2;
        $draft['routing']['preferred_model_id'] = self::REQUESTED_TIERS[4][0];
        $draft['routing']['default_model_id'] = self::REQUESTED_TIERS[4][0];
        $draft['routing']['fallback_model_ids'] = array_reverse(array_column(array_slice(self::REQUESTED_TIERS, 0, 4), 0));
        $draft['routing']['experimental_model_ids'] = [];

        return $draft;
    }

    /** Replace only the previous non-native proposals; published data is handled by the caller. */
    public function replaceNonNativeRequestedLadder(array $draft): ?array
    {
        $models = $draft['models'] ?? [];
        if (count($models) !== count(self::PREVIOUS_REQUESTED_IDS)
            || array_column($models, 'id') !== self::PREVIOUS_REQUESTED_IDS) {
            return null;
        }
        foreach ([0, 1, 2, 4] as $index) {
            if (($models[$index]['enabled'] ?? true) !== false || ($models[$index]['artifact'] ?? null) !== null) {
                return null;
            }
        }

        $replacement = $this->buildReplacementLadder($draft, $models[3]);
        $replacement['schema_version'] = 2;

        return $replacement;
    }

    private function buildReplacementLadder(array $draft, array $retainedModel): array
    {
        $empty = $this->emptyModel();
        $models = [];
        foreach (self::REQUESTED_TIERS as $index => [$id, $displayName]) {
            $model = $index === 3
                ? $retainedModel
                : array_replace($empty, self::CANDIDATE_METADATA[$id] ?? []);
            $model['id'] = $id;
            $model['display_name'] = $displayName;
            $model['promoted'] = true;
            $model['release_channel'] = 'stable';
            $model['routing_role'] = $index === 4 ? 'preferred' : 'fallback';
            if ($index !== 3) {
                $model['enabled'] = false;
                $model['runtime'] = null;
                $model['capacity_policy'] = $empty['capacity_policy'];
                $model['chat_template_hash'] = null;
                $model['evaluation_report_hash'] = null;
            }
            $models[] = $model;
        }
        $draft['models'] = $models;
        $draft['routing']['preferred_model_id'] = self::REQUESTED_TIERS[4][0];
        $draft['routing']['default_model_id'] = self::REQUESTED_TIERS[4][0];
        $draft['routing']['fallback_model_ids'] = array_reverse(array_column(array_slice(self::REQUESTED_TIERS, 0, 4), 0));
        $draft['routing']['experimental_model_ids'] = [];

        return $draft;
    }

    public function state(): array
    {
        $catalog = LocalModelCatalog::find(1);

        return ['draft' => $catalog->draft ?? $this->defaults(), 'revision' => $catalog->revision ?? 0, 'published' => $catalog?->published !== null];
    }

    /** Upgrade only the original, untouched disabled proposals; never overwrite configured models. */
    public function upgradeStarterDraft(array $draft): ?array
    {
        $models = $draft['models'] ?? [];
        if (count($models) !== 5) {
            return null;
        }
        $ids = ['local-tier-light', 'local-tier-compact', 'local-tier-balanced', 'local-tier-performance'];
        foreach ($ids as $index => $id) {
            if (($models[$index]['id'] ?? null) !== $id || ($models[$index]['enabled'] ?? true) !== false
                || ($models[$index]['artifact'] ?? null) !== null) {
                return null;
            }
        }
        foreach (['Sparsam', 'Kompakt', 'Ausgewogen', 'Leistungsstark'] as $index => $label) {
            $models[$index] = $models[4];
            $models[$index]['id'] = $ids[$index];
            $models[$index]['display_name'] = mb_substr($label.' · '.$models[4]['display_name'], 0, 160);
            $models[$index]['routing_role'] = 'fallback';
        }
        $draft['models'] = $models;

        return $draft;
    }
}
