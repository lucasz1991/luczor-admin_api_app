<x-ui.tabs id="admin-models" :tabs="['profiles' => 'Modellprofile', 'routing' => 'Fallback-Ketten', 'create' => 'Profil anlegen']" active="profiles">
    <x-ui.tab-panel name="profiles">
        <x-ui.panel title="Profile" description="Modell, Zweck und Freigabe im Überblick. Öffne ein Profil zum Bearbeiten.">
            <div class="space-y-4">
                @forelse($modelProfiles as $profile)
                    <article class="ui-record">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0"><h3 class="font-semibold text-slate-100">{{ $profile->name }}</h3><p class="mt-1 break-all font-mono text-xs text-slate-400">{{ $profile->model_id }}</p></div>
                            <div class="flex items-center gap-2"><x-ui.badge>{{ $profile->purpose }}</x-ui.badge><x-ui.badge :tone="$profile->active ? 'success' : 'neutral'">{{ $profile->active ? 'aktiv' : 'aus' }}</x-ui.badge></div>
                        </div>
                        <details class="ui-disclosure mt-4">
                            <summary>Profil bearbeiten</summary>
                            <form class="mt-5 space-y-5" method="POST" action="{{ route('dashboard.model-profiles.update', $profile) }}">
                                @csrf @method('PUT')
                                @include('admin.pages.model-profile-fields', ['profile' => $profile])
                                <x-ui.button type="submit">Änderungen speichern</x-ui.button>
                            </form>
                            <div class="mt-5 flex flex-wrap items-center gap-3">
                                <form method="POST" action="{{ route('dashboard.model-profiles.toggle', $profile) }}">@csrf<x-ui.button type="submit" variant="secondary">{{ $profile->active ? 'Deaktivieren' : 'Aktivieren' }}</x-ui.button></form>
                                <form method="POST" action="{{ route('dashboard.model-profiles.destroy', $profile) }}">@csrf @method('DELETE')<x-ui.button type="submit" variant="danger">Löschen</x-ui.button></form>
                            </div>
                        </details>
                    </article>
                @empty
                    <x-ui.empty title="Noch keine Modellprofile">Lege im Tab „Profil anlegen“ das erste Modell an. Provider-Zugänge verwaltest du separat.</x-ui.empty>
                @endforelse
            </div>
        </x-ui.panel>
    </x-ui.tab-panel>
    <x-ui.tab-panel name="routing">@include('admin.pages.model-routing')</x-ui.tab-panel>
    <x-ui.tab-panel name="create">
        <x-ui.panel title="Neues Modellprofil" description="Nur Admins bestimmen Modelle und Fallbacks. Ein aktiver Provider-Zugang wird benötigt.">
            <form class="space-y-6" method="POST" action="{{ route('dashboard.model-profiles.store') }}">
                @csrf
                @include('admin.pages.model-profile-fields', ['profile' => null])
                <x-ui.button type="submit">Profil anlegen</x-ui.button>
            </form>
        </x-ui.panel>
    </x-ui.tab-panel>
</x-ui.tabs>
