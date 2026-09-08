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
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'models' => ['required', 'array', 'size:5'], 'models.*' => ['required', 'json', 'max:20000'], 'publish' => ['nullable', 'boolean'],
            'profiles' => ['sometimes', 'array', 'size:5'], 'profiles.*.name' => ['required', 'string', 'max:160'],
            'profiles.*.enabled' => ['required', 'boolean'], 'profiles.*.context' => ['required', 'integer', 'min:512', 'max:2000000'],
            'profiles.*.total_ram' => ['required', 'numeric', 'min:1', 'max:1024'], 'profiles.*.free_ram' => ['required', 'numeric', 'min:0.5', 'max:1024'],
            'profiles.*.vram' => ['required', 'numeric', 'min:0', 'max:1024']]);

        return DB::transaction(function () use ($request, $data, $tiers, $manifest) {
            // The owner row serializes the first creation too.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $catalog = LocalModelCatalog::firstOrCreate(['id' => 1], ['draft' => $tiers->defaults(), 'revision' => 0]);
            $catalog = LocalModelCatalog::whereKey(1)->lockForUpdate()->firstOrFail();
            abort_unless((int) $data['revision'] === $catalog->revision, 409, 'Die Modellkonfiguration wurde inzwischen geändert. Bitte neu laden.');
            $draft = $catalog->draft;
            $models = array_map(fn ($json) => json_decode($json, true, 32, JSON_THROW_ON_ERROR), $data['models']);
            if (collect($models)->contains(fn ($model) => ! is_array($model) || array_is_list($model))) {
                throw ValidationException::withMessages(['models' => 'Jede Modellstufe muss ein JSON-Objekt enthalten.']);
            }
            foreach (($data['profiles'] ?? []) as $index => $profile) {
                abort_unless(isset($models[$index]), 422);
                $models[$index]['display_name'] = $profile['name'];
                $models[$index]['enabled'] = (bool) $profile['enabled'];
                $models[$index]['context_limit'] = (int) $profile['context'];
                $models[$index]['capacity_policy']['min_total_ram_bytes'] = (int) round($profile['total_ram'] * 1024 ** 3);
                $models[$index]['capacity_policy']['min_available_ram_bytes'] = (int) round($profile['free_ram'] * 1024 ** 3);
                $models[$index]['capacity_policy']['min_vram_bytes'] = (int) round($profile['vram'] * 1024 ** 3);
            }
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
                try {
                    $manifest->validateCatalog($draft, true);
                } catch (LocalModelManifestConfigurationException $error) {
                    throw ValidationException::withMessages(['models' => 'Der Katalog konnte nicht signiert werden: '.$error->getMessage()]);
                }
                $updates['published'] = $draft;
            }
            $catalog->update($updates);
            app(AuditLogger::class)->record(['actor_user_id' => $request->user()->id, 'event_type' => 'local_model_catalog.updated', 'payload' => ['revision' => $version, 'published' => $request->boolean('publish')]]);

            return back()->with('status', $request->boolean('publish') ? 'Fünf Modellstufen signiert veröffentlicht. Unterstützte Desktops übernehmen sie beim nächsten Katalogabruf.' : 'Entwurf gespeichert. Der aktive Modellkatalog bleibt erhalten.');
        });
    }
}
