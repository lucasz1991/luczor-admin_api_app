<x-app-layout>
    <x-ui.page title="Meine Geräte & Kosten" eyebrow="Persönlicher Gerätepark" description="Deine lokalen Modelle, ihre Verbindung und deine Nutzung auf einen Blick.">
        <x-slot:actions><x-ui.button :href="route('account.workspace')">Workspace öffnen</x-ui.button></x-slot:actions>
        <div class="grid gap-4 sm:grid-cols-3" aria-label="Geräteübersicht">
            <x-ui.stat label="Verbundene Geräte" :value="$devices->whereNull('revoked_at')->count()" hint="Deinem Konto zugeordnet" />
            <x-ui.stat label="Jetzt online" :value="$devices->filter(fn($device) => !$device->revoked_at && $device->last_seen_at?->gt(now()->subMinutes(2)))->count()" hint="Signal in den letzten zwei Minuten" />
            <x-ui.stat label="Geschätzte Modellkosten" :value="number_format($costs->sum('estimated_cost_usd'), 2, ',', '.').' USD'" hint="Gesamtzeitraum" />
        </div>
        @if(session('status'))<p class="ui-notice" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<p class="ui-notice" role="alert">{{ $errors->first() }}</p>@endif
        <x-ui.tabs id="device-sections" :tabs="['devices' => 'Meine Geräte', 'costs' => 'Nutzung & Kosten', 'connect' => 'Gerät hinzufügen']" active="devices">
            <x-ui.tab-panel name="devices">
                <p class="mb-5 max-w-3xl text-sm leading-6 text-slate-400">Ein Master-Gerät kann deine anderen Geräte koordinieren. Jedes Gerät kann weiterhin eigenständig arbeiten. Im Web-Workspace wählst du das Ziel direkt aus.</p>
                <div class="grid gap-5 lg:grid-cols-2">
                    @forelse($devices as $device)
                        @php($online = !$device->revoked_at && $device->last_seen_at?->gt(now()->subMinutes(2)))
                        <x-ui.panel :title="$device->name" :description="'Zuletzt gesehen: '.($device->last_seen_at?->locale('de')->diffForHumans() ?? 'Noch kein Kontakt')">
                            <x-slot:actions><x-ui.badge :tone="$device->revoked_at ? 'danger' : ($online ? 'success' : 'neutral')">{{ $device->revoked_at ? 'Zugriff entzogen' : ($online ? 'Online' : 'Offline') }}</x-ui.badge></x-slot:actions>
                            <form class="space-y-5" method="POST" action="{{ route('account.devices.update', $device) }}">
                                @csrf @method('PATCH')
                                <div><label class="ui-label" for="device-name-{{ $device->id }}">Gerätename</label><x-ui.input id="device-name-{{ $device->id }}" name="name" :value="$device->name" maxlength="120" required :disabled="(bool)$device->revoked_at" /></div>
                                <input type="hidden" name="master" value="0">
                                <label class="flex items-start gap-3 text-sm"><input class="mt-1" type="checkbox" name="master" value="1" @checked((int)$user->master_device_id === (int)$device->id) @disabled($device->revoked_at)><span>Als Master-Gerät verwenden<span class="mt-1 block text-xs leading-5 text-slate-400">Koordiniert Aufträge zwischen deinen Geräten.</span></span></label>
                                <div class="flex flex-wrap items-center justify-between gap-3"><p class="min-w-0 break-all font-mono text-xs text-slate-500">{{ $device->device_id }}</p><x-ui.button type="submit" variant="secondary" :disabled="(bool)$device->revoked_at">Speichern</x-ui.button></div>
                            </form>
                        </x-ui.panel>
                    @empty
                        <div class="lg:col-span-2"><x-ui.empty title="Dein erstes Gerät wartet.">Verbinde deine Luczor-App unter Einstellungen → Server → Im Browser anmelden.</x-ui.empty></div>
                    @endforelse
                </div>
            </x-ui.tab-panel>
            <x-ui.tab-panel name="costs">
                <x-ui.panel title="Meine Modellkosten nach Gerät · Gesamtzeitraum" description="Schätzwerte in USD. Läufe ohne Kostenangabe werden separat ausgewiesen.">
                    <x-ui.table>
                        <thead><tr><th>Gerät</th><th>Läufe</th><th>Geschätzte Kosten</th><th>Ohne Kostenangabe</th></tr></thead>
                        <tbody>@forelse($costs as $cost)<tr><td>{{ $devices->firstWhere('device_id', $cost->client_id)?->name ?? $cost->client_id ?? 'Ohne Gerät' }}</td><td>{{ $cost->runs_count }}</td><td>{{ number_format($cost->estimated_cost_usd, 6, ',', '.') }} USD</td><td>{{ $cost->unknown_cost_count }}</td></tr>@empty<tr><td colspan="4">Noch keine Modellkosten gemeldet.</td></tr>@endforelse</tbody>
                    </x-ui.table>
                </x-ui.panel>
            </x-ui.tab-panel>
            <x-ui.tab-panel name="connect">
                <div class="max-w-3xl"><x-ui.panel title="Ein Konto. Mehrere Geräte." description="Die Desktop-App startet die Verbindung; hier bestätigst du die Zuordnung.">
                    <ol class="space-y-5 text-sm leading-6 text-slate-300"><li><span class="ui-kicker mr-3">01</span>Öffne Luczor auf dem neuen Gerät.</li><li><span class="ui-kicker mr-3">02</span>Wähle Einstellungen → Server → Im Browser anmelden.</li><li><span class="ui-kicker mr-3">03</span>Melde dich mit deinem Konto an und bestätige das Gerät.</li></ol>
                    <p class="mt-6 text-sm leading-6 text-slate-400">Danach erscheint es in deiner Geräteübersicht. Du kannst einen Namen vergeben und es als Master-Gerät auswählen.</p>
                </x-ui.panel></div>
            </x-ui.tab-panel>
        </x-ui.tabs>
    </x-ui.page>
</x-app-layout>
