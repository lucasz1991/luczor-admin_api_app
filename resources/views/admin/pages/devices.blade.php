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
