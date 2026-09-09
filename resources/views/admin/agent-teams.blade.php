@if(session('agent_team_setup_result'))
    <x-ui.alert :tone="session('agent_team_setup_result.tone')">{{ session('agent_team_setup_result.message') }}</x-ui.alert>
@endif
<x-ui.panel title="Einrichtung vervollständigen" description="Der Team-Schalter allein legt noch keine Rollenmodelle an. Diese Prüfung liest nur die aktuelle Konfiguration.">
    <p class="text-sm"><strong>{{ $agentTeamSetup['configured_count'] }} von {{ $agentTeamSetup['requested_count'] }} externen Rollen konfiguriert</strong> · {{ $agentTeamSetup['selected_label'] }}</p>
    <ol class="mt-4 space-y-3 text-sm list-decimal pl-5">
        <li>
            <strong>Katalog und Rollenabdeckung prüfen.</strong>
            <span>{{ $agentTeamSetup['catalog_current'] ? 'Der gespeicherte Katalog liegt innerhalb des 14-Tage-Zeitraums.' : 'Der Katalog muss neu geprüft werden, bevor Rollen ergänzt werden.' }}</span>
            <form method="POST" action="{{ route('dashboard.agent-teams.research') }}" class="mt-2">@csrf<x-ui.button type="submit" variant="secondary">OpenRouter-Katalog neu prüfen</x-ui.button></form>
            <p class="mt-2 text-xs text-slate-400">Geprüfte Kandidaten: @foreach($agentTeamSetup['roles'] as $role){{ $role['label'] }} {{ $role['catalog_candidates'] }}{{ $loop->last ? '.' : ' · ' }}@endforeach</p>
            @if(collect($agentTeamSetup['roles'])->contains(fn ($role) => $role['catalog_candidates'] === 0))
                <p class="mt-2 text-amber-200">Für Rollen mit 0 Kandidaten kann „Teams ergänzen“ keine Modellroute anlegen. Katalog neu prüfen oder unter <a class="underline" href="{{ route('admin.page', 'models') }}">Modelle und Rollenketten</a> eine eigene passende Auswahl konfigurieren.</p>
            @endif
        </li>
        <li>
            <strong>Provider-Zugang zuordnen.</strong>
            @if($agentTeamSetup['credential_count'] === 0)
                <span>Kein aktiver OpenRouter-Zugang mit Chat Completions vorhanden.</span> <a class="underline" href="{{ route('admin.page', 'providers') }}">Zugänge verwalten</a>
            @else
                <span>{{ $agentTeamSetup['credential_count'] }} aktive Zugänge mit passendem Anfrageformat vorhanden. Gewünschten Zugang im Formular unten auswählen; Schlüssel und Rollenroute werden gesondert geprüft.</span>
            @endif
        </li>
        <li><strong>„Teams ergänzen“ ausführen und Ergebnis prüfen.</strong> Fehlende Rollen werden ergänzt. Vorhandene leere aktive Ketten werden nur mit der zusätzlichen Checkbox befüllt. Der Rollenstatus darunter nennt verbleibende Hindernisse.</li>
    </ol>
</x-ui.panel>
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
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="fill_empty_routes" value="1" class="mt-1"> Leere aktive Rollenketten erneut befüllen</label>
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
<x-ui.panel title="Einrichtungsstatus je Rolle" description="Prüft Rollen, Zugänge, Preise und Routingregeln. Die Verfügbarkeit eines Providers wird erst beim tatsächlichen Auftrag geprüft.">
    <div class="grid gap-3 md:grid-cols-2">
        @foreach(['planning' => 'Planung', 'research' => 'Recherche', 'coding' => 'Codeentwurf', 'review' => 'Prüfung'] as $role => $label)
            @php($rolePolicy = $agentTeamPolicy['models_by_role'][$role])
            <article class="ui-record space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-semibold">{{ $label }}</h3><x-ui.badge :tone="$rolePolicy['ready'] ? 'success' : 'warning'">{{ $rolePolicy['ready'] ? 'Konfiguriert' : 'Einrichtung offen' }}</x-ui.badge></div>
                @if($role === 'planning')<p class="text-xs text-slate-400">Im Free-Team übernimmt das lokale Modell die Planung.</p>@endif
                @if($rolePolicy['reason'])<p class="text-sm text-amber-200">{{ $rolePolicy['reason'] }}</p>@endif
                <p class="text-xs text-slate-400">{{ count($rolePolicy['candidates']) }} aktive Kandidaten mit gültigem Preisstand</p>
            </article>
        @endforeach
    </div>
    <a class="mt-4 inline-block text-sm text-cyan-300 underline" href="{{ route('admin.page', 'models') }}">Modelle und Rollenketten bearbeiten</a>
</x-ui.panel>
