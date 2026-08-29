<?php

namespace App\Services;

use App\Models\AgentProfile;
use App\Models\AgentRun;
use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\ContextStrategy;
use App\Models\Device;
use App\Models\DeviceDebugRequest;
use App\Models\DeviceJob;
use App\Models\EvaluationResult;
use App\Models\LlmAttempt;
use App\Models\LlmExperiment;
use App\Models\LlmRun;
use App\Models\LuczorAgentEventArchive;
use App\Models\LuczorMemoryArchive;
use App\Models\LuczorMessageArchive;
use App\Models\LuczorProjectArchive;
use App\Models\LuczorSummaryArchive;
use App\Models\MemoryLink;
use App\Models\ModelProfile;
use App\Models\ModelRanking;
use App\Models\ModelUseCase;
use App\Models\NetworkPolicy;
use App\Models\Persona;
use App\Models\Project;
use App\Models\PromptTemplate;
use App\Models\ProviderCredential;
use App\Models\ProviderPriceSnapshot;
use App\Models\Setting;
use App\Models\Skill;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminDashboardData
{
    public function dashboard(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user?->isAdmin() ?? false;
        // H5-Fix: admins render the full dashboard.index (settings + model/use-case
        // forms). The admin.page UI stays reachable via GET /admin/{page}. Without
        // this, the rich admin forms below were unreachable (redirect-before-render).
        $clientIds = $this->ownedClientIds($user);

        $apiKeys = ApiKey::with('user')
            ->when(! $isAdmin, fn ($query) => $query->where('user_id', $user->id))
            ->latest()
            ->get();

        return [
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
                'users' => User::count(),
                'devices_online' => Device::query()->where('status', 'online')->count(),
                'device_jobs_open' => DeviceJob::query()->whereIn('status', ['approval_required', 'queued', 'running'])->count(),
                'llm_runs_24h' => LlmRun::query()->where('created_at', '>=', now()->subDay())->count(),
                'evaluations_24h' => EvaluationResult::query()->where('created_at', '>=', now()->subDay())->count(),
                'audit_events_24h' => AuditEvent::query()->where('created_at', '>=', now()->subDay())->count(),
            ] : [],
            'charts' => $isAdmin ? $this->dashboardCharts() : [],
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
        ];
    }

    public function forPage(string $page): array
    {
        if ($page === 'archives') {
            return $this->archivesPageData();
        }

        return [
            'page' => $page,
            'operations' => ['users' => User::count(), 'devices_online' => Device::where('status', 'online')->count(), 'device_jobs_open' => DeviceJob::whereIn('status', ['approval_required', 'queued', 'running'])->count(), 'llm_runs_24h' => LlmRun::where('created_at', '>=', now()->subDay())->count(), 'evaluations_24h' => EvaluationResult::where('created_at', '>=', now()->subDay())->count(), 'audit_events_24h' => AuditEvent::where('created_at', '>=', now()->subDay())->count()],
            'providers' => ProviderCredential::latest()->get(), 'modelProfiles' => ModelProfile::orderBy('purpose')->orderBy('name')->get(), 'modelUseCases' => ModelUseCase::with(['entries.modelProfile'])->orderBy('slug')->get(),
            'telemetry' => $this->telemetrySummary(), 'modelTelemetry' => $this->modelTelemetry(), 'recentAttempts' => LlmAttempt::with('run')->latest()->limit(50)->get(), 'modelRankings' => ModelRanking::whereNull('user_id')->orderBy('task_type')->orderByDesc('score')->get(),
            'providerPrices' => ProviderPriceSnapshot::latest('valid_from')->get(), 'promptTemplates' => PromptTemplate::latest('version')->get(), 'contextStrategies' => ContextStrategy::orderBy('key')->get(), 'networkPolicies' => NetworkPolicy::orderBy('key')->get(), 'llmExperiments' => LlmExperiment::latest()->get(),
            'devices' => Device::with('user')->latest('last_seen_at')->get(), 'debugRequests' => DeviceDebugRequest::with('device')->latest()->limit(50)->get(), 'apiKeys' => ApiKey::with('user')->latest()->get(), 'abilities' => ApiKey::ABILITIES,
            'archiveCounts' => ['projects' => LuczorProjectArchive::count(), 'messages' => LuczorMessageArchive::count(), 'memories' => LuczorMemoryArchive::count(), 'summaries' => LuczorSummaryArchive::count(), 'agent_events' => LuczorAgentEventArchive::count()], 'settings' => Setting::orderBy('group')->orderBy('key')->get(),
            'charts' => $page === 'overview' ? $this->dashboardCharts() : [],
            'telemetryCharts' => $page === 'telemetry' ? $this->telemetryCharts() : [],
            'memoryOverview' => $page === 'archives' ? $this->memoryOverview() : [],
            'memoryGraph' => $page === 'archives' ? $this->memoryGraph() : [],
            'personas' => $page === 'optimizer' ? Persona::orderBy('name')->get() : collect(),
            'skills' => $page === 'optimizer' ? Skill::with('workflowDefinition')->orderByDesc('active')->orderBy('name')->get() : collect(),
            'skillWorkflows' => $page === 'optimizer' ? WorkflowDefinition::where('status', 'active')->orderBy('name')->get(['id', 'name']) : collect(),
            'reflections' => $page === 'optimizer'
                ? EvaluationResult::with('llmRun')->latest()->limit(20)->get()
                : collect(),
            'agentRuns' => $page === 'agents' ? AgentRun::withCount('tasks')->latest()->limit(30)->get() : collect(),
            'agentEvents' => $page === 'agents' ? AuditEvent::latest()->limit(50)->get() : collect(),
            'workflowDefinitions' => $page === 'workflows'
                ? WorkflowDefinition::withCount(['runs',
                    'runs as successful_runs_count' => fn ($q) => $q->where('status', 'completed'),
                    'runs as failed_runs_count' => fn ($q) => $q->where('status', 'failed'),
                ])->latest()->limit(50)->get()
                : collect(),
            'workflowRuns' => $page === 'workflows' ? WorkflowRun::with('definition')->latest()->limit(20)->get() : collect(),
            'taskCatalog' => $page === 'workflows' ? WorkflowTaskCatalog::options() : [],
            'workflowTemplates' => $page === 'workflows' ? WorkflowTemplateService::templates() : [],
            // Board editor (?wf=) and run preview (?run=) context of the workflows page.
            'workflowEditing' => $page === 'workflows' && request()->filled('wf')
                ? WorkflowDefinition::find((int) request()->query('wf'))
                : null,
            'workflowPreviewRun' => $page === 'workflows' && request()->filled('run')
                ? WorkflowRun::with('definition')->find((int) request()->query('run'))
                : null,
        ];
    }

    private function archivesPageData(): array
    {
        $totalMemories = MemoryLink::count();

        return [
            'page' => 'archives',
            'archiveCounts' => [
                'projects' => LuczorProjectArchive::count(),
                'messages' => LuczorMessageArchive::count(),
                'memories' => LuczorMemoryArchive::count(),
                'summaries' => LuczorSummaryArchive::count(),
                'agent_events' => LuczorAgentEventArchive::count(),
            ],
            'memoryOverview' => $this->memoryOverview($totalMemories),
            'memoryGraph' => $this->memoryGraph($totalMemories),
        ];
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

    private function dashboardCharts(): array
    {
        $days = 14;
        $w = 560.0;
        $h = 120.0;      // drawable height
        $baseY = 130.0;  // baseline
        $start = now()->subDays($days - 1)->startOfDay();

        $rows = LlmRun::query()->where('created_at', '>=', $start)
            ->selectRaw('date(created_at) as d, count(*) as runs, sum(cost_total) as cost')
            ->groupBy('d')->get()->keyBy('d');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $start->copy()->addDays($i)->toDateString();
            $r = $rows->get($d);
            $series[] = [
                'd' => $d,
                'runs' => (int) ($r?->getAttribute('runs') ?? 0),
                'cost' => (float) ($r?->getAttribute('cost') ?? 0),
            ];
        }
        $maxRuns = max(1, max(array_column($series, 'runs')));
        $maxCost = max(0.0000001, max(array_column($series, 'cost')));
        $slot = $w / $days;

        $bars = [];
        $costPts = [];
        foreach ($series as $i => $point) {
            $bh = round($point['runs'] / $maxRuns * $h, 1);
            $bars[] = [
                'x' => round($i * $slot + 3, 1), 'y' => round($baseY - $bh, 1),
                'w' => round($slot - 6, 1), 'h' => $bh,
                'day' => (int) substr($point['d'], 8, 2), 'runs' => $point['runs'],
            ];
            $cx = round($i * $slot + $slot / 2, 1);
            $cy = round($baseY - $point['cost'] / $maxCost * $h, 1);
            $costPts[] = "{$cx},{$cy}";
        }

        $providerRows = LlmRun::query()->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('provider_id, count(*) as runs')->groupBy('provider_id')->orderByDesc('runs')->limit(6)->get();
        $providerMax = max(1, (int) ($providerRows->max('runs') ?? 1));
        $providers = $providerRows->map(function (LlmRun $run) use ($providerMax) {
            $runs = (int) $run->getAttribute('runs');

            return [
                'label' => $run->provider_id ?: '—',
                'value' => $runs,
                'pct' => round($runs / $providerMax * 100),
            ];
        })->all();

        $wfRows = WorkflowRun::query()->selectRaw('status, count(*) as c')->groupBy('status')->get();
        $wfMax = max(1, (int) ($wfRows->max('c') ?? 1));
        $workflowStatus = $wfRows->map(function (WorkflowRun $run) use ($wfMax) {
            $count = (int) $run->getAttribute('c');

            return [
                'label' => $run->status,
                'value' => $count,
                'pct' => round($count / $wfMax * 100),
            ];
        })->all();

        return [
            'bars' => $bars, 'cost_points' => implode(' ', $costPts),
            'providers' => $providers, 'workflow_status' => $workflowStatus,
            'total_runs' => array_sum(array_column($series, 'runs')),
            'total_cost' => round(array_sum(array_column($series, 'cost')), 4),
        ];
    }

    private function memoryGraph(?int $total = null): array
    {
        $memories = MemoryLink::query()
            ->orderByDesc('importance')
            ->orderByDesc('updated_at')
            ->limit(60)
            ->get([
                'id', 'user_id', 'tenant_id', 'scope', 'type', 'project_id', 'project_ref_id', 'feature_key',
                'importance', 'confidence', 'summary', 'dataset', 'status',
                'projection_status', 'staleness',
                'source_type', 'supersedes_id', 'cognee_memory_id', 'updated_at',
            ]);
        $projects = Project::query()
            ->whereIn('id', $memories->pluck('project_ref_id')->filter()->unique())
            ->pluck('name', 'id');
        $visibleIds = $memories->pluck('id');
        $mappedMemories = $memories->map(fn (MemoryLink $m) => [
            'id' => $m->id,
            'scope' => $m->scope ?: 'global',
            'type' => $m->type ?: 'note',
            'project' => $m->project_ref_id
                ? (string) ($projects[$m->project_ref_id] ?? 'Projekt #'.$m->project_ref_id)
                : ($m->project_id ?: 'Ohne Projekt'),
            'project_key' => $m->project_ref_id
                ? 'ref:'.$m->project_ref_id
                : 'legacy:'.hash('sha256', implode('|', [
                    $m->tenant_id ?? '-',
                    $m->user_id ?? '-',
                    $m->project_id ?? '-',
                ])),
            'feature_key' => $m->feature_key,
            'importance' => (float) $m->importance,
            'confidence' => $m->getAttribute('confidence') === null ? null : (float) $m->getAttribute('confidence'),
            'summary' => Str::limit((string) $m->summary, 220),
            'dataset' => $m->dataset,
            'status' => $m->status ?: 'active',
            'projection_status' => $m->projection_status ?: 'unbekannt',
            'staleness' => $m->staleness ?: 'unbekannt',
            'source_type' => $m->source_type ?: 'unbekannt',
            'supersedes_id' => $m->supersedes_id,
            'cognee_projected' => filled($m->cognee_memory_id),
            'updated_at' => $m->updated_at?->format('d.m.Y H:i'),
        ])->values();

        return [
            'total' => $total ?? MemoryLink::count(),
            'visible' => $mappedMemories->count(),
            'projects' => $mappedMemories->pluck('project_key')->unique()->count(),
            'types' => $mappedMemories->pluck('type')->unique()->count(),
            'version_edges' => $memories
                ->filter(fn (MemoryLink $memory) => $memory->supersedes_id !== null && $visibleIds->contains($memory->supersedes_id))
                ->count(),
            'memories' => $mappedMemories->all(),
        ];
    }

    private function memoryOverview(?int $total = null): array
    {
        $total ??= MemoryLink::count();
        $mk = fn (array $counts) => collect($counts)->map(fn ($value, $label) => [
            'label' => (string) $label,
            'value' => (int) $value,
            'pct' => (int) round($value / max(1, $total) * 100),
        ])->values()->all();

        $byScope = MemoryLink::query()->selectRaw('scope, count(*) c')->groupBy('scope')->pluck('c', 'scope')->all();
        $byType = MemoryLink::query()->selectRaw('type, count(*) c')->groupBy('type')->orderByDesc('c')->limit(8)->pluck('c', 'type')->all();
        $byProject = MemoryLink::query()
            ->where(fn ($query) => $query->whereNotNull('project_ref_id')->orWhereNotNull('project_id'))
            ->selectRaw('project_ref_id, project_id, tenant_id, user_id, count(*) c')
            ->groupBy('project_ref_id', 'project_id', 'tenant_id', 'user_id')
            ->orderByDesc('c')
            ->limit(8)
            ->get();
        $projectNames = Project::query()
            ->whereIn('id', $byProject->pluck('project_ref_id')->filter()->unique())
            ->pluck('name', 'id');
        $byProjectRows = $byProject->map(function (MemoryLink $memory) use ($projectNames, $total) {
            $projectRefId = $memory->getAttribute('project_ref_id');
            $projectId = $memory->getAttribute('project_id');
            $count = (int) $memory->getAttribute('c');

            return [
                'label' => $projectRefId
                    ? (string) ($projectNames[$projectRefId] ?? 'Projekt #'.$projectRefId)
                    : (string) ($projectId ?: 'Ohne Projekt'),
                'value' => $count,
                'pct' => (int) round($count / max(1, $total) * 100),
            ];
        })->all();

        return [
            'total' => $total,
            'by_scope' => $mk($byScope),
            'by_type' => $mk($byType),
            'by_project' => $byProjectRows,
        ];
    }

    private function telemetryCharts(): array
    {
        $days = 30;
        $w = 560.0;
        $h = 120.0;
        $baseY = 130.0;
        $start = now()->subDays($days - 1)->startOfDay();

        $rows = LlmRun::query()->where('created_at', '>=', $start)
            ->selectRaw('date(created_at) as d, count(*) as runs, sum(cost_total) as cost, avg(case when success then 1 else 0 end) as sr')
            ->groupBy('d')->get()->keyBy('d');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $start->copy()->addDays($i)->toDateString();
            $r = $rows->get($d);
            $series[] = [
                'runs' => (int) ($r?->getAttribute('runs') ?? 0),
                'cost' => (float) ($r?->getAttribute('cost') ?? 0),
                'sr' => (float) ($r?->getAttribute('sr') ?? 0),
            ];
        }
        $maxRuns = max(1, max(array_column($series, 'runs')));
        $maxCost = max(0.0000001, max(array_column($series, 'cost')));
        $slot = $w / $days;

        $bars = [];
        $costPts = [];
        $srPts = [];
        foreach ($series as $i => $p) {
            $bh = round($p['runs'] / $maxRuns * $h, 1);
            $bars[] = ['x' => round($i * $slot + 1, 1), 'y' => round($baseY - $bh, 1), 'w' => round($slot - 2, 1), 'h' => $bh, 'runs' => $p['runs']];
            $cx = round($i * $slot + $slot / 2, 1);
            $costPts[] = $cx.','.round($baseY - $p['cost'] / $maxCost * $h, 1);
            $srPts[] = $cx.','.round($baseY - $p['sr'] * $h, 1);
        }

        return ['bars' => $bars, 'cost_points' => implode(' ', $costPts), 'sr_points' => implode(' ', $srPts)];
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
