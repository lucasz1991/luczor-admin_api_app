<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DeviceJob;
use App\Models\DeviceDebugRequest;
use App\Models\EvaluationResult;
use App\Models\LuczorAgentEventArchive;
use App\Models\LuczorMemoryArchive;
use App\Models\LuczorMessageArchive;
use App\Models\LuczorProjectArchive;
use App\Models\LuczorSummaryArchive;
use App\Models\LlmRun;
use App\Models\LlmAttempt;
use App\Models\ModelRanking;
use App\Models\ProviderPriceSnapshot;
use App\Models\PromptTemplate;
use App\Models\ContextStrategy;
use App\Models\NetworkPolicy;
use App\Models\LlmExperiment;
use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\ProviderCredential;
use App\Models\Project;
use App\Models\Setting;
use App\Models\AgentProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user?->isAdmin() ?? false;
        if ($isAdmin) return view('admin.page', $this->adminData('overview'));
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
            'telemetry' => $isAdmin ? $this->telemetrySummary() : [],
            'modelTelemetry' => $isAdmin ? $this->modelTelemetry() : collect(),
            'recentAttempts' => $isAdmin ? LlmAttempt::query()->with('run')->latest()->limit(30)->get() : collect(),
            'modelRankings' => $isAdmin ? ModelRanking::query()->whereNull('user_id')->orderBy('task_type')->orderByDesc('score')->get() : collect(),
            'providerPrices' => $isAdmin ? ProviderPriceSnapshot::query()->latest('valid_from')->get() : collect(),
            'promptTemplates' => $isAdmin ? PromptTemplate::query()->latest('version')->get() : collect(),
            'contextStrategies' => $isAdmin ? ContextStrategy::query()->orderBy('key')->get() : collect(),
            'networkPolicies' => $isAdmin ? NetworkPolicy::query()->orderBy('key')->get() : collect(),
            'llmExperiments' => $isAdmin ? LlmExperiment::query()->latest()->get() : collect(),
            'agentProfiles' => $isAdmin ? AgentProfile::query()->orderBy('type')->get() : collect(),
            'devices' => $isAdmin ? Device::query()->with('user')->latest('last_seen_at')->get() : collect(),
            'debugRequests' => $isAdmin ? DeviceDebugRequest::query()->with('device')->latest()->limit(50)->get() : collect(),
        ]);
    }

    public function page(Request $request, string $page)
    {
        $this->ensureAdmin($request);
        abort_unless(in_array($page, ['overview', 'providers', 'models', 'telemetry', 'optimizer', 'experiments', 'devices', 'api-keys', 'archives', 'settings'], true), 404);
        return view('admin.page', $this->adminData($page));
    }

    public function requestDeviceDebug(Request $request, Device $device)
    {
        $this->ensureAdmin($request);
        DeviceDebugRequest::create([
            'device_id' => $device->id,
            'user_id' => $device->user_id,
            'requested_by' => $request->user()->id,
            'status' => 'pending',
            'meta' => ['source' => 'admin_dashboard'],
        ]);

        return Redirect::route('dashboard')->with('status', 'Debug-Anforderung an das Gerät gesendet.');
    }

    public function downloadDeviceDebug(Request $request, DeviceDebugRequest $debugRequest)
    {
        $this->ensureAdmin($request);
        abort_unless($debugRequest->status === 'completed' && is_array($debugRequest->payload), 404);

        $filename = 'luczor-debug-'.$debugRequest->device_id.'-'.now()->format('Ymd-His').'.json';
        return response()->streamDownload(function () use ($debugRequest) {
            echo json_encode($debugRequest->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $filename, ['Content-Type' => 'application/json; charset=UTF-8']);
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
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'device_id' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date'],
        ];
        if ($request->user()?->isAdmin()) {
            $rules['abilities'] = ['required', 'array', 'min:1'];
            $rules['abilities.*'] = [Rule::in(ApiKey::ABILITIES)];
        }
        $data = $request->validate($rules);
        $abilities = $request->user()?->isAdmin()
            ? $data['abilities']
            : ['sync.read', 'sync.write', 'settings.read', 'brain.read', 'brain.write', 'proxy.use', 'device.connect', 'device.jobs.read', 'device.jobs.write'];

        $minted = ApiKey::mint([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'device_id' => $data['device_id'] ?? null,
            'device_name' => $data['device_name'] ?? null,
            'abilities' => $abilities,
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

    public function toggleProviderCredential(Request $request, ProviderCredential $providerCredential)
    {
        $this->ensureAdmin($request);
        $providerCredential->update(['active' => ! $providerCredential->active]);
        return Redirect::route('dashboard')->with('status', 'Provider status updated.');
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
        $data = $request->validate(['key' => ['required','string','max:120'], 'task_type' => ['nullable','string','max:120'], 'body' => ['required','string','max:100000']]);
        $version = ((int) PromptTemplate::where('key', $data['key'])->max('version')) + 1;
        PromptTemplate::create($data + ['version' => $version, 'status' => 'active']);
        return Redirect::route('dashboard')->with('status', 'Prompt version saved.');
    }

    public function storeContextStrategy(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate(['key' => ['required','string','max:120'], 'name' => ['required','string','max:190'], 'config' => ['required','json']]);
        ContextStrategy::updateOrCreate(['key' => $data['key']], ['name' => $data['name'], 'status' => 'active', 'config' => json_decode($data['config'], true, 512, JSON_THROW_ON_ERROR)]);
        return Redirect::route('dashboard')->with('status', 'Context strategy saved.');
    }

    public function storeNetworkPolicy(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'key' => ['required','string','max:120'], 'name' => ['required','string','max:190'],
            'connect_timeout_ms' => ['required','integer','min:100','max:120000'], 'request_timeout_ms' => ['required','integer','min:1000','max:600000'],
            'max_attempts' => ['required','integer','min:1','max:10'], 'backoff_ms' => ['required','integer','min:0','max:60000'],
            'max_cost_usd' => ['nullable','numeric','min:0'], 'max_input_tokens' => ['nullable','integer','min:1'], 'max_output_tokens' => ['nullable','integer','min:1'],
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

    public function exportTelemetry(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'format' => ['nullable', Rule::in(['jsonl', 'csv'])],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        $format = $data['format'] ?? 'jsonl';
        $days = (int) ($data['days'] ?? 30);
        $filename = 'luczor-telemetry-'.now()->format('Ymd-His').'.'.$format;

        return response()->streamDownload(function () use ($format, $days) {
            $output = fopen('php://output', 'wb');
            if ($format === 'csv') {
                fputcsv($output, ['request_id', 'created_at', 'task_type', 'selected_by', 'attempt', 'provider', 'model', 'status', 'http_status', 'ttft_ms', 'total_ms', 'input_tokens', 'output_tokens', 'tokens_per_second', 'cost_usd', 'cost_source', 'quality_score', 'test_passed']);
            }

            LlmRun::query()->with(['attempts', 'metrics', 'evaluations', 'toolCalls'])
                ->where('created_at', '>=', now()->subDays($days))->orderBy('id')
                ->chunkById(100, function ($runs) use ($format, $output) {
                    foreach ($runs as $run) {
                        if ($format === 'jsonl') {
                            fwrite($output, json_encode($run->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                            continue;
                        }
                        foreach ($run->attempts as $attempt) {
                            fputcsv($output, [$run->request_id, $run->created_at?->toIso8601String(), $run->task_type, $run->selected_by, $attempt->attempt_no, $attempt->provider_id, $attempt->model_id, $attempt->status, $attempt->http_status, $attempt->ttft_ms, $attempt->total_ms, $attempt->input_tokens, $attempt->output_tokens, $attempt->tokens_per_second, $attempt->effective_cost, $attempt->cost_source, $run->quality_score, $run->test_passed]);
                        }
                    }
                });
            fclose($output);
        }, $filename, ['Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/x-ndjson']);
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

    private function adminData(string $page): array
    {
        return [
            'page' => $page,
            'operations' => ['users' => \App\Models\User::count(), 'devices_online' => Device::where('status', 'online')->count(), 'device_jobs_open' => DeviceJob::whereIn('status', ['approval_required', 'queued', 'running'])->count(), 'llm_runs_24h' => LlmRun::where('created_at', '>=', now()->subDay())->count(), 'evaluations_24h' => EvaluationResult::where('created_at', '>=', now()->subDay())->count(), 'audit_events_24h' => AuditEvent::where('created_at', '>=', now()->subDay())->count()],
            'providers' => ProviderCredential::latest()->get(), 'modelProfiles' => ModelProfile::orderBy('purpose')->orderBy('name')->get(), 'modelUseCases' => ModelUseCase::with(['entries.modelProfile'])->orderBy('slug')->get(),
            'telemetry' => $this->telemetrySummary(), 'modelTelemetry' => $this->modelTelemetry(), 'recentAttempts' => LlmAttempt::with('run')->latest()->limit(50)->get(), 'modelRankings' => ModelRanking::whereNull('user_id')->orderBy('task_type')->orderByDesc('score')->get(),
            'providerPrices' => ProviderPriceSnapshot::latest('valid_from')->get(), 'promptTemplates' => PromptTemplate::latest('version')->get(), 'contextStrategies' => ContextStrategy::orderBy('key')->get(), 'networkPolicies' => NetworkPolicy::orderBy('key')->get(), 'llmExperiments' => LlmExperiment::latest()->get(),
            'devices' => Device::with('user')->latest('last_seen_at')->get(), 'debugRequests' => DeviceDebugRequest::with('device')->latest()->limit(50)->get(), 'apiKeys' => ApiKey::with('user')->latest()->get(), 'abilities' => ApiKey::ABILITIES,
            'archiveCounts' => ['projects' => LuczorProjectArchive::count(), 'messages' => LuczorMessageArchive::count(), 'memories' => LuczorMemoryArchive::count(), 'summaries' => LuczorSummaryArchive::count(), 'agent_events' => LuczorAgentEventArchive::count()], 'settings' => Setting::orderBy('group')->orderBy('key')->get(),
        ];
    }

    private function telemetrySummary(): array
    {
        $q = LlmRun::query()->where('created_at', '>=', now()->subDays(30));
        $total = (clone $q)->count();
        $successful = (clone $q)->where('success', true)->count();
        $cost = (float) (clone $q)->sum('cost_total');
        return [
            'runs_30d' => $total,
            'success_rate' => $total ? round($successful / $total * 100, 1) : 0,
            'cost_30d' => round($cost, 6),
            'cost_per_success' => $successful ? round($cost / $successful, 6) : 0,
            'avg_latency_ms' => (int) round((float) (clone $q)->avg('latency_ms')),
            'avg_ttft_ms' => (int) round((float) (clone $q)->avg('ttft_ms')),
            'avg_tokens_per_second' => round((float) (clone $q)->avg('tokens_per_second'), 2),
            'fallback_rate' => $total ? round((clone $q)->where('attempt_count', '>', 1)->count() / $total * 100, 1) : 0,
            'input_tokens' => (int) (clone $q)->sum('input_tokens'),
            'output_tokens' => (int) (clone $q)->sum('output_tokens'),
        ];
    }

    private function modelTelemetry()
    {
        return LlmRun::query()->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('model_id, provider_id, task_type, count(*) as runs, avg(case when success then 1 else 0 end) as success_rate, avg(latency_ms) as avg_latency_ms, avg(ttft_ms) as avg_ttft_ms, avg(tokens_per_second) as avg_tps, sum(cost_total) as total_cost, avg(cost_total) as avg_cost, avg(quality_score) as avg_quality')
            ->groupBy('model_id', 'provider_id', 'task_type')->orderByDesc('runs')->get();
    }
}
