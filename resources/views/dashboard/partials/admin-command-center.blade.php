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
    $systemLabel = 'Betriebsbereit';

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

<div class="admin-command-center__overview" data-admin-command-center>
    <header class="admin-command-center__hero" data-dashboard-reveal>
        <div class="admin-command-center__hero-copy">
            <div class="admin-command-center__eyebrow">
                <span class="admin-command-center__signal" aria-hidden="true"></span>
                Luczor / Control Plane
            </div>
            <h1>Luczor Admin Control</h1>
            <p>Systemlage, Modellbetrieb und offene Aufgaben in einer klaren Ansicht. Tiefe Konfiguration bleibt einen Schritt entfernt.</p>

            <div class="admin-command-center__actions">
                <a class="admin-command-center__button admin-command-center__button--primary" href="{{ route('admin.page', 'overview') }}" data-dashboard-action="open-system-overview">
                    Systemübersicht öffnen
                    <span aria-hidden="true">→</span>
                </a>
                <a class="admin-command-center__button admin-command-center__button--secondary" href="{{ route('admin.page', 'settings') }}" data-dashboard-action="open-server-settings">
                    Server konfigurieren
                </a>
            </div>
        </div>

        <aside class="admin-command-center__posture" aria-labelledby="system-posture-title">
            <div class="admin-command-center__posture-heading">
                <span id="system-posture-title">Systemstatus</span>
                <span class="admin-command-center__status admin-command-center__status--{{ $systemTone }}">{{ $systemLabel }}</span>
            </div>
            <dl>
                <div>
                    <dt>Provider</dt>
                    <dd>{{ $activeProviders }} aktiv</dd>
                </div>
                <div>
                    <dt>Modelle</dt>
                    <dd>{{ $activeModels }} aktiv</dd>
                </div>
                <div>
                    <dt>Geräte</dt>
                    <dd>{{ $onlineDevices }} online</dd>
                </div>
            </dl>
            <p>Stand {{ now()->format('d.m.Y · H:i') }} Uhr</p>
        </aside>
    </header>

    <section class="admin-command-center__metrics" aria-label="Zentrale Betriebskennzahlen" data-dashboard-reveal>
        @foreach ($metrics as $metric)
            <article class="admin-command-center__metric" data-dashboard-metric="{{ $metric['key'] }}">
                <span>{{ $metric['label'] }}</span>
                <strong>{{ $metric['value'] }}</strong>
                <small>{{ $metric['detail'] }}</small>
            </article>
        @endforeach
    </section>

    <div class="admin-command-center__primary-grid">
        <section class="admin-command-center__surface admin-command-center__chart" aria-labelledby="dashboard-chart-title" data-dashboard-reveal>
            <div class="admin-command-center__section-heading">
                <div>
                    <span class="admin-command-center__section-kicker">Betrieb · 14 Tage</span>
                    <h2 id="dashboard-chart-title">Läufe und Kosten</h2>
                </div>
                <div class="admin-command-center__chart-total">
                    <strong>{{ number_format($charts['total_runs'] ?? 0) }}</strong>
                    <span>Läufe · $ {{ number_format($charts['total_cost'] ?? 0, 4) }}</span>
                </div>
            </div>

            <div class="admin-command-center__chart-canvas">
                @if (($charts['total_runs'] ?? 0) > 0)
                    <svg viewBox="0 0 560 140" preserveAspectRatio="none" role="img" aria-labelledby="dashboard-chart-title dashboard-chart-description">
                        <desc id="dashboard-chart-description">LLM-Läufe pro Tag als Balken und Kosten als Linie für die letzten 14 Tage.</desc>
                        <line x1="0" y1="130" x2="560" y2="130" class="admin-command-center__chart-axis" />
                        @foreach (($charts['bars'] ?? []) as $bar)
                            <rect x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['w'] }}" height="{{ $bar['h'] }}" rx="2" class="admin-command-center__chart-bar">
                                <title>{{ $bar['runs'] }} Läufe am Tag {{ $bar['day'] }}</title>
                            </rect>
                            <text x="{{ $bar['x'] + $bar['w'] / 2 }}" y="139" text-anchor="middle">{{ $bar['day'] }}</text>
                        @endforeach
                        @if (! empty($charts['cost_points']))
                            <polyline points="{{ $charts['cost_points'] }}" class="admin-command-center__chart-cost" />
                        @endif
                    </svg>
                @endif

                @if (($charts['total_runs'] ?? 0) === 0)
                    <div class="admin-command-center__chart-empty">
                        <span>Noch keine Läufe</span>
                        <small>Der Verlauf füllt sich mit dem ersten Provider-Aufruf.</small>
                    </div>
                @endif
            </div>

            <div class="admin-command-center__legend" aria-hidden="true">
                <span><i class="admin-command-center__legend-bar"></i>Läufe</span>
                <span><i class="admin-command-center__legend-line"></i>Kosten</span>
            </div>
        </section>

        <aside class="admin-command-center__surface admin-command-center__attention" aria-labelledby="dashboard-attention-title" data-dashboard-reveal>
            <div class="admin-command-center__section-heading">
                <div>
                    <span class="admin-command-center__section-kicker">Prioritäten</span>
                    <h2 id="dashboard-attention-title">{{ $attentionCount === 1 ? '1 Hinweis' : ($attentionCount > 1 ? $attentionCount.' Hinweise' : 'Alles im Blick') }}</h2>
                </div>
            </div>

            <div class="admin-command-center__attention-list">
                @if ($activeProviders === 0)
                    <a href="{{ route('admin.page', 'providers') }}">
                        <span class="is-warning" aria-hidden="true"></span>
                        <span><strong>Provider verbinden</strong><small>Kein aktives Credential vorhanden.</small></span>
                    </a>
                @endif
                @if ($activeModels === 0)
                    <a href="{{ route('admin.page', 'models') }}">
                        <span class="is-warning" aria-hidden="true"></span>
                        <span><strong>Modell aktivieren</strong><small>Routing hat noch kein aktives Profil.</small></span>
                    </a>
                @endif
                @if ($onlineDevices === 0)
                    <a href="{{ route('admin.page', 'devices') }}">
                        <span class="is-neutral" aria-hidden="true"></span>
                        <span><strong>Keine Geräte online</strong><small>Registrierte Clients sind aktuell nicht verbunden.</small></span>
                    </a>
                @endif
                @if ($openDeviceJobs > 0)
                    <a href="{{ route('admin.page', 'devices') }}">
                        <span class="is-warning" aria-hidden="true"></span>
                        <span><strong>{{ $openDeviceJobs }} offene Jobs</strong><small>Freigabe, Warteschlange oder Ausführung prüfen.</small></span>
                    </a>
                @endif
                @if ($runs30d === 0)
                    <a href="{{ route('admin.page', 'telemetry') }}">
                        <span class="is-neutral" aria-hidden="true"></span>
                        <span><strong>Telemetrie wartet</strong><small>Noch kein Modelllauf in den letzten 30 Tagen.</small></span>
                    </a>
                @endif
                @if ($attentionCount === 0)
                    <div class="admin-command-center__attention-clear">
                        <span class="is-ready" aria-hidden="true"></span>
                        <span><strong>Keine offenen Hinweise</strong><small>Provider, Modelle und Geräte melden einen betriebsbereiten Zustand.</small></span>
                    </div>
                @endif
            </div>
        </aside>
    </div>

    <div class="admin-command-center__secondary-grid">
        <section class="admin-command-center__surface admin-command-center__activity" aria-labelledby="dashboard-activity-title" data-dashboard-reveal>
            <div class="admin-command-center__section-heading">
                <div>
                    <span class="admin-command-center__section-kicker">Provider-Aktivität</span>
                    <h2 id="dashboard-activity-title">Letzte Modellversuche</h2>
                </div>
                <a href="{{ route('admin.page', 'telemetry') }}">Alle Messwerte <span aria-hidden="true">→</span></a>
            </div>

            <div class="admin-command-center__activity-list">
                @forelse ($recentAttempts->take(5) as $attempt)
                    @php
                        $attemptTone = match ($attempt->status) {
                            'completed' => 'complete',
                            'failed' => 'failed',
                            default => 'pending',
                        };
                    @endphp
                    <article>
                        <div class="admin-command-center__activity-index">{{ str_pad((string) $attempt->attempt_no, 2, '0', STR_PAD_LEFT) }}</div>
                        <div class="admin-command-center__activity-copy">
                            <strong>{{ $attempt->model_id }}</strong>
                            <span>Run #{{ $attempt->llm_run_id }} · {{ $attempt->total_ms ?? '—' }} ms · $ {{ number_format($attempt->effective_cost ?? 0, 6) }}</span>
                        </div>
                        <span class="admin-command-center__activity-status is-{{ $attemptTone }}">{{ $attempt->status }}</span>
                    </article>
                @empty
                    <div class="admin-command-center__empty-state">
                        <span class="admin-command-center__empty-mark" aria-hidden="true">∅</span>
                        <div><strong>Noch keine Provider-Versuche</strong><small>Neue Modellaufrufe erscheinen hier chronologisch.</small></div>
                    </div>
                @endforelse
            </div>
        </section>

        <nav class="admin-command-center__surface admin-command-center__modules" aria-labelledby="dashboard-modules-title" data-dashboard-reveal>
            <div class="admin-command-center__section-heading">
                <div>
                    <span class="admin-command-center__section-kicker">Verwaltung</span>
                    <h2 id="dashboard-modules-title">Systembereiche</h2>
                </div>
            </div>

            <div class="admin-command-center__module-list">
                @foreach ($modules as $module)
                    <a href="{{ route('admin.page', $module['page']) }}" data-dashboard-action="open-{{ $module['page'] }}">
                        <span class="admin-command-center__module-icon">
                            @include('layouts.partials.icon', ['name' => $module['icon'], 'class' => ''])
                        </span>
                        <span class="admin-command-center__module-copy">
                            <strong>{{ $module['label'] }}</strong>
                            <small>{{ $module['description'] }}</small>
                        </span>
                        <span class="admin-command-center__module-meta">{{ $module['meta'] }}</span>
                        <span class="admin-command-center__module-arrow" aria-hidden="true">→</span>
                    </a>
                @endforeach
            </div>
        </nav>
    </div>
</div>
