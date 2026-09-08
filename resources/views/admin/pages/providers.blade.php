<x-ui.tabs id="admin-providers" :tabs="['connections' => 'Zugänge', 'create' => 'Zugang hinzufügen']" active="connections">
    <x-ui.tab-panel name="connections">
        <x-ui.panel title="Provider-Zugänge" description="Schlüssel bleiben verschlüsselt auf dem Server. Hier siehst du ausschließlich maskierte Angaben.">
            <div class="space-y-4">
                @forelse($providers as $provider)
                    <article class="ui-record flex flex-wrap items-center justify-between gap-4">
                        <div class="min-w-0"><h3 class="font-semibold">{{ $provider->label }}</h3><p class="mt-1 text-sm text-slate-400">{{ $provider->provider }} · <span class="font-mono">{{ $provider->maskedKey() }}</span></p></div>
                        <div class="flex items-center gap-3">
<x-ui.badge :tone="$provider->active ? 'success' : 'neutral'">{{ $provider->active ? 'Aktiv' : 'Inaktiv' }}</x-ui.badge>
<form method="POST" action="{{ route('dashboard.provider-credentials.toggle', $provider) }}">@csrf<x-ui.button type="submit" variant="secondary">{{ $provider->active ? 'Deaktivieren' : 'Aktivieren' }}</x-ui.button>
</form>
</div>
                    </article>
                @empty
                    <x-ui.empty title="Noch keine Provider-Zugänge">Füge einen Zugang hinzu, um externe Modellprofile zu verwenden.</x-ui.empty>
                @endforelse
            </div>
        </x-ui.panel>
    </x-ui.tab-panel>
    <x-ui.tab-panel name="create">
        <x-ui.panel title="Provider Credential" description="Verbindung benennen, Anfrageformat wählen und den Zugang verschlüsselt speichern.">
            <form class="space-y-6" method="POST" action="{{ route('dashboard.provider-credentials.store') }}">
                @csrf
                <div class="grid gap-5 md:grid-cols-2">
                    <label class="ui-field">Provider<x-ui.input name="provider" value="openrouter" /></label>
                    <label class="ui-field">Anfrageformat<x-ui.select name="request_format"><option value="chat_completions">Chat Completions</option><option value="responses">OpenAI Responses</option><option value="messages">Anthropic Messages</option></x-ui.select>
</label>
                    <label class="ui-field">Bezeichnung<x-ui.input name="label" placeholder="Mein OpenRouter-Zugang" required /></label>
                    <label class="ui-field">API-Key<x-ui.input name="api_key" type="password" autocomplete="new-password" placeholder="Wird verschlüsselt gespeichert" required /></label>
                    <label class="ui-field md:col-span-2">Server-URL<x-ui.input name="base_url" value="https://openrouter.ai/api/v1" /></label>
                </div>
                <x-ui.button type="submit">Zugang speichern</x-ui.button>
            </form>
        </x-ui.panel>
    </x-ui.tab-panel>
</x-ui.tabs>
