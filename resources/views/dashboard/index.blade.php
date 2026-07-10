<x-app-layout>
    <div class="mb-8">
        <h1 class="text-2xl font-semibold text-white">{{ $isAdmin ? 'Luczor Admin Control' : 'Mein Luczor' }}</h1>
        <p class="mt-2 text-sm text-slate-400">{{ $isAdmin ? 'Provider, Routing, Telemetrie, Policies und Systembetrieb.' : 'Geräte, Projekte, Memory und sichere Cloud-Verbindung.' }}</p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md border border-emerald-400/30 bg-emerald-400/10 p-3 text-sm text-emerald-100">{{ session('status') }}</div>
    @endif

    @if (session('plain_api_key'))
        <div class="mb-6 rounded-md border border-cyan-400/30 bg-cyan-400/10 p-4">
            <div class="text-sm font-semibold text-cyan-100">Neuer API Key, nur jetzt sichtbar:</div>
            <code class="mt-2 block break-all rounded bg-slate-950 p-3 text-sm text-cyan-200">{{ session('plain_api_key') }}</code>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-6 rounded-md border border-rose-400/30 bg-rose-400/10 p-4 text-sm text-rose-100">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! $isAdmin)
        <section class="grid gap-6 xl:grid-cols-[1.35fr_0.85fr]">
            <div class="luczor-card overflow-hidden border-cyan-400/30">
                <div class="border-b border-cyan-400/10 bg-cyan-400/5 px-5 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h1 class="font-mono text-xl font-semibold text-cyan-100">luczor terminal</h1>
                            <p class="mt-1 text-sm text-slate-400">Cloud-gesteuerter Zugriff auf deine verbundenen Geraete, Projekte und Repositories.</p>
                        </div>
                        <span class="rounded-full border border-emerald-400/30 bg-emerald-400/10 px-3 py-1 text-xs font-semibold text-emerald-200">USER MODE</span>
                    </div>
                </div>
                <div class="space-y-4 p-5 font-mono text-sm">
                    <div class="text-slate-500">$ luczor status --scope=user</div>
                    <div class="grid gap-3 md:grid-cols-4">
                        @foreach ($archiveCounts as $label => $count)
                            <div class="rounded-md border border-cyan-400/10 bg-slate-950/70 p-3">
                                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-500">{{ str_replace('_', ' ', $label) }}</div>
                                <div class="mt-2 text-2xl font-semibold text-cyan-100">{{ $count }}</div>
                            </div>
                        @endforeach
                    </div>
                    <div class="rounded-md border border-cyan-400/10 bg-slate-950/80 p-4">
                        <div class="text-cyan-300">&gt; online clients</div>
                        <div class="mt-2 text-slate-300">
                            {{ $clientIds->count() ? $clientIds->implode(', ') : 'Noch kein Device verbunden. Erzeuge unten ein Geraete-Token und verbinde die Tauri-App.' }}
                        </div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-3">
                        <div class="rounded-md border border-cyan-400/10 bg-slate-950/70 p-3">
                            <div class="text-slate-500">control</div>
                            <div class="mt-1 text-cyan-100">cloud queue bereit</div>
                        </div>
                        <div class="rounded-md border border-cyan-400/10 bg-slate-950/70 p-3">
                            <div class="text-slate-500">mode</div>
                            <div class="mt-1 text-cyan-100">agent assisted</div>
                        </div>
                        <div class="rounded-md border border-cyan-400/10 bg-slate-950/70 p-3">
                            <div class="text-slate-500">provider</div>
                            <div class="mt-1 text-cyan-100">server routed</div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="devices" class="luczor-card p-5">
                <h2 class="text-lg font-semibold text-white">Meine Geraete</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($apiKeys as $key)
                        <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-slate-100">{{ $key->device_name ?: $key->name }}</div>
                                    <div class="text-slate-500">{{ $key->device_id ?: 'keine Device ID' }}</div>
                                </div>
                                <span class="rounded-full px-2 py-1 text-xs {{ $key->active ? 'bg-emerald-400/10 text-emerald-200' : 'bg-rose-400/10 text-rose-200' }}">{{ $key->active ? 'aktiv' : 'inaktiv' }}</span>
                            </div>
                            <div class="mt-2 text-xs text-slate-500">Abilities: {{ implode(', ', $key->abilities ?? []) }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Noch keine Geraete verbunden.</p>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="mt-8 grid gap-6 lg:grid-cols-2">
            <div id="projects" class="luczor-card p-5">
                <h2 class="text-lg font-semibold text-white">Meine Projekte</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($userProjects as $project)
                        <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-cyan-100">{{ $project->name }}</div>
                                    <div class="font-mono text-xs text-slate-500">{{ $project->external_id }}</div>
                                </div>
                                <span class="text-xs text-slate-500">{{ optional($project->updated_at)->diffForHumans() }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Noch keine Projekte synchronisiert.</p>
                    @endforelse
                </div>
            </div>

            <div id="github" class="luczor-card p-5">
                <h2 class="text-lg font-semibold text-white">Cloud / GitHub</h2>
                <p class="mt-1 text-sm text-slate-400">Hier wird der Codex-aehnliche Einstieg fuer Online-Geraete und Repository-basierte Projekte gebuendelt.</p>
                <div class="mt-4 rounded-md border border-cyan-400/10 bg-slate-950/70 p-4 font-mono text-sm">
                    <div class="text-slate-500">$ luczor attach github --repo owner/repo</div>
                    <div class="mt-2 text-cyan-100">Repository-Verknuepfung ist als naechster Cloud-Workflow vorbereitet.</div>
                </div>
                <div class="mt-4">
                    <h3 class="text-sm font-semibold text-slate-200">Letzte Agent-Events</h3>
                    <div class="mt-3 space-y-2">
                        @forelse ($userEvents as $event)
                            <div class="rounded border border-slate-800 bg-slate-950/50 px-3 py-2 text-xs">
                                <span class="font-mono text-cyan-200">{{ $event->event_type }}</span>
                                <span class="ml-2 text-slate-500">{{ optional($event->created_at)->diffForHumans() }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">Noch keine Agent-Events.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        <section id="connect" class="mt-8 luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Geraete-Verbindung erstellen</h2>
            <p class="mt-1 text-sm text-slate-400">Dieses Token verbindet deine Tauri-App mit deinem User-Bereich. Provider-API-Keys bleiben auf dem Server.</p>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.api-keys.store') }}">
                @csrf
                <input class="luczor-input" name="name" placeholder="Name, z.B. Mein Desktop" required>
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="device_id" placeholder="Device ID optional">
                    <input class="luczor-input" name="device_name" placeholder="Device Name optional">
                </div>
                <input class="luczor-input" name="expires_at" type="datetime-local">
                <div class="rounded border border-cyan-400/10 bg-cyan-400/5 p-3 text-sm text-slate-400">Die sicheren Geräteberechtigungen werden automatisch vom Server vergeben. Provider- und Modellrechte sind ausgeschlossen.</div>
                <button class="luczor-btn" type="submit">Verbindungstoken erzeugen</button>
            </form>
        </section>
    @else

    <section id="overview" class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
        @foreach ($operations as $label => $count)
            <div class="luczor-card p-4">
                <div class="text-xs uppercase tracking-wider text-slate-500">{{ str_replace('_', ' ', $label) }}</div>
                <div class="mt-2 text-2xl font-semibold text-cyan-100">{{ $count }}</div>
            </div>
        @endforeach
    </section>

    <section id="devices" class="mt-8 luczor-card p-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div><h2 class="text-xl font-semibold text-white">Geräte-Debug</h2><p class="mt-1 text-sm text-slate-400">Nur Admins können eine stille Diagnose vom Gerät anfordern. Nutzer sehen keinen Dialog und keine Meldung.</p></div>
            <span class="rounded-full border border-amber-400/20 bg-amber-400/5 px-3 py-1 text-xs text-amber-200">Serverseitig verschlüsselt</span>
        </div>
        <div class="mt-4 grid gap-3 lg:grid-cols-2">
            @forelse($devices as $device)
                <div class="rounded border border-slate-800 bg-slate-950/50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div><b class="text-cyan-100">{{ $device->name ?: $device->device_id }}</b><div class="font-mono text-xs text-slate-500">{{ $device->device_id }} · {{ $device->user?->email }}</div></div>
                        <span class="text-xs {{ $device->status === 'online' ? 'text-emerald-300' : 'text-slate-500' }}">{{ $device->status }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('dashboard.devices.debug.request', $device) }}">@csrf<button class="luczor-btn-secondary">Debug anfordern</button></form>
                        <span class="text-xs text-slate-500">zuletzt {{ optional($device->last_seen_at)->diffForHumans() ?: 'unbekannt' }}</span>
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-500">Noch keine registrierten Geräte.</p>
            @endforelse
        </div>
        <div class="mt-5 overflow-x-auto"><table class="min-w-full text-left text-xs"><thead class="text-slate-500"><tr><th class="py-2">Zeit</th><th>Gerät</th><th>Status</th><th></th></tr></thead><tbody class="divide-y divide-slate-900">
            @forelse($debugRequests as $debug)
                <tr><td class="py-2">{{ optional($debug->requested_at)->format('d.m.Y H:i') }}</td><td class="font-mono text-cyan-200">{{ $debug->device?->device_id }}</td><td class="{{ $debug->status === 'completed' ? 'text-emerald-300' : ($debug->status === 'failed' ? 'text-rose-300' : 'text-amber-300') }}">{{ $debug->status }}</td><td>@if($debug->status === 'completed')<a class="text-cyan-200 hover:text-white" href="{{ route('dashboard.devices.debug.download', $debug) }}">Download</a>@endif</td></tr>
            @empty
                <tr><td colspan="4" class="py-4 text-slate-500">Noch keine Debug-Anforderungen.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>

    <section id="telemetry" class="mt-8 space-y-6">
        <div class="flex items-end justify-between gap-4">
            <div><h2 class="text-xl font-semibold text-white">Provider- und Modell-Telemetrie</h2><p class="mt-1 text-sm text-slate-400">30 Tage · Kosten, Nutzen, Geschwindigkeit, Fallbacks und Ergebnisqualität.</p></div>
            <div class="flex flex-wrap gap-2"><a class="luczor-btn-secondary" href="{{ route('dashboard.telemetry.export', ['format' => 'jsonl', 'days' => 30]) }}">JSONL Export</a><a class="luczor-btn-secondary" href="{{ route('dashboard.telemetry.export', ['format' => 'csv', 'days' => 30]) }}">CSV Export</a><span class="rounded-full border border-cyan-400/20 bg-cyan-400/5 px-3 py-1 text-xs text-cyan-200">Admin only</span></div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([
                'Runs' => number_format($telemetry['runs_30d'] ?? 0),
                'Erfolg' => number_format($telemetry['success_rate'] ?? 0, 1).' %',
                'Kosten' => '$ '.number_format($telemetry['cost_30d'] ?? 0, 6),
                'Kosten / Erfolg' => '$ '.number_format($telemetry['cost_per_success'] ?? 0, 6),
                'Fallback-Rate' => number_format($telemetry['fallback_rate'] ?? 0, 1).' %',
                'Ø Latenz' => number_format($telemetry['avg_latency_ms'] ?? 0).' ms',
                'Ø TTFT' => number_format($telemetry['avg_ttft_ms'] ?? 0).' ms',
                'Ø Tokens/s' => number_format($telemetry['avg_tokens_per_second'] ?? 0, 2),
                'Input Tokens' => number_format($telemetry['input_tokens'] ?? 0),
                'Output Tokens' => number_format($telemetry['output_tokens'] ?? 0),
            ] as $label => $value)
                <div class="luczor-card p-4"><div class="text-[10px] uppercase tracking-[.18em] text-slate-500">{{ $label }}</div><div class="mt-2 font-mono text-xl text-cyan-100">{{ $value }}</div></div>
            @endforeach
        </div>
        <div class="luczor-card overflow-x-auto p-5">
            <h3 class="font-semibold text-white">Leistung je Modell und Aufgabentyp</h3>
            <table class="mt-4 min-w-full text-left text-xs">
                <thead class="border-b border-slate-800 text-slate-500"><tr><th class="py-2">Modell / Task</th><th>Runs</th><th>Erfolg</th><th>Qualität</th><th>Latenz</th><th>TTFT</th><th>Tok/s</th><th>Kosten gesamt</th><th>Ø Kosten</th></tr></thead>
                <tbody class="divide-y divide-slate-900">@forelse($modelTelemetry as $row)<tr>
                    <td class="py-3"><div class="font-mono text-cyan-200">{{ $row->model_id }}</div><div class="text-slate-500">{{ $row->provider_id }} · {{ $row->task_type }}</div></td>
                    <td>{{ $row->runs }}</td><td>{{ number_format($row->success_rate * 100, 1) }} %</td><td>{{ $row->avg_quality === null ? '—' : number_format($row->avg_quality, 3) }}</td>
                    <td>{{ number_format($row->avg_latency_ms) }} ms</td><td>{{ number_format($row->avg_ttft_ms) }} ms</td><td>{{ number_format($row->avg_tps, 2) }}</td>
                    <td>$ {{ number_format($row->total_cost, 6) }}</td><td>$ {{ number_format($row->avg_cost, 6) }}</td>
                </tr>@empty<tr><td colspan="9" class="py-6 text-center text-slate-500">Noch keine LLM-Läufe. Daten entstehen automatisch über den Provider-Proxy.</td></tr>@endforelse</tbody>
            </table>
        </div>
        <div class="grid gap-6 xl:grid-cols-[1fr_1.4fr]">
            <div class="luczor-card p-5"><h3 class="font-semibold text-white">Aktuelle Rankings</h3><div class="mt-4 space-y-2">@forelse($modelRankings as $ranking)<div class="rounded border border-slate-800 bg-slate-950/60 p-3 text-xs"><div class="flex justify-between"><span class="font-mono text-cyan-200">{{ $ranking->task_type }}</span><b>{{ number_format($ranking->score, 4) }}</b></div><div class="mt-1 text-slate-400">{{ $ranking->model_id }} · {{ $ranking->sample_count }} Samples · ${{ number_format($ranking->avg_cost_total, 6) }}</div></div>@empty<p class="text-sm text-slate-500">Rankings werden ab fünf Messwerten je Modell aktiv.</p>@endforelse</div></div>
            <div class="luczor-card overflow-x-auto p-5"><h3 class="font-semibold text-white">Letzte Provider-Versuche</h3><table class="mt-4 min-w-full text-left text-xs"><thead class="text-slate-500"><tr><th>Run</th><th>Versuch</th><th>Modell</th><th>Status</th><th>TTFT / Gesamt</th><th>Tokens</th><th>Kosten</th></tr></thead><tbody class="divide-y divide-slate-900">@foreach($recentAttempts as $attempt)<tr><td class="py-2 font-mono">#{{ $attempt->llm_run_id }}</td><td>{{ $attempt->attempt_no }}</td><td class="max-w-52 truncate text-cyan-200">{{ $attempt->model_id }}</td><td class="{{ $attempt->status === 'completed' ? 'text-emerald-300' : 'text-rose-300' }}">{{ $attempt->status }}</td><td>{{ $attempt->ttft_ms ?? '—' }} / {{ $attempt->total_ms ?? '—' }} ms</td><td>{{ $attempt->input_tokens ?? 0 }} → {{ $attempt->output_tokens ?? 0 }}</td><td>${{ number_format($attempt->effective_cost ?? 0, 8) }}</td></tr>@endforeach</tbody></table></div>
        </div>
    </section>

    <section id="archives" class="grid gap-4 md:grid-cols-5">
        @foreach ($archiveCounts as $label => $count)
            <div class="luczor-card p-4">
                <div class="text-xs uppercase tracking-wider text-slate-500">{{ str_replace('_', ' ', $label) }}</div>
                <div class="mt-2 text-3xl font-semibold text-cyan-100">{{ $count }}</div>
            </div>
        @endforeach
    </section>

    <section id="settings" class="mt-8">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Client-Einstellungen (Server-Defaults)</h2>
            <p class="mt-1 text-sm text-slate-400">Werden an die Desktop-App ausgeliefert (<code class="text-cyan-200">/api/v1/runtime-settings</code>).</p>
            <form class="mt-4" method="POST" action="{{ route('dashboard.settings.store') }}">
                @csrf
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($settings as $setting)
                        <div class="rounded border border-slate-800 bg-slate-950/50 px-3 py-2">
                            <div class="text-sm text-slate-200">{{ $setting->label ?? $setting->key }} <span class="text-xs text-slate-500">({{ $setting->key }})</span></div>
                            <div class="mt-2">
                                @if ($setting->type === 'bool')
                                    <label class="flex items-center gap-2 text-sm text-slate-300">
                                        <input type="checkbox" class="rounded border-slate-700 bg-slate-950 text-cyan-400" name="settings[{{ $setting->key }}]" value="1" @checked($setting->value['v'] ?? false)>
                                        aktiviert
                                    </label>
                                @elseif ($setting->type === 'number')
                                    <input type="number" step="any" class="luczor-input" name="settings[{{ $setting->key }}]" value="{{ $setting->value['v'] ?? 0 }}">
                                @else
                                    <input type="text" class="luczor-input" name="settings[{{ $setting->key }}]" value="{{ $setting->value['v'] ?? '' }}">
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <button class="luczor-btn mt-4" type="submit">Einstellungen speichern</button>
            </form>
        </div>
    </section>

    <section class="mt-8 grid gap-6 lg:grid-cols-2">
        <div id="api-keys" class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Device API Key erstellen</h2>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.api-keys.store') }}">
                @csrf
                <input class="luczor-input" name="name" placeholder="Name, z.B. Desktop LZ" required>
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="device_id" placeholder="Device ID optional">
                    <input class="luczor-input" name="device_name" placeholder="Device Name optional">
                </div>
                <input class="luczor-input" name="expires_at" type="datetime-local">
                <div class="grid gap-2 md:grid-cols-2">
                    @foreach ($abilities as $ability)
                        <label class="flex items-center gap-2 rounded border border-slate-800 bg-slate-950/50 px-3 py-2 text-sm text-slate-200">
                            <input class="rounded border-slate-700 bg-slate-950 text-cyan-400" type="checkbox" name="abilities[]" value="{{ $ability }}" @checked($ability === 'all')>
                            {{ $ability }}
                        </label>
                    @endforeach
                </div>
                <button class="luczor-btn" type="submit">Key erzeugen</button>
            </form>
        </div>

        <div id="providers" class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Provider Credential</h2>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.provider-credentials.store') }}">
                @csrf
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="provider" placeholder="openrouter, elevenlabs, ..." required>
                    <input class="luczor-input" name="label" placeholder="Label" required>
                </div>
                <input class="luczor-input" name="api_key" placeholder="API Key wird verschluesselt gespeichert" required>
                <input class="luczor-input" name="base_url" placeholder="Base URL optional">
                <button class="luczor-btn" type="submit">Credential speichern</button>
            </form>
        </div>
    </section>

    <section class="mt-8 grid gap-6 xl:grid-cols-2">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Provider-Preissnapshot</h2>
            <p class="mt-1 text-sm text-slate-400">Fallback, falls der Provider keine Kosten meldet. Historische Läufe behalten ihren Snapshot.</p>
            <form class="mt-4 grid gap-3 md:grid-cols-2" method="POST" action="{{ route('dashboard.provider-prices.store') }}">@csrf
                <input class="luczor-input" name="provider_id" value="openrouter" required><input class="luczor-input" name="model_id" placeholder="provider/model-id" required>
                <input class="luczor-input" name="input_per_million" type="number" min="0" step="0.00000001" placeholder="Input $ / 1M" required><input class="luczor-input" name="output_per_million" type="number" min="0" step="0.00000001" placeholder="Output $ / 1M" required>
                <input class="luczor-input" name="cache_read_per_million" type="number" min="0" step="0.00000001" placeholder="Cache read $ / 1M"><input class="luczor-input" name="cache_write_per_million" type="number" min="0" step="0.00000001" placeholder="Cache write $ / 1M">
                <input type="hidden" name="currency" value="USD"><input class="luczor-input" name="valid_from" type="datetime-local" value="{{ now()->format('Y-m-d\TH:i') }}" required>
                <button class="luczor-btn" type="submit">Preisversion speichern</button>
            </form>
            <div class="mt-4 space-y-2">@foreach($providerPrices->take(10) as $price)<div class="rounded border border-slate-800 p-2 text-xs"><span class="font-mono text-cyan-200">{{ $price->model_id }}</span><span class="ml-2 text-slate-500">in ${{ $price->input_per_million }} · out ${{ $price->output_per_million }} / 1M · ab {{ $price->valid_from }}</span></div>@endforeach</div>
        </div>
        <div class="luczor-card p-5"><h2 class="text-lg font-semibold text-white">Provider-Status</h2><div class="mt-4 space-y-3">@forelse($providers as $provider)<div class="flex items-center justify-between rounded border border-slate-800 bg-slate-950/50 p-3 text-sm"><div><b>{{ $provider->label }}</b><div class="text-slate-500">{{ $provider->provider }} · {{ $provider->maskedKey() }}</div></div><form method="POST" action="{{ route('dashboard.provider-credentials.toggle', $provider) }}">@csrf<button class="luczor-btn-secondary">{{ $provider->active ? 'Deaktivieren' : 'Aktivieren' }}</button></form></div>@empty<p class="text-slate-500">Keine Provider.</p>@endforelse</div></div>
    </section>

    <section id="models" class="mt-8 grid gap-6 lg:grid-cols-2">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Einzelnes Modellprofil</h2>
            <p class="mt-1 text-sm text-slate-400">Diese Profile werden pro Use-Case in Fallback-Reihenfolge zusammengestellt.</p>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.model-profiles.store') }}">
                @csrf
                <input class="luczor-input" name="name" placeholder="Name, z.B. Planner Fast" required>
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="provider" placeholder="Provider" required>
                    <input class="luczor-input" name="model_id" placeholder="Model ID" required>
                </div>
                <div class="grid gap-3 md:grid-cols-3">
                    <input class="luczor-input" name="temperature" type="number" min="0" max="2" step="0.01" value="0.20" required>
                    <input class="luczor-input" name="max_tokens" type="number" min="1" value="1200" required>
                    <input class="luczor-input" name="purpose" placeholder="Zweck">
                </div>
                <button class="luczor-btn" type="submit">Modellprofil speichern</button>
            </form>
        </div>

        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Use-Case anlegen</h2>
            <p class="mt-1 text-sm text-slate-400">Jeder Fall bekommt danach eine eigene Modellkette.</p>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.model-use-cases.store') }}">
                @csrf
                <input class="luczor-input" name="name" placeholder="z.B. Browser Agent, Vision, TTS" required>
                <textarea class="luczor-input" name="description" rows="3" placeholder="Beschreibung optional"></textarea>
                <button class="luczor-btn" type="submit">Use-Case speichern</button>
            </form>
        </div>
    </section>

    <section class="mt-8 luczor-card p-5">
        <h2 class="text-lg font-semibold text-white">Modell-Fallbacks pro Use-Case</h2>
        <p class="mt-1 text-sm text-slate-400">Niedrige Sortierung wird zuerst genutzt. Faellt ein Modell aus, folgt das naechste aktive Profil.</p>

        <form class="mt-4 grid gap-3 md:grid-cols-4" method="POST" action="{{ route('dashboard.model-use-case-entries.store') }}">
            @csrf
            <select class="luczor-input" name="model_use_case_id" required>
                <option value="">Use-Case</option>
                @foreach ($modelUseCases as $useCase)
                    <option value="{{ $useCase->id }}">{{ $useCase->slug }}</option>
                @endforeach
            </select>
            <select class="luczor-input" name="model_profile_id" required>
                <option value="">Modellprofil</option>
                @foreach ($modelProfiles as $profile)
                    <option value="{{ $profile->id }}">{{ $profile->name }} / {{ $profile->model_id }}</option>
                @endforeach
            </select>
            <input class="luczor-input" name="sort_order" type="number" min="1" value="1" required>
            <button class="luczor-btn mt-1" type="submit">Fallback setzen</button>
        </form>

        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            @foreach ($modelUseCases as $useCase)
                <div class="rounded-lg border border-slate-800 bg-slate-950/50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-cyan-100">{{ $useCase->name }}</h3>
                            <p class="text-xs text-slate-500">{{ $useCase->slug }}</p>
                        </div>
                        <span class="rounded-full bg-cyan-400/10 px-2 py-1 text-xs text-cyan-200">{{ $useCase->entries->count() }} Modelle</span>
                    </div>
                    <ol class="mt-4 space-y-2">
                        @forelse ($useCase->entries->sortBy('sort_order') as $entry)
                            <li class="flex items-center justify-between rounded border border-slate-800 px-3 py-2 text-sm">
                                <span>
                                    <span class="font-mono text-cyan-200">#{{ $entry->sort_order }}</span>
                                    {{ $entry->modelProfile?->name }}
                                    <span class="text-slate-500">({{ $entry->modelProfile?->model_id }})</span>
                                </span>
                                <span class="text-xs {{ $entry->active ? 'text-emerald-300' : 'text-slate-500' }}">{{ $entry->active ? 'aktiv' : 'aus' }}</span>
                            </li>
                        @empty
                            <li class="text-sm text-slate-500">Noch keine Fallbacks gesetzt.</li>
                        @endforelse
                    </ol>
                </div>
            @endforeach
        </div>
    </section>

    <section id="optimizer" class="mt-8 grid gap-6 xl:grid-cols-3">
        <div class="luczor-card p-5"><h2 class="font-semibold text-white">Prompt-Version</h2><form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.prompt-templates.store') }}">@csrf<input class="luczor-input" name="key" placeholder="luczor.coding" required><input class="luczor-input" name="task_type" placeholder="coding.fix_bug"><textarea class="luczor-input" name="body" rows="6" placeholder="System-/Prompt-Template" required></textarea><button class="luczor-btn">Neue Version</button></form><div class="mt-4 text-xs text-slate-500">{{ $promptTemplates->count() }} Versionen gespeichert</div></div>
        <div class="luczor-card p-5"><h2 class="font-semibold text-white">Kontextstrategie</h2><form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.context-strategies.store') }}">@csrf<input class="luczor-input" name="key" value="context.memory_code_budgeted" required><input class="luczor-input" name="name" value="Memory + Code budgetiert" required><textarea class="luczor-input font-mono text-xs" name="config" rows="6" required>{"git_tokens":250,"graph_tokens":1000,"memory_tokens":600,"raw_file_tokens":3500,"deduplicate":true}</textarea><button class="luczor-btn">Strategie speichern</button></form></div>
        <div class="luczor-card p-5"><h2 class="font-semibold text-white">Netzwerk-/Kostenpolicy</h2><form class="mt-4 grid gap-3 md:grid-cols-2" method="POST" action="{{ route('dashboard.network-policies.store') }}">@csrf<input class="luczor-input md:col-span-2" name="key" value="proxy.openrouter.default" required><input class="luczor-input md:col-span-2" name="name" value="OpenRouter Default" required><input class="luczor-input" name="connect_timeout_ms" type="number" value="10000" required><input class="luczor-input" name="request_timeout_ms" type="number" value="90000" required><input class="luczor-input" name="max_attempts" type="number" value="3" required><input class="luczor-input" name="backoff_ms" type="number" value="250" required><input class="luczor-input" name="max_cost_usd" type="number" step="0.000001" placeholder="Max $ / Run"><input class="luczor-input" name="max_input_tokens" type="number" value="24000" placeholder="Max Input"><input class="luczor-input" name="max_output_tokens" type="number" value="8192"><button class="luczor-btn">Policy speichern</button></form></div>
    </section>

    <section id="experiments" class="mt-8 grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
        <div class="luczor-card p-5">
            <h2 class="font-semibold text-white">A/B-Modellversuch</h2>
            <p class="mt-1 text-xs text-slate-500">Varianten dürfen ausschließlich bereits administrierte Modellprofile referenzieren. Das Routing bleibt serverseitig.</p>
            <form class="mt-4 grid gap-3 md:grid-cols-2" method="POST" action="{{ route('dashboard.llm-experiments.store') }}">@csrf
                <input class="luczor-input" name="key" placeholder="coding-fast-v1" required>
                <input class="luczor-input" name="name" placeholder="Coding: Qualität gegen Kosten" required>
                <input class="luczor-input" name="task_type" value="coding" required>
                <select class="luczor-input" name="status"><option value="draft">Entwurf</option><option value="active">Aktiv</option><option value="paused">Pausiert</option><option value="completed">Beendet</option></select>
                <input class="luczor-input" name="traffic_percent" type="number" min="0" max="100" value="10" required>
                <textarea class="luczor-input font-mono text-xs md:col-span-2" name="variants" rows="4" required>[{"model_profile_slug":"luczor-default","weight":100}]</textarea>
                <textarea class="luczor-input font-mono text-xs md:col-span-2" name="success_criteria" rows="3">{"quality_min":0.8,"cost_max_usd":0.05,"latency_max_ms":15000}</textarea>
                <button class="luczor-btn">Experiment speichern</button>
            </form>
        </div>
        <div class="luczor-card p-5">
            <h2 class="font-semibold text-white">Experimente</h2>
            <div class="mt-4 space-y-3">
                @forelse($llmExperiments as $experiment)
                    <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                        <div class="flex justify-between"><b class="text-cyan-100">{{ $experiment->name }}</b><span class="text-xs text-slate-400">{{ $experiment->status }}</span></div>
                        <div class="mt-1 font-mono text-xs text-slate-500">{{ $experiment->task_type }} · {{ $experiment->traffic_percent }}% Traffic</div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Noch keine Experimente angelegt.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section class="mt-8 luczor-card p-5"><h2 class="font-semibold text-white">Aktive Modellprofile (nur Admin)</h2><div class="mt-4 grid gap-3 lg:grid-cols-2">@foreach($modelProfiles as $profile)<div class="flex items-center justify-between rounded border border-slate-800 bg-slate-950/50 p-3 text-sm"><div><b class="text-cyan-100">{{ $profile->name }}</b><div class="font-mono text-xs text-slate-500">{{ $profile->model_id }} · {{ $profile->purpose }}</div></div><form method="POST" action="{{ route('dashboard.model-profiles.toggle', $profile) }}">@csrf<button class="luczor-btn-secondary">{{ $profile->active ? 'Deaktivieren' : 'Aktivieren' }}</button></form></div>@endforeach</div></section>

    <section class="mt-8 grid gap-6 xl:grid-cols-[1fr_1.2fr]">
        <div class="luczor-card p-5">
            <h2 class="font-semibold text-white">Agent-Profil</h2>
            <form class="mt-4 grid gap-3 md:grid-cols-2" method="POST" action="{{ route('dashboard.agent-profiles.store') }}">@csrf
                <input class="luczor-input" name="key" placeholder="backend" required><input class="luczor-input" name="name" placeholder="Backend Agent" required>
                <input class="luczor-input" name="type" placeholder="backend" required><select class="luczor-input" name="status"><option value="active">Aktiv</option><option value="draft">Entwurf</option><option value="disabled">Deaktiviert</option></select>
                <input class="luczor-input md:col-span-2" name="prompt_template_key" value="luczor.system">
                <textarea class="luczor-input font-mono text-xs md:col-span-2" name="required_sources" rows="2">["graphify","github","cognee"]</textarea>
                <textarea class="luczor-input font-mono text-xs md:col-span-2" name="capabilities" rows="2">[]</textarea>
                <textarea class="luczor-input font-mono text-xs md:col-span-2" name="config" rows="2">{"parallel_safe":false}</textarea>
                <button class="luczor-btn">Agent speichern</button>
            </form>
        </div>
        <div class="luczor-card p-5"><h2 class="font-semibold text-white">Orchestrator-Agenten</h2><div class="mt-4 grid gap-3 md:grid-cols-2">@foreach($agentProfiles as $agent)<div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm"><div class="flex justify-between"><b class="text-cyan-100">{{ $agent->name }}</b><span class="text-xs text-slate-500">{{ $agent->status }}</span></div><div class="mt-1 font-mono text-xs text-slate-500">{{ $agent->type }} · {{ implode(', ', $agent->required_sources ?? []) }}</div></div>@endforeach</div></div>
    </section>

    <section class="mt-8 grid gap-6 lg:grid-cols-2">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Aktive API Keys</h2>
            <div class="mt-4 space-y-3">
                @forelse ($apiKeys as $key)
                    <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="font-semibold text-slate-100">{{ $key->name }}</div>
                                <div class="text-slate-500">{{ $key->device_name ?: 'kein Device Name' }} / {{ $key->device_id ?: 'keine Device ID' }}</div>
                            </div>
                            <form method="POST" action="{{ route('dashboard.api-keys.toggle', $key) }}">
                                @csrf
                                <button class="luczor-btn-secondary" type="submit">{{ $key->active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                            </form>
                        </div>
                        <div class="mt-2 text-xs text-slate-500">Abilities: {{ implode(', ', $key->abilities ?? []) }}</div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Noch keine API Keys.</p>
                @endforelse
            </div>
        </div>

        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Provider Credentials</h2>
            <div class="mt-4 space-y-3">
                @forelse ($providers as $provider)
                    <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                        <div class="font-semibold text-slate-100">{{ $provider->label }}</div>
                        <div class="text-slate-500">{{ $provider->provider }} / {{ $provider->base_url ?: 'default endpoint' }}</div>
                        <div class="mt-1 font-mono text-xs text-cyan-200">{{ $provider->maskedKey() }}</div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Noch keine Provider Credentials.</p>
                @endforelse
            </div>
        </div>
    </section>
    @endif
</x-app-layout>
