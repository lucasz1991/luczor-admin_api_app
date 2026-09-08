<x-ui.panel title="Agententeams und Modellrecherche">
    <p class="mt-2 text-sm text-slate-400">Das lokale Modell koordiniert und führt freigegebene Tools aus. Externe Modelle liefern begrenzte Recherche, Codeentwürfe und Prüfberichte. Nur ausdrücklich freigegebener Kontext verlässt den Desktop.</p>
    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <form method="POST" action="{{ route('dashboard.agent-teams.prepare') }}" class="space-y-3 ui-record">
            @csrf
            <label class="block text-sm" for="agent-credential">Vorhandener OpenRouter-Zugang</label>
            <x-ui.select id="agent-credential" name="provider_credential_id" required>
                <option value="">Zugang auswählen</option>
                @foreach($providers->where('active', true)->where('provider', 'openrouter')->where('request_format', 'chat_completions') as $credential)
                    <option value="{{ $credential->id }}">{{ $credential->label }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.button type="submit" variant="primary">Teams ergänzen</x-ui.button>
            <p class="text-xs text-slate-500">Ergänzt fehlende Rollen, Modelle und Kostenregeln. Bestehende oder deaktivierte Profile und bearbeitete Routingketten bleiben erhalten. Free-Rollen haben 0 USD Kostenbudget, externe Planung höchstens 0,05 USD je Anfrage.</p>
        </form>
        <form method="POST" action="{{ route('dashboard.agent-teams.update') }}" class="space-y-3 ui-record">
            @csrf @method('PUT')
            <input type="hidden" name="enabled" value="0">
            <label class="flex items-center gap-2 text-sm"><input name="enabled" type="checkbox" value="1" @checked($agentTeamPolicy['enabled'])> Externe Agententeams verfügbar</label>
            <label class="block text-sm" for="agent-preset">Standardteam</label>
            <x-ui.select id="agent-preset" name="default_preset">
                @foreach($agentTeamPolicy['presets'] as $preset)<option value="{{ $preset['id'] }}" @selected($agentTeamPolicy['default_preset'] === $preset['id'])>{{ $preset['label'] }}</option>@endforeach
            </x-ui.select>
            <label class="block text-sm" for="agent-parallel">Gleichzeitige externe Aufgaben</label>
            <x-ui.input id="agent-parallel" name="max_parallel" type="number" min="1" max="3" value="{{ $agentTeamPolicy['presets'][0]['max_parallel'] }}" required aria-label="max parallel" />
            <x-ui.button type="submit" variant="secondary">Team-Einstellungen speichern</x-ui.button>
        </form>
    </div>
</x-ui.panel>
