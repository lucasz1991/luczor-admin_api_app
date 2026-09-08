{{-- RailTime UI page / dashboard stat-card / accordion tabs pattern, adapted to Luczor's own account scope. --}}
<x-ui.page title="Mein Luczor" eyebrow="Dein Arbeitsbereich" description="Deine Geräte, Gespräche und Projekte. Über den Workspace behältst du alles im Blick.">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('account.devices')">Meine Geräte</x-ui.button>
        <x-ui.button :href="route('account.workspace')">Workspace öffnen</x-ui.button>
    </x-slot:actions>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat label="Verknüpfte Geräte" :value="$devices->count()" hint="In deinem Benutzerkonto" />
        <x-ui.stat label="Synchronisierte Projekte" :value="$userProjects->count()" hint="Aus deinen Geräten" />
        <x-ui.stat label="Archivierte Einträge" :value="array_sum($archiveCounts)" hint="Deine gespeicherten Daten" />
        <x-ui.stat label="Aktive Verbindungen" :value="$apiKeys->filter(fn ($key) => $key->active && ! $key->isExpired())->count()" hint="Gültige Gerätezugänge" />
    </div>

    <x-ui.tabs id="member-dashboard" :tabs="['overview' => 'Übersicht', 'projects' => 'Projekte & Aktivität', 'connect' => 'Gerät verbinden']" :active="$errors->any() ? 'connect' : 'overview'">
        <x-ui.tab-panel name="overview">
            <div class="grid gap-6 lg:grid-cols-[1.2fr_1fr]">
                <x-ui.panel title="Ein Workspace für deine Geräte" description="Besprich einen Auftrag, wähle das passende Gerät und verfolge die Antworten im Webchat.">
                    <div class="space-y-5">
                        <p class="text-sm leading-relaxed text-slate-400">Im übergeordneten Chat kannst du deine Geräte und Chats koordinieren. Für ein neues Thema steht dir ein freier Chat mit deinen persönlichen Erinnerungen zur Verfügung.</p>
                        <x-ui.button :href="route('account.workspace')">Zu Chats & Steuerung</x-ui.button>
                        <p class="text-xs leading-relaxed text-slate-500">Ob ein Gerät erreichbar ist und Aufträge ausführen kann, siehst du in der Geräteübersicht.</p>
                    </div>
                </x-ui.panel>
                <x-ui.panel id="devices" title="Meine Geräte" description="Verknüpfte Geräte und ihre letzten Statusmeldungen.">
                    <x-slot:actions><x-ui.button variant="secondary" :href="route('account.devices')">Alle Geräte</x-ui.button></x-slot:actions>
                    <div class="divide-y divide-white/5">
                        @forelse ($devices->take(5) as $device)
                            <div class="flex flex-wrap items-center justify-between gap-3 py-4 first:pt-0 last:pb-0">
                                <div class="min-w-0">
                                    <p class="break-words font-medium text-slate-100">{{ $device->name ?: $device->device_id }}</p>
                                    <p class="mt-1 text-xs text-slate-500">Zuletzt gemeldet: {{ $device->last_seen_at?->locale('de')->diffForHumans() ?? 'noch keine Meldung' }}</p>
                                </div>
                                <x-ui.badge :tone="$device->last_seen_at?->gt(now()->subMinutes(5)) ? 'success' : 'neutral'">{{ $device->last_seen_at?->gt(now()->subMinutes(5)) ? 'Kürzlich aktiv' : 'Keine aktuelle Meldung' }}</x-ui.badge>
                            </div>
                        @empty
                            <x-ui.empty title="Noch kein Gerät verknüpft">Verbinde die Luczor-App im Tab „Gerät verbinden“ mit deinem Konto.</x-ui.empty>
                        @endforelse
                    </div>
                </x-ui.panel>
            </div>
        </x-ui.tab-panel>
        <x-ui.tab-panel name="projects">
            <div class="grid gap-6 lg:grid-cols-[1.2fr_1fr]">
                <x-ui.panel id="projects" title="Meine Projekte" description="Die zuletzt synchronisierten Projekte deiner Geräte.">
                    <div class="divide-y divide-white/5">
                        @forelse ($userProjects as $project)
                            <div class="flex flex-wrap items-center justify-between gap-3 py-4 first:pt-0 last:pb-0">
                                <div class="min-w-0">
                                    <p class="break-words font-medium text-slate-100">{{ $project->name }}</p>
                                    <p class="mt-1 break-all font-mono text-xs text-slate-500">{{ $project->external_id }}</p>
                                </div>
                                <span class="text-xs text-slate-500">{{ $project->updated_at?->locale('de')->diffForHumans() }}</span>
                            </div>
                        @empty
                            <x-ui.empty title="Noch keine Projekte">Sobald deine App ein Projekt synchronisiert, erscheint es hier.</x-ui.empty>
                        @endforelse
                    </div>
                </x-ui.panel>
                <x-ui.panel title="Letzte Agenten-Aktivität" description="Die jüngsten Ereignisse aus deinem Benutzerbereich.">
                    <div class="divide-y divide-white/5">
                        @forelse ($userEvents as $event)
                            <div class="space-y-1 py-3 first:pt-0 last:pb-0">
                                <p class="break-words text-sm text-slate-200">{{ $event->event_type }}</p>
                                <p class="text-xs text-slate-500">{{ $event->created_at?->locale('de')->diffForHumans() }}</p>
                            </div>
                        @empty
                            <x-ui.empty title="Noch keine Aktivität">Neue Agenten-Ereignisse erscheinen hier automatisch nach der Synchronisierung.</x-ui.empty>
                        @endforelse
                    </div>
                </x-ui.panel>
            </div>
        </x-ui.tab-panel>
        <x-ui.tab-panel name="connect">
            <x-ui.panel id="connect" title="Geräte-Verbindung erstellen" description="Erstelle einen persönlichen Zugang für deine Luczor-App.">
                <form class="max-w-3xl space-y-5" method="POST" action="{{ route('dashboard.api-keys.store') }}">
                    @csrf
                    <label class="block space-y-2 text-sm text-slate-300">Verbindungsname
                        <x-ui.input name="name" :value="old('name')" placeholder="Zum Beispiel mein Desktop" required />
                    </label>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="block space-y-2 text-sm text-slate-300">Geräte-ID <span class="text-slate-500">(optional)</span><x-ui.input name="device_id" :value="old('device_id')" /></label>
                        <label class="block space-y-2 text-sm text-slate-300">Gerätename <span class="text-slate-500">(optional)</span><x-ui.input name="device_name" :value="old('device_name')" /></label>
                    </div>
                    <label class="block space-y-2 text-sm text-slate-300">Gültig bis <span class="text-slate-500">(optional)</span><x-ui.input name="expires_at" type="datetime-local" :value="old('expires_at')" /></label>
                    <p class="text-sm leading-relaxed text-slate-400">Die Geräteberechtigungen werden vom Server vergeben. Provider-API-Keys bleiben auf dem Server.</p>
                    <x-ui.button type="submit">Verbindungstoken erzeugen</x-ui.button>
                </form>
            </x-ui.panel>
        </x-ui.tab-panel>
    </x-ui.tabs>
</x-ui.page>
