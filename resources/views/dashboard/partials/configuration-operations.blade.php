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
                        <form method="POST" action="{{ route('dashboard.devices.debug.request', $device) }}">@csrf<input type="hidden" name="_dashboard_tool_group" value="operations"><button class="luczor-btn-secondary">Debug anfordern</button></form>
                        <span class="text-xs text-slate-500">zuletzt {{ optional($device->last_seen_at)->diffForHumans() ?: 'unbekannt' }}</span>
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-500">Noch keine registrierten Geräte.</p>
            @endforelse
        </div>
        <div class="mt-5 overflow-x-auto"><table class="min-w-full text-left text-xs"><thead class="text-slate-500"><tr><th class="py-2">Zeit</th><th>Gerät</th><th>Status</th><th></th></tr></thead><tbody class="divide-y divide-slate-900">
            @forelse($debugRequests as $debug)
                <tr><td class="py-2">{{ optional($debug->requested_at)->format('d.m.Y H:i') }}</td><td class="font-mono text-cyan-200">{{ $debug->device?->device_id }}</td><td class="{{ $debug->status === 'completed' ? 'text-emerald-300' : ($debug->status === 'failed' ? 'text-rose-300' : 'text-amber-300') }}">{{ $debug->status }}</td><td>@if($debug->status === 'completed')<a class="admin-dashboard-debug-download text-cyan-200 hover:text-white" href="{{ route('dashboard.devices.debug.download', $debug) }}">Download</a>@endif</td></tr>
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
