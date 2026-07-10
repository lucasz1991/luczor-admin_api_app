<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\EvaluationResult;
use App\Models\LuczorAgentEventArchive;
use App\Models\LuczorMemoryArchive;
use App\Models\LuczorMessageArchive;
use App\Models\LuczorProjectArchive;
use App\Models\LuczorSummaryArchive;
use App\Models\LlmRun;
use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\ProviderCredential;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user?->isAdmin() ?? false;
        $clientIds = $this->ownedClientIds($user);

        $apiKeys = ApiKey::with('user')
            ->when(! $isAdmin, fn ($query) => $query->where('user_id', $user->id))
            ->latest()
            ->get();

        return view('dashboard.index', [
            'isAdmin' => $isAdmin,
            'clientIds' => $clientIds,
            'apiKeys' => $apiKeys,
            'abilities' => ApiKey::ABILITIES,
            'providers' => $isAdmin ? ProviderCredential::latest()->get() : collect(),
            'modelProfiles' => $isAdmin ? ModelProfile::orderBy('name')->get() : collect(),
            'modelUseCases' => $isAdmin ? ModelUseCase::with(['entries.modelProfile'])->orderBy('slug')->get() : collect(),
            'archiveCounts' => [
                'projects' => $this->archiveQuery(LuczorProjectArchive::class, $isAdmin, $user?->id)->count(),
                'messages' => $this->archiveQuery(LuczorMessageArchive::class, $isAdmin, $user?->id)->count(),
                'memories' => $this->archiveQuery(LuczorMemoryArchive::class, $isAdmin, $user?->id)->count(),
                'summaries' => $this->archiveQuery(LuczorSummaryArchive::class, $isAdmin, $user?->id)->count(),
                'agent_events' => $this->archiveQuery(LuczorAgentEventArchive::class, $isAdmin, $user?->id)->count(),
            ],
            'settings' => $isAdmin ? Setting::orderBy('group')->orderBy('key')->get() : collect(),
            'userProjects' => $isAdmin ? collect() : Project::query()->where('user_id', $user->id)->latest('updated_at')->limit(8)->get(),
            'userEvents' => $isAdmin ? collect() : $this->archiveQuery(LuczorAgentEventArchive::class, false, $user->id)->latest('created_at')->limit(10)->get(),
            'operations' => $isAdmin ? [
                'users' => \App\Models\User::count(),
                'devices_online' => Device::query()->where('status', 'online')->count(),
                'device_jobs_open' => DeviceJob::query()->whereIn('status', ['approval_required', 'queued', 'running'])->count(),
                'llm_runs_24h' => LlmRun::query()->where('created_at', '>=', now()->subDay())->count(),
                'evaluations_24h' => EvaluationResult::query()->where('created_at', '>=', now()->subDay())->count(),
                'audit_events_24h' => AuditEvent::query()->where('created_at', '>=', now()->subDay())->count(),
            ] : [],
        ]);
    }

    public function storeSettings(Request $request)
    {
        $this->ensureAdmin($request);

        $incoming = (array) $request->input('settings', []);

        foreach (Setting::all() as $setting) {
            $attrs = ['group' => $setting->group, 'label' => $setting->label, 'type' => $setting->type];

            if (! array_key_exists($setting->key, $incoming)) {
                // Unchecked checkboxes are absent -> false.
                if ($setting->type === 'bool') {
                    Setting::putValue($setting->key, false, $attrs);
                }
                continue;
            }

            $raw = $incoming[$setting->key];
            $value = match ($setting->type) {
                'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                'number' => is_numeric($raw) ? (float) $raw : 0,
                default => (string) $raw,
            };

            Setting::putValue($setting->key, $value, $attrs);
        }

        return Redirect::route('dashboard')->with('status', 'Einstellungen gespeichert.');
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

    public function toggleApiKey(Request $request, ApiKey $apiKey)
    {
        if (! $request->user()?->isAdmin() && (int) $apiKey->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        $apiKey->forceFill(['active' => ! $apiKey->active])->save();

        return Redirect::route('dashboard')->with('status', 'API key updated.');
    }

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

        return Redirect::route('dashboard')->with('status', 'Provider credential saved.');
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

        return Redirect::route('dashboard')->with('status', 'Model profile saved.');
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

        return Redirect::route('dashboard')->with('status', 'Model use case saved.');
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

        return Redirect::route('dashboard')->with('status', 'Fallback order updated.');
    }

    private function ownedClientIds($user)
    {
        if (! $user) {
            return collect();
        }

        return ApiKey::where('user_id', $user->id)
            ->whereNotNull('device_id')
            ->pluck('device_id')
            ->filter()
            ->unique()
            ->values();
    }

    private function archiveQuery(string $modelClass, bool $isAdmin, ?int $userId)
    {
        $query = $modelClass::query();

        // Archive rows without an owner are legacy data and must be migrated
        // before any user can see them. Client ids are device metadata, not an
        // authorization boundary.
        return $isAdmin ? $query : $query->where('user_id', $userId);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403);
    }
}
