<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\LocalModelManifestConfigurationException;
use App\Models\LocalModelCatalog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LocalModelManifestService;
use App\Services\LocalModelTierService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocalModelTierController extends AdminController
{
    public function index(Request $request, LocalModelTierService $tiers)
    {
        $this->ensureAdmin($request);

        return view('admin.local-models', $tiers->state());
    }

    public function update(Request $request, LocalModelTierService $tiers, LocalModelManifestService $manifest)
    {
        $this->ensureAdmin($request);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'models' => ['required', 'array', 'size:5'], 'models.*' => ['required', 'json', 'max:20000'], 'publish' => ['nullable', 'boolean']]);

        return DB::transaction(function () use ($request, $data, $tiers, $manifest) {
            // The owner row serializes the first creation too.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $catalog = LocalModelCatalog::firstOrCreate(['id' => 1], ['draft' => $tiers->defaults(), 'revision' => 0]);
            $catalog = LocalModelCatalog::whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless((int) $data['revision'] === $catalog->revision, 409, 'Die Modellkonfiguration wurde inzwischen geändert. Bitte neu laden.');
            $draft = $catalog->draft;
            $models = array_map(fn ($json) => json_decode($json, true, 32, JSON_THROW_ON_ERROR), $data['models']);
            if (array_column($models, 'id') !== array_column($draft['models'], 'id')) {
                throw ValidationException::withMessages(['models' => 'Die Modell-IDs der fünf Stufen müssen erhalten bleiben.']);
            }
            $draft['models'] = $models;
            $version = max($catalog->revision, (int) config('local_models.catalog_version'), (int) config('local_models.policy_version')) + 1;
            $draft['catalog_version'] = $version;
            $draft['policy_version'] = $version;
            try {
                $manifest->validateCatalog($draft);
            } catch (LocalModelManifestConfigurationException $error) {
                throw ValidationException::withMessages(['models' => 'Modellkonfiguration unvollständig oder ungültig: '.$error->getMessage()]);
            }
            $updates = ['draft' => $draft, 'revision' => $version];
            if ($request->boolean('publish')) {
                if (! collect($models)->contains(fn ($model) => $model['enabled'] === true)) {
                    throw ValidationException::withMessages(['models' => 'Mindestens ein vollständig konfiguriertes Modell muss aktiv sein.']);
                }
                $manifest->validateCatalog($draft, true);
                $updates['published'] = $draft;
            }
            $catalog->update($updates);
            app(AuditLogger::class)->record(['actor_user_id' => $request->user()->id, 'event_type' => 'local_model_catalog.updated', 'payload' => ['revision' => $version, 'published' => $request->boolean('publish')]]);

            return back()->with('status', $request->boolean('publish') ? 'Fünf Modellstufen signiert veröffentlicht. Unterstützte Desktops übernehmen sie beim nächsten Katalogabruf.' : 'Entwurf gespeichert. Der aktive Modellkatalog bleibt erhalten.');
        });
    }
}
