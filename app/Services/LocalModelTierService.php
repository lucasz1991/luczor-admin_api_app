<?php

namespace App\Services;

use App\Models\LocalModelCatalog;

class LocalModelTierService
{
    public function defaults(): array
    {
        $existing = collect(config('local_models.models'))->firstWhere('id', config('local_models.routing.default_model_id'));
        $models = [];
        foreach ([['local-tier-light', 'Sparsam · Qwen3.5 0.8B', 4, 2, 4096], ['local-tier-compact', 'Kompakt · Qwen3.5 2B', 8, 3, 8192], ['local-tier-balanced', 'Ausgewogen · Qwen3.5 4B', 12, 5, 16384], ['local-tier-performance', 'Leistungsstark · Qwen3.5 9B', 16, 8, 16384]] as [$id, $name, $total, $free, $context]) {
            $model = $existing;
            $model['id'] = $id;
            $model['display_name'] = $name;
            $model['enabled'] = false;
            $model['promoted'] = true;
            $model['release_channel'] = 'stable';
            $model['routing_role'] = 'fallback';
            $model['context_limit'] = $context;
            $model['artifact'] = null;
            $model['chat_template_hash'] = null;
            $model['evaluation_report_hash'] = null;
            $model['license'] = 'Apache-2.0';
            $model['capacity_policy']['min_total_ram_bytes'] = $total * 1024 ** 3;
            $model['capacity_policy']['min_available_ram_bytes'] = $free * 1024 ** 3;
            $model['capacity_policy']['min_vram_bytes'] = 0;
            $model['capacity_policy']['min_storage_free_bytes'] = $free * 1024 ** 3;
            // Disabled proposals need measured runtime/quality evidence before publication as executable models.
            $models[] = $model;
        }
        $existing['routing_role'] = 'preferred';
        $existing['release_channel'] = 'stable';
        $existing['promoted'] = true;
        $models[] = $existing;
        $routing = config('local_models.routing');
        $routing['preferred_model_id'] = $existing['id'];
        $routing['default_model_id'] = $existing['id'];
        $routing['fallback_model_ids'] = array_reverse(array_column(array_slice($models, 0, 4), 'id'));
        $routing['experimental_model_ids'] = [];

        return ['schema_version' => 2, 'models' => $models, 'routing' => $routing];
    }

    public function state(): array
    {
        $catalog = LocalModelCatalog::find(1);

        return ['draft' => $catalog?->draft ?? $this->defaults(), 'revision' => $catalog?->revision ?? 0, 'published' => $catalog?->published !== null];
    }
}
