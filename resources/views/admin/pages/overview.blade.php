@php
    $operationLabels = ['users' => 'Benutzer', 'devices_online' => 'Geräte online', 'device_jobs_open' => 'Offene Geräteaufträge', 'llm_runs_24h' => 'Modell-Läufe · 24 h', 'evaluations_24h' => 'Prüfungen · 24 h', 'audit_events_24h' => 'Ereignisse · 24 h'];
@endphp
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    @foreach($operations as $label => $value)<x-ui.stat :label="$operationLabels[$label] ?? str_replace('_', ' ', $label)" :value="$value" />@endforeach
</div>
<x-ui.tabs id="admin-overview" :tabs="['activity' => 'Aktivität', 'manage' => 'Verwalten']" active="activity">
    <x-ui.tab-panel name="activity">
<div class="grid gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)]">
    <x-ui.panel title="Läufe / Tag" description="Die letzten 14 Tage">
<div class="text-sm text-slate-400">{{ $charts['total_runs'] ?? 0 }} Läufe · ${{ $charts['total_cost'] ?? 0 }}</div>
        <svg viewBox="0 0 560 140" class="mt-3 w-full" preserveAspectRatio="none" role="img" aria-label="Läufe pro Tag">
            <line x1="0" y1="130" x2="560" y2="130" stroke="rgba(148,197,236,0.15)"/>
            @foreach(($charts['bars'] ?? []) as $b)<rect x="{{ $b['x'] }}" y="{{ $b['y'] }}" width="{{ $b['w'] }}" height="{{ $b['h'] }}" rx="2" fill="rgba(34,211,238,0.55)"><title>{{ $b['runs'] }} Läufe</title></rect><text x="{{ $b['x'] + $b['w']/2 }}" y="139" fill="rgba(107,142,166,0.8)" font-size="7" text-anchor="middle">{{ $b['day'] }}</text>@endforeach
            @if(!empty($charts['cost_points']))<polyline points="{{ $charts['cost_points'] }}" fill="none" stroke="rgb(245,158,11)" stroke-width="1.5" stroke-linejoin="round"/>@endif
        </svg>
        <div class="mt-1 flex gap-4 text-xs text-slate-500"><span><span class="inline-block h-2 w-2 rounded-sm" style="background:rgba(34,211,238,.55)"></span> Läufe</span><span><span class="inline-block h-2 w-2 rounded-sm" style="background:rgb(245,158,11)"></span> Kosten</span></div>
    </x-ui.panel>
    <x-ui.panel title="Provider (30 T)">
        <div class="mt-3 space-y-2">@forelse(($charts['providers'] ?? []) as $p)<div>
<div class="flex justify-between text-xs"><span class="text-slate-300">{{ $p['label'] }}</span><span class="text-slate-500">{{ $p['value'] }}</span></div>
<div class="mt-1 h-2 rounded bg-slate-800">
<div class="h-2 rounded" style="width:{{ $p['pct'] }}%;background:rgba(34,211,238,.6)"></div></div></div>@empty<p class="text-xs text-slate-500">Noch keine Läufe.</p>@endforelse</div>
        <h2 class="mt-5 font-semibold">Workflow-Status</h2>
        <div class="mt-3 space-y-2">@forelse(($charts['workflow_status'] ?? []) as $s)<div>
<div class="flex justify-between text-xs"><span class="text-slate-300">{{ $s['label'] }}</span><span class="text-slate-500">{{ $s['value'] }}</span></div>
<div class="mt-1 h-2 rounded bg-slate-800">
<div class="h-2 rounded" style="width:{{ $s['pct'] }}%;background:rgba(52,211,153,.6)"></div></div></div>@empty<p class="text-xs text-slate-500">Noch keine Läufe.</p>@endforelse</div>
    </x-ui.panel>
</div>
    </x-ui.tab-panel>
    <x-ui.tab-panel name="manage">
        <div class="grid gap-5 lg:grid-cols-2">
            @foreach([
                ['models', 'Modelle verwalten', 'Modellprofile, Fallback-Ketten und Messwerte.'],
                ['telemetry', 'Telemetry auswerten', 'Kosten, Geschwindigkeit und Erfolgsrate.'],
                ['users', 'Benutzer verwalten', 'User, Projekte, Geräte und Besitzzuordnung.'],
                ['costs', 'Kosten je Benutzer', 'LLM-Kosten nach User, Projekt und Gerät.'],
                ['devices', 'Geräte debuggen', 'Gezielte Debug-Anforderung für ein Gerät.'],
                ['workflows', 'Workflows', 'AI-Abläufe orchestrieren, starten und exportieren.'],
                ['agents', 'Agenten & Ereignisse', 'Agent-Läufe und das Audit-Ereignisprotokoll.'],
            ] as [$target, $title, $description])
                <x-ui.panel :title="$title" :description="$description">
<x-ui.button href="{{ route('admin.page', $target) }}" variant="secondary">Bereich öffnen</x-ui.button>
</x-ui.panel>
            @endforeach
        </div>
    </x-ui.tab-panel>
</x-ui.tabs>
