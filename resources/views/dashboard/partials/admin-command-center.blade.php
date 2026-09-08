@php
    $onlineDevices = (int) ($operations['devices_online'] ?? 0);
    $openDeviceJobs = (int) ($operations['device_jobs_open'] ?? 0);
    $runs24h = (int) ($operations['llm_runs_24h'] ?? 0);
    $activeProviders = $providers->where('active', true)->count();
    $activeModels = $modelProfiles->where('active', true)->count();
    $activeApiKeys = $apiKeys
        ->filter(fn ($apiKey) => $apiKey->active && ! $apiKey->isExpired())
        ->count();
    $runs30d = (int) ($telemetry['runs_30d'] ?? 0);
    $successRate = (float) ($telemetry['success_rate'] ?? 0);
    $cost30d = (float) ($telemetry['cost_30d'] ?? 0);
    $archiveTotal = array_sum($archiveCounts);
    $workflowRunTotal = collect($charts['workflow_status'] ?? [])->sum('value');

    $systemTone = 'ready';
    $systemLabel = 'Konfiguration vorhanden';

    if ($activeProviders === 0 || $activeModels === 0) {
        $systemTone = 'setup';
        $systemLabel = 'Einrichtung offen';
    } elseif ($openDeviceJobs > 0) {
        $systemTone = 'attention';
        $systemLabel = 'Aufmerksamkeit';
    }

    $attentionCount = (int) ($activeProviders === 0)
        + (int) ($activeModels === 0)
        + (int) ($onlineDevices === 0)
        + (int) ($openDeviceJobs > 0)
        + (int) ($runs30d === 0);

    $metrics = [
        [
            'key' => 'devices-online',
            'label' => 'Geräte online',
            'value' => number_format($onlineDevices),
            'detail' => number_format($devices->count()).' registriert',
        ],
        [
            'key' => 'device-jobs-open',
            'label' => 'Offene Jobs',
            'value' => number_format($openDeviceJobs),
            'detail' => $runs24h.' LLM-Läufe in 24 h',
        ],
        [
            'key' => 'success-rate',
            'label' => 'Erfolgsrate',
            'value' => $runs30d > 0 ? number_format($successRate, 1).' %' : '—',
            'detail' => $runs30d > 0 ? number_format($runs30d).' Läufe in 30 Tagen' : 'Noch keine Messwerte',
        ],
        [
            'key' => 'cost-30d',
            'label' => 'Kosten · 30 Tage',
            'value' => '$ '.number_format($cost30d, 4),
            'detail' => number_format((float) ($telemetry['fallback_rate'] ?? 0), 1).' % Fallback-Rate',
        ],
    ];

    $modules = [
        [
            'label' => 'Provider & Preise',
            'description' => 'Credentials und Preissnapshots',
            'meta' => $activeProviders.' / '.$providers->count().' aktiv',
            'page' => 'providers',
            'icon' => 'cloud',
        ],
        [
            'label' => 'Modelle & Routing',
            'description' => 'Modell-Fallbacks pro Use-Case',
            'meta' => $activeModels.' / '.$modelProfiles->count().' aktiv',
            'page' => 'models',
            'icon' => 'cpu',
        ],
        [
            'label' => 'Telemetrie & Kosten',
            'description' => 'Qualität, Tempo und Verbrauch',
            'meta' => number_format($runs30d).' Läufe',
            'page' => 'telemetry',
            'icon' => 'activity',
        ],
        [
            'label' => 'Workflows',
            'description' => 'Abläufe steuern und prüfen',
            'meta' => number_format($workflowRunTotal).' Läufe',
            'page' => 'workflows',
            'icon' => 'workflow',
        ],
        [
            'label' => 'Geräte & Keys',
            'description' => 'Zugänge und Clients verwalten',
            'meta' => $activeApiKeys.' aktive Keys',
            'page' => 'api-keys',
            'icon' => 'key',
        ],
        [
            'label' => 'Archive & Audit',
            'description' => 'Memory und Verlauf überblicken',
            'meta' => number_format($archiveTotal).' Einträge',
            'page' => 'archives',
            'icon' => 'archive',
        ],
    ];
@endphp

