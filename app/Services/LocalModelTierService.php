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
        ['dolphin3-qwen2.5-3b-gguf', 'Stufe 1 · Dolphin3.0 Qwen2.5 3B GGUF · uncensored/tool data'],
        ['dolphin3-llama3.1-8b-gguf', 'Stufe 2 · Dolphin3.0 Llama3.1 8B GGUF · uncensored/tool data'],
        ['rootmonster-qwen3-14b-abliterated-gguf', 'Stufe 3 · RootMonsteR Qwen3 14B Abliterated GGUF'],
        [self::LEGACY_27B_ID, 'Stufe 4 · OrcaRouter Qwen3.8-27B Uncensored Q4_K_M'],
        ['huihui-qwen3-30b-a3b-instruct-abliterated-gguf', 'Stufe 5 · Huihui Qwen3 30B A3B Instruct Abliterated GGUF'],
    ];

    private const PREVIOUS_REQUESTED_IDS = [
        'alxis955-qwe2.5-coder-uncensored',
        'darkmaniac7-qwen3.5-4b-uncensored-mnn',
        'blossomsai-qwen2.5-coder-14b-instruct-uncensored',
        self::LEGACY_27B_ID,
        'thebloke-wizardlm-uncensored-falcon-40b-gptq',
    ];

    private const TOOL_UNSTABLE_IDS = [
        'bartowski-qwen2.5-coder-3b-abliterated-gguf',
        'mradermacher-whiterabbitneo-v3-7b-gguf',
        'blossomsai-qwen2.5-coder-14b-instruct-uncensored-gguf',
        self::LEGACY_27B_ID,
        'tobiaslogic-qwen2.5-coder-32b-abliterated-gguf',
    ];

    private const BASE_TEXT_FEATURES = [
        'text_generation' => 'candidate',
        'text_edit' => 'candidate',
        'tool_calling' => 'candidate',
        'coding' => 'candidate',
        'cybersecurity' => 'unknown',
        'image_to_text' => 'unsupported',
        'audio_input' => 'unsupported',
        'audio_output' => 'unsupported',
        'uncensored' => 'candidate',
    ];

    /** Download metadata is pinned to the HF revision and file hash; it is not an activation claim. */
    private const CANDIDATE_METADATA = [
        'dolphin3-qwen2.5-3b-gguf' => [
            'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding', 'tools', 'uncensored'],
            'features' => self::BASE_TEXT_FEATURES,
            'context_limit' => 32768,
            'artifact' => [
                'url' => 'https://huggingface.co/bartowski/Dolphin3.0-Qwen2.5-3b-GGUF/resolve/4f84a9b5b7ecc4c49367838cf226677008f37c1e/Dolphin3.0-Qwen2.5-3b-Q4_K_M.gguf',
                'sha256' => '0cb1908c5f444e1dc2c5b5619d62ac4957a22ad39cd42f2d0b48e2d8b1c358ab',
                'size_bytes' => 1929906144,
                'format' => 'gguf',
                'quantization' => 'Q4_K_M',
                'storage_class' => 'fixed_storage',
            ],
            'license' => 'Other (Dolphin/Qwen terms; review before commercial redistribution)',
        ],
        'dolphin3-llama3.1-8b-gguf' => [
            'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding', 'tools', 'uncensored'],
            'features' => self::BASE_TEXT_FEATURES,
            'context_limit' => 32768,
            'artifact' => [
                'url' => 'https://huggingface.co/bartowski/Dolphin3.0-Llama3.1-8B-GGUF/resolve/a6274707ba8c5f12d4521eabbce1b318bb2f27f5/Dolphin3.0-Llama3.1-8B-Q4_K_M.gguf',
                'sha256' => '268390e07edd407ad93ea21a868b7ae995b5950e01cad0db9e1802ae5049d405',
                'size_bytes' => 4920749472,
                'format' => 'gguf',
                'quantization' => 'Q4_K_M',
                'storage_class' => 'fixed_storage',
            ],
            'license' => 'Llama 3.1 Community License',
        ],
        'rootmonster-qwen3-14b-abliterated-gguf' => [
            'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding', 'tools', 'cybersecurity', 'uncensored'],
            'features' => [
                'text_generation' => 'candidate',
                'text_edit' => 'candidate',
                'tool_calling' => 'candidate',
                'coding' => 'candidate',
                'cybersecurity' => 'candidate',
                'image_to_text' => 'unsupported',
                'audio_input' => 'unsupported',
                'audio_output' => 'unsupported',
                'uncensored' => 'candidate',
            ],
            'context_limit' => 32768,
            'artifact' => [
                'url' => 'https://huggingface.co/RootMonsteR/Qwen3-14B-Abliterated-GGUF/resolve/aad7bb258333fa83991acef39e31a97677eb711b/qwen3-14b-abliterated-Q4_K_M.gguf',
                'sha256' => 'c74b5bcfcf7d4c9386075cde43fd7a4580c602b46b90ad01d7fc58b696748bbb',
                'size_bytes' => 9001753792,
                'format' => 'gguf',
                'quantization' => 'Q4_K_M',
                'storage_class' => 'fixed_storage',
            ],
            'license' => 'Apache-2.0',
        ],
        self::LEGACY_27B_ID => [
            'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding', 'tools', 'image_to_text', 'uncensored'],
            'features' => [
                'text_generation' => 'verified',
                'text_edit' => 'candidate',
                'tool_calling' => 'verified',
                'coding' => 'verified',
                'cybersecurity' => 'candidate',
                'image_to_text' => 'candidate',
                'audio_input' => 'unsupported',
                'audio_output' => 'unsupported',
                'uncensored' => 'verified',
            ],
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
        'huihui-qwen3-30b-a3b-instruct-abliterated-gguf' => [
            'capabilities' => ['chat', 'reasoning', 'planning', 'execution_preparation', 'coding', 'tools', 'uncensored'],
            'features' => self::BASE_TEXT_FEATURES,
            'context_limit' => 32768,
            'artifact' => [
                'url' => 'https://huggingface.co/Sowkwndms/Huihui-Qwen3-30B-A3B-Instruct-2507-abliterated-Q4_K_M-GGUF/resolve/6972656944f13f6a156a881830c8726556e13143/huihui-qwen3-30b-a3b-instruct-2507-abliterated-q4_k_m.gguf',
                'sha256' => '8394a17f4fef88c92f0ade6237e198ecba74000ba38cae26d5a08148976c71be',
                'size_bytes' => 18556686496,
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
            $model['capabilities'] = self::CANDIDATE_METADATA[$id]['capabilities'] ?? ($model['capabilities'] ?? $empty['capabilities']);
            $model['features'] = self::CANDIDATE_METADATA[$id]['features'] ?? ($model['features'] ?? $empty['features']);
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
            'features' => [
                'text_generation' => 'unknown',
                'text_edit' => 'unknown',
                'tool_calling' => 'unknown',
                'coding' => 'unknown',
                'cybersecurity' => 'unknown',
                'image_to_text' => 'unknown',
                'audio_input' => 'unknown',
                'audio_output' => 'unknown',
                'uncensored' => 'unknown',
            ],
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
            $model['capabilities'] = self::CANDIDATE_METADATA[$id]['capabilities'] ?? ($model['capabilities'] ?? $configured['capabilities']);
            $model['features'] = self::CANDIDATE_METADATA[$id]['features'] ?? ($model['features'] ?? $configured['features']);
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

    /** Replace the first GGUF ladder whose non-27B tiers failed Luczor tool probes. */
    public function replaceToolUnstableLadder(array $draft): ?array
    {
        $models = $draft['models'] ?? [];
        if (count($models) !== count(self::TOOL_UNSTABLE_IDS)
            || array_column($models, 'id') !== self::TOOL_UNSTABLE_IDS) {
            return null;
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
            $model['capabilities'] = self::CANDIDATE_METADATA[$id]['capabilities'] ?? ($model['capabilities'] ?? $empty['capabilities']);
            $model['features'] = self::CANDIDATE_METADATA[$id]['features'] ?? ($model['features'] ?? $empty['features']);
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
