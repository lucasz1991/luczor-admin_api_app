@php
    $claudeSettings = \App\Services\ClaudeAccessService::SETTINGS;
    $claudeValues = collect($settings ?? [])->mapWithKeys(fn ($setting) => [$setting->key => $setting->value['v'] ?? null]);
    $claudeUseCase = collect($modelUseCases ?? [])->firstWhere('slug', \App\Services\ProviderPolicyService::MAIN_USE_CASE);
    $providerPresets = [
        'openrouter' => ['format' => 'chat_completions', 'url' => 'https://openrouter.ai/api/v1', 'label' => 'OpenRouter'],
        'openai' => ['format' => 'responses', 'url' => 'https://api.openai.com/v1', 'label' => 'OpenAI'],
        'anthropic' => ['format' => 'messages', 'url' => 'https://api.anthropic.com/v1', 'label' => 'Anthropic (Claude)'],
    ];
@endphp
<x-ui.tabs id="admin-providers" :tabs="['connections' => 'Zugänge', 'create' => 'Zugang hinzufügen', 'claude' => 'Claude']" active="connections">
    <x-ui.tab-panel name="connections">
        <x-ui.panel title="Provider-Zugänge" description="Schlüssel bleiben verschlüsselt auf dem Server. Hier siehst du ausschließlich maskierte Angaben.">
            <div class="space-y-4">
                @forelse($providers as $provider)
                    <article class="ui-record flex flex-wrap items-center justify-between gap-4">
                        <div class="min-w-0"><h3 class="font-semibold">{{ $provider->label }}</h3><p class="mt-1 text-sm text-slate-400">{{ $provider->provider }} · <span class="font-mono">{{ $provider->maskedKey() }}</span></p>
                            @if($provider->user_id)<p class="mt-1 text-xs text-slate-500">Persönlicher Zugang von {{ $provider->user?->email ?? 'Benutzer #'.$provider->user_id }}</p>@endif
                        </div>
                        <div class="flex items-center gap-3">
@if($provider->user_id)<x-ui.badge tone="info">Persönlich</x-ui.badge>@endif
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
            <form class="space-y-6" method="POST" action="{{ route('dashboard.provider-credentials.store') }}"
                  x-data="{ presets: @js($providerPresets), provider: 'openrouter', format: 'chat_completions', url: 'https://openrouter.ai/api/v1', preset() { this.format = this.presets[this.provider].format; this.url = this.presets[this.provider].url; } }">
                @csrf
                <div class="grid gap-5 md:grid-cols-2">
                    <label class="ui-field">Provider<x-ui.select name="provider" x-model="provider" x-on:change="preset()">
                        @foreach($providerPresets as $value => $preset)<option value="{{ $value }}" @selected($value === 'openrouter')>{{ $preset['label'] }}</option>@endforeach
                    </x-ui.select>
</label>
                    <label class="ui-field">Anfrageformat<x-ui.select name="request_format" x-model="format"><option value="chat_completions">Chat Completions</option><option value="responses">OpenAI Responses</option><option value="messages">Anthropic Messages</option></x-ui.select>
</label>
                    <label class="ui-field">Bezeichnung<x-ui.input name="label" placeholder="Mein OpenRouter-Zugang" required /></label>
                    <label class="ui-field">API-Key<x-ui.input name="api_key" type="password" autocomplete="new-password" placeholder="Wird verschlüsselt gespeichert" required /></label>
                    <label class="ui-field md:col-span-2">Server-URL<x-ui.input name="base_url" value="https://openrouter.ai/api/v1" x-model="url" /></label>
                </div>
                <x-ui.button type="submit">Zugang speichern</x-ui.button>
            </form>
        </x-ui.panel>
    </x-ui.tab-panel>
    <x-ui.tab-panel name="claude">
        <x-ui.panel title="Claude-Modi" description="Steuert, ob Benutzer Claude als Hauptmodell wählen dürfen und welche Schlüsselquellen zulässig sind.">
            <form class="space-y-4" method="POST" action="{{ route('dashboard.claude-settings.update') }}">
                @csrf
                @foreach($claudeSettings as $key => $definition)
                    <label class="flex items-center gap-3 text-sm"><input type="checkbox" name="settings[{{ $key }}]" value="1" @checked((bool) ($claudeValues[$key] ?? $definition['default']))> {{ $definition['label'] }}</label>
                @endforeach
                <p class="text-xs text-slate-500">Der Organisationszugang ist der aktive globale Anthropic-Zugang aus der Liste „Zugänge“.</p>
                <x-ui.button type="submit">Claude-Einstellungen speichern</x-ui.button>
            </form>
        </x-ui.panel>
        <x-ui.panel class="mt-6" title="Modelle im Eingabefeld" description="Einträge des Use-Case main-claude in der gepflegten Reihenfolge. Reihenfolge und Aktivierung pflegst du unter „Modelle“.">
            <div class="space-y-4">
                @forelse($claudeUseCase?->entries ?? [] as $entry)
                    @php
                        $visible = ($entry->modelProfile?->meta['composer_visible'] ?? false) === true;
                    @endphp
                    <article class="ui-record flex flex-wrap items-center justify-between gap-4">
                        <div class="min-w-0"><h3 class="font-semibold">{{ $entry->modelProfile?->meta['composer_label'] ?? $entry->modelProfile?->name }}</h3><p class="mt-1 text-sm text-slate-400"><span class="font-mono">{{ $entry->modelProfile?->slug }}</span> · {{ $entry->modelProfile?->meta['composer_description'] ?? '' }}</p></div>
                        <div class="flex items-center gap-3">
<x-ui.badge :tone="$entry->active && $entry->modelProfile?->active ? 'success' : 'neutral'">{{ $entry->active && $entry->modelProfile?->active ? 'Aktiv' : 'Inaktiv' }}</x-ui.badge>
<x-ui.badge :tone="$visible ? 'info' : 'neutral'">{{ $visible ? 'Sichtbar' : 'Ausgeblendet' }}</x-ui.badge>
@if($entry->modelProfile)<form method="POST" action="{{ route('dashboard.model-profiles.composer-visibility', $entry->modelProfile) }}">@csrf<x-ui.button type="submit" variant="secondary">{{ $visible ? 'Ausblenden' : 'Einblenden' }}</x-ui.button>
</form>@endif
</div>
                    </article>
                @empty
                    <x-ui.empty title="Noch keine Claude-Modelle">Lege die Profile mit „php artisan luczor:claude:install-profiles“ an.</x-ui.empty>
                @endforelse
            </div>
        </x-ui.panel>
    </x-ui.tab-panel>
</x-ui.tabs>