<div class="space-y-6" data-admin-command-center>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Zentrale Betriebskennzahlen">
        @foreach ($metrics as $metric)
            <x-ui.stat :label="$metric['label']" :value="$metric['value']" :hint="$metric['detail']" data-dashboard-metric="{{ $metric['key'] }}" />
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-[1.6fr_1fr]">
        <x-ui.panel title="Läufe und Kosten" description="Gemeldete Modellaufrufe der letzten 14 Tage.">
            <x-slot:actions><x-ui.button variant="secondary" :href="route('admin.page', 'telemetry')">Telemetrie öffnen</x-ui.button></x-slot:actions>
            @if (($charts['total_runs'] ?? 0) > 0)
                <p class="mb-5 text-sm text-slate-400"><strong class="text-white">{{ number_format($charts['total_runs'] ?? 0) }}</strong> Läufe · $ {{ number_format($charts['total_cost'] ?? 0, 4) }}</p>
                <div class="admin-command-center__chart-canvas" style="min-height: 12rem">
                    <svg viewBox="0 0 560 140" preserveAspectRatio="none" role="img" aria-label="Läufe als Balken und Kosten als Linie für die letzten 14 Tage">
                        <line x1="0" y1="130" x2="560" y2="130" class="admin-command-center__chart-axis" />
                        @foreach (($charts['bars'] ?? []) as $bar)
                            <rect x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['w'] }}" height="{{ $bar['h'] }}" rx="2" class="admin-command-center__chart-bar"><title>{{ $bar['runs'] }} Läufe am Tag {{ $bar['day'] }}</title></rect>
                            <text x="{{ $bar['x'] + $bar['w'] / 2 }}" y="139" text-anchor="middle">{{ $bar['day'] }}</text>
                        @endforeach
                        @if (! empty($charts['cost_points']))<polyline points="{{ $charts['cost_points'] }}" class="admin-command-center__chart-cost" />@endif
                    </svg>
                </div>
                <div class="admin-command-center__legend" aria-hidden="true"><span><i class="admin-command-center__legend-bar"></i>Läufe</span><span><i class="admin-command-center__legend-line"></i>Kosten</span></div>
            @else
                <x-ui.empty title="Noch keine Läufe">Der Verlauf füllt sich mit den gemeldeten Modellaufrufen.</x-ui.empty>
            @endif
        </x-ui.panel>

        <x-ui.panel title="Im Blick behalten" description="Hinweise aus Konfiguration und Geräte-Rückmeldungen.">
            <x-slot:actions><x-ui.badge :tone="$attentionCount > 0 ? 'warning' : 'neutral'">{{ $attentionCount }} Hinweise</x-ui.badge></x-slot:actions>
            <div class="space-y-4 text-sm">
                @if ($activeProviders === 0)<a class="block text-slate-300 hover:text-cyan-200" href="{{ route('admin.page', 'providers') }}"><strong class="block text-slate-100">Provider verbinden</strong><span class="text-xs text-slate-500">Kein aktiver Provider-Zugang vorhanden.</span></a>@endif
                @if ($activeModels === 0)<a class="block text-slate-300 hover:text-cyan-200" href="{{ route('admin.page', 'models') }}"><strong class="block text-slate-100">Modell aktivieren</strong><span class="text-xs text-slate-500">Das Routing hat noch kein aktives Profil.</span></a>@endif
                @if ($onlineDevices === 0)<a class="block text-slate-300 hover:text-cyan-200" href="{{ route('admin.page', 'devices') }}"><strong class="block text-slate-100">Keine Geräte online</strong><span class="text-xs text-slate-500">Prüfe die Verbindung deiner registrierten Clients.</span></a>@endif
                @if ($openDeviceJobs > 0)<a class="block text-slate-300 hover:text-cyan-200" href="{{ route('admin.page', 'devices') }}"><strong class="block text-slate-100">{{ $openDeviceJobs }} offene Jobs</strong><span class="text-xs text-slate-500">Freigabe, Warteschlange oder Ausführung prüfen.</span></a>@endif
                @if ($runs30d === 0)<a class="block text-slate-300 hover:text-cyan-200" href="{{ route('admin.page', 'telemetry') }}"><strong class="block text-slate-100">Telemetrie wartet</strong><span class="text-xs text-slate-500">Noch kein Modelllauf in den letzten 30 Tagen.</span></a>@endif
                @if ($attentionCount === 0)<p class="text-slate-300">Keine offenen Hinweise aus den vorliegenden Meldungen.</p>@endif
            </div>
            <p class="mt-5 text-xs leading-relaxed text-slate-500">Stand {{ now()->format('d.m.Y · H:i') }} Uhr. Eine vorhandene Konfiguration bestätigt noch keinen erfolgreichen Modelllauf.</p>
        </x-ui.panel>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1.6fr_1fr]">
        <x-ui.panel title="Letzte Modellversuche" description="Antwortzeit und Kosten der jüngsten Provider-Aufrufe.">
            <div class="divide-y divide-white/5">
                @forelse ($recentAttempts->take(5) as $attempt)
                    @php($attemptTone = match ($attempt->status) { 'completed' => 'success', 'failed' => 'danger', default => 'info' })
                    <div class="flex flex-wrap items-center justify-between gap-3 py-4 first:pt-0 last:pb-0">
                        <div class="min-w-0"><p class="break-words text-sm font-medium text-slate-100">{{ $attempt->model_id }}</p><p class="mt-1 text-xs text-slate-500">Run #{{ $attempt->llm_run_id }} · {{ $attempt->total_ms ?? '—' }} ms · $ {{ number_format($attempt->effective_cost ?? 0, 6) }}</p></div>
                        <x-ui.badge :tone="$attemptTone" data-attempt-status="{{ $attempt->status }}">{{ $attempt->status }}</x-ui.badge>
                    </div>
                @empty
                    <x-ui.empty title="Noch keine Provider-Versuche">Neue Modellaufrufe erscheinen hier chronologisch.</x-ui.empty>
                @endforelse
            </div>
        </x-ui.panel>
        <x-ui.panel title="Systembereiche" description="Gezielt zur passenden Verwaltung springen.">
            <nav class="divide-y divide-white/5" aria-label="Systembereiche">
                @foreach ($modules as $module)
                    <a class="group flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0" href="{{ route('admin.page', $module['page']) }}" data-dashboard-action="open-{{ $module['page'] }}">
                        <div><span class="block text-sm font-medium text-slate-200 group-hover:text-cyan-200">{{ $module['label'] }}</span><span class="mt-1 block text-xs text-slate-500">{{ $module['meta'] }}</span></div>
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/5 text-slate-400" aria-hidden="true">→</span>
                    </a>
                @endforeach
            </nav>
        </x-ui.panel>
    </div>
</div>
