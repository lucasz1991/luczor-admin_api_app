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
        ['alxis955-qwe2.5-coder-uncensored', 'Stufe 1 · Alxis955/qwe2.5-coder-Uncensored'],
        ['darkmaniac7-qwen3.5-4b-uncensored-mnn', 'Stufe 2 · darkmaniac7/Qwen3.5-4B-uncensored-MNN'],
        ['blossomsai-qwen2.5-coder-14b-instruct-uncensored', 'Stufe 3 · BlossomsAI/Qwen2.5-Coder-14B-Instruct-Uncensored'],
        [self::LEGACY_27B_ID, 'Stufe 4 · OrcaRouter Qwen3.8-27B Uncensored Q4_K_M'],
        ['thebloke-wizardlm-uncensored-falcon-40b-gptq', 'Stufe 5 · TheBloke/WizardLM-Uncensored-Falcon-40B-GPTQ'],
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
            $model = $index === 3 && is_array($configured27b) ? $configured27b : $empty;
            $model['id'] = $id;
            $model['display_name'] = mb_substr($displayName, 0, 160);
            $model['promoted'] = true;
            $model['release_channel'] = 'stable';
            $model['routing_role'] = $index === 4 ? 'preferred' : 'fallback';
            if ($index !== 3) {
                $model['enabled'] = false;
                $model['artifact'] = null;
                $model['runtime'] = null;
                $model['context_limit'] = null;
                $model['capacity_policy'] = $empty['capacity_policy'];
                $model['chat_template_hash'] = null;
                $model['evaluation_report_hash'] = null;
                $model['license'] = null;
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
            $model = $index === 3 ? $source : $configured;
            $model['id'] = $id;
            $model['display_name'] = $displayName;
            $model['promoted'] = true;
            $model['release_channel'] = 'stable';
            $model['routing_role'] = $index === 4 ? 'preferred' : 'fallback';
            if ($index !== 3) {
                $model['enabled'] = false;
                $model['artifact'] = null;
                $model['runtime'] = null;
                $model['context_limit'] = null;
                $model['capacity_policy'] = $configured['capacity_policy'];
                $model['chat_template_hash'] = null;
                $model['evaluation_report_hash'] = null;
                $model['license'] = null;
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
