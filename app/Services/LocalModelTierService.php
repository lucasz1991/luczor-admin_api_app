<?php

namespace App\Services;

use App\Models\LocalModelCatalog;

class LocalModelTierService
{
    public function defaults(): array
    {
        $existing = collect(config('local_models.models'))->firstWhere('id', config('local_models.routing.default_model_id'));
        $models = [];
        foreach ([['local-tier-light', 'Sparsam'], ['local-tier-compact', 'Kompakt'], ['local-tier-balanced', 'Ausgewogen'], ['local-tier-performance', 'Leistungsstark']] as [$id, $name]) {
            $model = $existing;
            $model['id'] = $id;
            $model['display_name'] = mb_substr($name.' · '.$existing['display_name'], 0, 160);
            $model['promoted'] = true;
            $model['release_channel'] = 'stable';
            $model['routing_role'] = 'fallback';
            // Starter slots share the existing verified weights and measured requirements.
            // A different label cannot make the same model fit into less memory.
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
