<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\LuczorAgentEventArchive;
use App\Models\LuczorMemoryArchive;
use App\Models\LuczorMessageArchive;
use App\Models\LuczorProjectArchive;
use App\Models\LuczorSummaryArchive;
use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\ProviderCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function index()
    {
        return view('dashboard.index', [
            'apiKeys' => ApiKey::with('user')->latest()->get(),
            'abilities' => ApiKey::ABILITIES,
            'providers' => ProviderCredential::latest()->get(),
            'modelProfiles' => ModelProfile::orderBy('name')->get(),
            'modelUseCases' => ModelUseCase::with(['entries.modelProfile'])->orderBy('slug')->get(),
            'archiveCounts' => [
                'projects' => LuczorProjectArchive::count(),
                'messages' => LuczorMessageArchive::count(),
                'memories' => LuczorMemoryArchive::count(),
                'summaries' => LuczorSummaryArchive::count(),
                'agent_events' => LuczorAgentEventArchive::count(),
            ],
        ]);
    }

    public function storeApiKey(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'device_id' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(ApiKey::ABILITIES)],
            'expires_at' => ['nullable', 'date'],
        ]);

        $minted = ApiKey::mint([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'device_id' => $data['device_id'] ?? null,
            'device_name' => $data['device_name'] ?? null,
            'abilities' => $data['abilities'],
            'expires_at' => $data['expires_at'] ?? null,
            'active' => true,
        ]);

        return Redirect::route('dashboard')->with('plain_api_key', $minted['plain']);
    }

    public function toggleApiKey(ApiKey $apiKey)
    {
        $apiKey->forceFill(['active' => ! $apiKey->active])->save();

        return Redirect::route('dashboard')->with('status', 'API key updated.');
    }

    public function storeProviderCredential(Request $request)
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:80'],
            'label' => ['required', 'string', 'max:120'],
            'api_key' => ['required', 'string'],
            'base_url' => ['nullable', 'url', 'max:255'],
        ]);

        ProviderCredential::create($data + ['active' => true]);

        return Redirect::route('dashboard')->with('status', 'Provider credential saved.');
    }

    public function storeModelProfile(Request $request)
    {
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

        return Redirect::route('dashboard')->with('status', 'Model profile saved.');
    }

    public function storeModelUseCase(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['slug'] = Str::slug($data['name']);
        $data['active'] = true;

        ModelUseCase::updateOrCreate(['slug' => $data['slug']], $data);

        return Redirect::route('dashboard')->with('status', 'Model use case saved.');
    }

    public function storeModelUseCaseEntry(Request $request)
    {
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

        return Redirect::route('dashboard')->with('status', 'Fallback order updated.');
    }
}
