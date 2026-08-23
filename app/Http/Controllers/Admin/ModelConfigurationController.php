<?php

namespace App\Http\Controllers\Admin;

use App\Models\AgentProfile;
use App\Models\ContextStrategy;
use App\Models\LlmExperiment;
use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\NetworkPolicy;
use App\Models\PromptTemplate;
use App\Models\ProviderCredential;
use App\Models\ProviderPriceSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ModelConfigurationController extends AdminController
{
    public function storeProviderCredential(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'provider' => ['required', 'string', 'max:80'],
            'label' => ['required', 'string', 'max:120'],
            'api_key' => ['required', 'string'],
            'base_url' => ['nullable', 'url', 'max:255'],
        ]);

        ProviderCredential::create($data + ['active' => true]);

        return Redirect::route('admin.page', 'providers')->with('status', 'Provider credential saved.');
    }

    public function toggleProviderCredential(Request $request, ProviderCredential $providerCredential)
    {
        $this->ensureAdmin($request);
        $providerCredential->update(['active' => ! $providerCredential->active]);

        return Redirect::route('admin.page', 'providers')->with('status', 'Provider status updated.');
    }

    public function storeModelProfile(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', 'string', 'max:80'],
            'model_id' => ['required', 'string', 'max:180'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['required', 'integer', 'min:1', 'max:200000'],
            'purpose' => ['nullable', 'string', 'max:120'],
        ]);

        $data['slug'] = Str::slug($data['name']);
        $data['active'] = true;

        ModelProfile::updateOrCreate(['slug' => $data['slug']], $data);

        return Redirect::route('admin.page', 'models')->with('status', 'Model profile saved.');
    }

    public function toggleModelProfile(Request $request, ModelProfile $modelProfile)
    {
        $this->ensureAdmin($request);
        $modelProfile->update(['active' => ! $modelProfile->active]);

        return Redirect::route('admin.page', 'models')->with('status', 'Model status updated.');
    }

    public function updateModelProfile(Request $request, ModelProfile $modelProfile)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'provider' => ['required', 'string', 'max:80'],
            'model_id' => ['required', 'string', 'max:180'], 'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['required', 'integer', 'min:1', 'max:200000'], 'purpose' => ['nullable', 'string', 'max:120'],
        ]);
        $modelProfile->update($data + ['slug' => Str::slug($data['name'])]);

        return Redirect::route('admin.page', 'models')->with('status', 'Model profile updated.');
    }

    public function destroyModelProfile(Request $request, ModelProfile $modelProfile)
    {
        $this->ensureAdmin($request);
        DB::transaction(function () use ($modelProfile) {
            $modelProfile->fallbackEntries()->delete();
            $modelProfile->delete();
        });

        return Redirect::route('admin.page', 'models')->with('status', 'Model profile deleted and removed from fallback chains.');
    }

    public function storeProviderPrice(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'provider_id' => ['required', 'string', 'max:80'], 'model_id' => ['required', 'string', 'max:190'],
            'currency' => ['required', 'string', 'max:8'], 'input_per_million' => ['required', 'numeric', 'min:0'],
            'output_per_million' => ['required', 'numeric', 'min:0'], 'cache_read_per_million' => ['nullable', 'numeric', 'min:0'],
            'cache_write_per_million' => ['nullable', 'numeric', 'min:0'], 'valid_from' => ['required', 'date'],
        ]);
        ProviderPriceSnapshot::query()->where('provider_id', $data['provider_id'])->where('model_id', $data['model_id'])->whereNull('valid_until')->update(['valid_until' => now()]);
        ProviderPriceSnapshot::create($data + ['source' => 'admin', 'cache_read_per_million' => $data['cache_read_per_million'] ?? 0, 'cache_write_per_million' => $data['cache_write_per_million'] ?? 0]);

        return Redirect::route('dashboard')->with('status', 'Price snapshot saved.');
    }

    public function storePromptTemplate(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'task_type' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string', 'max:40'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'body' => ['required', 'string', 'max:100000'],
        ]);
        // P19 — transactional versioning (archive old active, insert next version).
        PromptTemplate::publish($data['key'], $data['body'], [
            'task_type' => $data['task_type'] ?? null,
            'role' => $data['role'] ?? null,
            'priority' => (int) ($data['priority'] ?? 100),
        ]);

        return Redirect::route('admin.page', 'optimizer')->with('status', 'Prompt-Version veröffentlicht.');
    }

    public function storeContextStrategy(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate(['key' => ['required', 'string', 'max:120'], 'name' => ['required', 'string', 'max:190'], 'config' => ['required', 'json']]);
        ContextStrategy::updateOrCreate(['key' => $data['key']], ['name' => $data['name'], 'status' => 'active', 'config' => json_decode($data['config'], true, 512, JSON_THROW_ON_ERROR)]);

        return Redirect::route('dashboard')->with('status', 'Context strategy saved.');
    }

    public function storeNetworkPolicy(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'key' => ['required', 'string', 'max:120'], 'name' => ['required', 'string', 'max:190'],
            'connect_timeout_ms' => ['required', 'integer', 'min:100', 'max:120000'], 'request_timeout_ms' => ['required', 'integer', 'min:1000', 'max:600000'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:10'], 'backoff_ms' => ['required', 'integer', 'min:0', 'max:60000'],
            'max_cost_usd' => ['nullable', 'numeric', 'min:0'], 'max_input_tokens' => ['nullable', 'integer', 'min:1'], 'max_output_tokens' => ['nullable', 'integer', 'min:1'],
        ]);
        NetworkPolicy::updateOrCreate(['key' => $data['key']], $data + ['status' => 'active']);

        return Redirect::route('dashboard')->with('status', 'Network policy saved.');
    }

    public function storeLlmExperiment(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:190'],
            'task_type' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in(['draft', 'active', 'paused', 'completed'])],
            'traffic_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'variants' => ['required', 'json'],
            'success_criteria' => ['nullable', 'json'],
        ]);
        $data['variants'] = json_decode($data['variants'], true, 512, JSON_THROW_ON_ERROR);
        $data['success_criteria'] = filled($data['success_criteria'] ?? null)
            ? json_decode($data['success_criteria'], true, 512, JSON_THROW_ON_ERROR)
            : null;
        LlmExperiment::updateOrCreate(['key' => $data['key']], $data);

        return Redirect::route('dashboard')->with('status', 'LLM experiment saved.');
    }

    public function storeAgentProfile(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', 'string', 'max:80'],
            'status' => ['required', Rule::in(['active', 'disabled', 'draft'])],
            'prompt_template_key' => ['nullable', 'string', 'max:120'],
            'capabilities' => ['nullable', 'json'],
            'required_sources' => ['nullable', 'json'],
            'config' => ['nullable', 'json'],
        ]);
        foreach (['capabilities', 'required_sources', 'config'] as $field) {
            $data[$field] = filled($data[$field] ?? null)
                ? json_decode($data[$field], true, 512, JSON_THROW_ON_ERROR)
                : [];
        }
        AgentProfile::updateOrCreate(['key' => $data['key']], $data);

        return Redirect::route('dashboard')->with('status', 'Agent profile saved.');
    }

    public function storeModelUseCase(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['slug'] = Str::slug($data['name']);
        $data['active'] = true;

        ModelUseCase::updateOrCreate(['slug' => $data['slug']], $data);

        return Redirect::route('admin.page', 'models')->with('status', 'Model use case saved.');
    }

    public function storeModelUseCaseEntry(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'model_use_case_id' => ['required', 'exists:model_use_cases,id'],
            'model_profile_id' => ['required', 'exists:model_profiles,id'],
            'sort_order' => ['required', 'integer', 'min:1', 'max:999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        ModelUseCaseEntry::updateOrCreate(
            [
                'model_use_case_id' => $data['model_use_case_id'],
                'model_profile_id' => $data['model_profile_id'],
            ],
            [
                'sort_order' => $data['sort_order'],
                'notes' => $data['notes'] ?? null,
                'active' => true,
            ]
        );

        return Redirect::route('admin.page', 'models')->with('status', 'Fallback order updated.');
    }

    public function reorderModelUseCaseEntries(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'model_use_case_id' => ['required', 'exists:model_use_cases,id'],
            'entry_ids' => ['required', 'array', 'min:1'],
            'entry_ids.*' => ['integer'],
        ]);

        DB::transaction(function () use ($data) {
            $entries = ModelUseCaseEntry::where('model_use_case_id', $data['model_use_case_id'])
                ->lockForUpdate()->get()->keyBy('id');
            $order = 1;
            // Apply the requested order first (only ids that belong to this use case).
            foreach ($data['entry_ids'] as $id) {
                if ($entry = $entries->get($id)) {
                    $entry->update(['sort_order' => $order++]);
                    $entries->forget($id);
                }
            }
            // Any entry not named in the payload keeps a stable tail position.
            foreach ($entries->sortBy('sort_order') as $entry) {
                $entry->update(['sort_order' => $order++]);
            }
        });

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Reihenfolge gespeichert.']);
        }

        return Redirect::route('admin.page', 'models')->with('status', 'Reihenfolge gespeichert.');
    }

    public function reorderModelProfiles(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'profile_ids' => ['required', 'array', 'min:1'],
            'profile_ids.*' => ['integer'],
        ]);

        DB::transaction(function () use ($data) {
            $profiles = ModelProfile::lockForUpdate()->get()->keyBy('id');
            $order = 1;
            foreach ($data['profile_ids'] as $id) {
                if ($profile = $profiles->get($id)) {
                    $profile->update(['sort_order' => $order++]);
                    $profiles->forget($id);
                }
            }
            foreach ($profiles->sortBy('sort_order') as $profile) {
                $profile->update(['sort_order' => $order++]);
            }
        });

        return Redirect::route('admin.page', 'models')->with('status', 'Modell-Reihenfolge gespeichert.');
    }

    public function toggleModelUseCaseEntry(Request $request, ModelUseCaseEntry $modelUseCaseEntry)
    {
        $this->ensureAdmin($request);
        $modelUseCaseEntry->update(['active' => ! $modelUseCaseEntry->active]);

        return Redirect::route('admin.page', 'models')->with('status', 'Eintrag-Status aktualisiert.');
    }

    public function destroyModelUseCaseEntry(Request $request, ModelUseCaseEntry $modelUseCaseEntry)
    {
        $this->ensureAdmin($request);
        $useCaseId = $modelUseCaseEntry->model_use_case_id;
        DB::transaction(function () use ($modelUseCaseEntry, $useCaseId) {
            $modelUseCaseEntry->delete();
            $order = 1;
            foreach (ModelUseCaseEntry::where('model_use_case_id', $useCaseId)->orderBy('sort_order')->lockForUpdate()->get() as $entry) {
                $entry->update(['sort_order' => $order++]);
            }
        });

        return Redirect::route('admin.page', 'models')->with('status', 'Eintrag entfernt.');
    }
}
