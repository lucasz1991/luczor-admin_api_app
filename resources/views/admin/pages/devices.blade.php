<x-ui.panel title="Registrierte Geräte" description="Eine Debug-Anforderung wird an das ausgewählte Gerät gesendet. Bestehende Gerätezuordnungen bleiben erhalten.">
    <div class="grid gap-5 lg:grid-cols-2">
        @forelse($devices as $device)
            <article class="ui-record space-y-4">
                <div class="min-w-0"><h3 class="font-semibold">{{ $device->name ?: $device->device_id }}</h3><p class="mt-1 break-all font-mono text-xs text-slate-400">{{ $device->device_id }}</p></div>
                <form method="POST" action="{{ route('dashboard.devices.debug.request', $device) }}">@csrf<x-ui.button type="submit" variant="secondary">Debug anfordern</x-ui.button>
</form>
            </article>
        @empty
            <div class="lg:col-span-2">
<x-ui.empty title="Noch keine Geräte registriert">Geräte erscheinen hier, sobald sich eine Luczor-App mit dem Backend verbindet.</x-ui.empty>
</div>
        @endforelse
    </div>
</x-ui.panel>

<x-ui.panel title="Diagnoseberichte" description="Gesammelte Berichte zum Herunterladen. Für Chat-Inhalte auf dem Gerät unter Einstellungen → Datenschutz zusätzlich die ausführliche Chat-Diagnose aktivieren.">
    <div class="mb-5 flex flex-wrap gap-3">
        <x-ui.button :href="route('admin.page', 'devices')" variant="secondary">Status aktualisieren</x-ui.button>
        <x-ui.button :href="route('dashboard.devices.debug.export')" variant="secondary">Letzte 50 Berichte gesammelt (JSONL)</x-ui.button>
    </div>
    <div class="grid gap-5 lg:grid-cols-2">
        @forelse($debugRequests as $debug)
            <article class="ui-record space-y-3">
                <h3 class="font-semibold">{{ $debug->device?->name ?: 'Gerät nicht mehr vorhanden' }}</h3>
                <p class="break-all font-mono text-xs text-slate-400">{{ $debug->public_id }}</p>
                <p>{{ ['pending' => 'Wartet auf Gerät / Freigabe', 'collecting' => 'Wird gesammelt – Wiederholung nach Verbindungsabbruch möglich', 'completed' => 'Bereit zum Download', 'failed' => 'Fehlgeschlagen'][$debug->status] ?? $debug->status }}</p>
                <p class="text-sm text-slate-400">Angefordert {{ $debug->requested_at?->format('d.m.Y. H:i') }}
                    @if($debug->completed_at) · Empfangen {{ $debug->completed_at->format('d.m.Y. H:i') }} @endif</p>
                @if($debug->status === 'completed')
                    <p class="text-sm">{{ $debug->meta['trace_events'] ?? 0 }} Chat-/Modell-/Tool-Ereignisse · {{ $debug->meta['report_version'] ?? 'älterer Bericht' }}</p>
                    @if(($debug->meta['dropped_events'] ?? 0) > 0)
                        <p class="text-sm text-amber-300">{{ $debug->meta['dropped_events'] }} ältere Ereignisse wegen Speichergrenze entfernt.</p>
                    @endif
                    <x-ui.button :href="route('dashboard.devices.debug.download', $debug)" variant="secondary">Bericht herunterladen (JSON)</x-ui.button>
                @endif
            </article>
        @empty
            <p>Noch keine Diagnose angefordert.</p>
        @endforelse
    </div>
</x-ui.panel>
