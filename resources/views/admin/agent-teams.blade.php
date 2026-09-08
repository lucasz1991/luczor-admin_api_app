<x-ui.panel title="Agententeams und Modellrecherche">
    <p class="mt-2 text-sm text-slate-400">Das lokale Modell koordiniert und führt freigegebene Tools aus. Externe Modelle liefern begrenzte Recherche, Codeentwürfe und Prüfberichte. Nur ausdrücklich freigegebener Kontext verlässt den Desktop.</p>
    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <form method="POST" action="{{ route('dashboard.agent-teams.prepare') }}" class="space-y-3 rounded border border-slate-800 p-4">
            @csrf
            <label class="block text-sm" for="agent-credential">Vorhandener OpenRouter-Zugang</label>
            <x-ui.select id="agent-credential" name="provider_credential_id" class="" required>
                <option value="">Zugang auswählen</option>
                @foreach($providers->where('active', true)->where('provider', 'openrouter')->where('request_format', 'chat_completions') as $credential)
                    <option value="{{ $credential->id }}">{{ $credential->label }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.button class="" type="submit" variant="primary">Teams ergänzen</x-ui.button>
            <p class="text-xs text-slate-500">Ergänzt fehlende Rollen, Modelle und Kostenregeln. Bestehende oder deaktivierte Profile und bearbeitete Routingketten bleiben erhalten. Free-Rollen haben 0 USD Kostenbudget, externe Planung höchstens 0,05 USD je Anfrage.</p>
        </form>
        <form method="POST" action="{{ route('dashboard.agent-teams.update') }}" class="space-y-3 rounded border border-slate-800 p-4">
            @csrf @method('PUT')
            <input type="hidden" name="enabled" value="0">
            <label class="flex items-center gap-2 text-sm"><input name="enabled" type="checkbox" value="1" @checked($agentTeamPolicy['enabled'])> Externe Agententeams verfügbar</label>
            <label class="block text-sm" for="agent-preset">Standardteam</label>
            <x-ui.select id="agent-preset" name="default_preset" class="">
                @foreach($agentTeamPolicy['presets'] as $preset)<option value="{{ $preset['id'] }}" @selected($agentTeamPolicy['default_preset'] === $preset['id'])>{{ $preset['label'] }}</option>@endforeach
            </x-ui.select>
            <label class="block text-sm" for="agent-parallel">Gleichzeitige externe Aufgaben</label>
            <x-ui.input id="agent-parallel" name="max_parallel" type="number" min="1" max="3" value="{{ $agentTeamPolicy['presets'][0]['max_parallel'] }}" class="" required aria-label="max parallel" />
            <x-ui.button class="" type="submit" variant="secondary">Team-Einstellungen speichern</x-ui.button>
        </form>
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-3">
        <form method="POST" action="{{ route('dashboard.agent-teams.research') }}">@csrf<x-ui.button class="" type="submit" variant="secondary">OpenRouter-Katalog neu prüfen</x-ui.button></form>
        <span class="text-xs text-slate-400">Recherchestand: {{ $agentModelCatalog['researched_at'] }} · Preisstand 14 Tage gültig; danach neu prüfen und ergänzen.</span>
        <a class="text-sm text-cyan-300 underline" href="{{ route('admin.page', 'models') }}">Rollenketten und Modelle bearbeiten</a>
    </div>
    <p class="mt-3 text-xs text-amber-200">Startkandidaten aus aktueller Recherche, noch keine nachgewiesenen Luczor-Sieger. Free-Angebote können ausfallen oder begrenzt sein; es gibt keinen automatischen Wechsel zu kostenpflichtigen Spezialisten. Free-Provider können Daten für Training verwenden. Verfügbarkeit und Preise sind zeitabhängig.</p>
    <div class="mt-4 grid gap-3 lg:grid-cols-2">
        @foreach($agentModelCatalog['models'] as $model)
            <article class="min-w-0 rounded border border-slate-800 p-3 text-sm">
                <h3 class="font-semibold text-cyan-100">{{ $model['name'] }}</h3>
                <p class="break-all font-mono text-xs text-slate-400">{{ $model['id'] }}</p>
                <p class="mt-2">{{ implode(', ', $model['roles']) }} · {{ number_format($model['context_window'], 0, ',', '.') }} Kontext-Tokens</p>
                <p>{{ number_format($model['input_per_million'], 3, ',', '.') }} / {{ number_format($model['output_per_million'], 3, ',', '.') }} USD je 1 Mio. Eingabe-/Ausgabe-Tokens</p>
                <p class="mt-2 text-xs text-slate-400">{{ $model['data_policy'] }}</p>
                <a class="mt-2 inline-block text-xs text-cyan-300 underline" href="{{ $model['source'] }}" target="_blank" rel="noopener noreferrer">Quelle</a>
            </article>
        @endforeach
    </div>
</x-ui.panel>
<x-ui.panel title="Rollenvergleich aus ausgeführten Aufgaben">
    <p class="mt-2 text-sm text-slate-400">Automatische Rangfolge erst ab fünf unterschiedlichen bewerteten Aufgaben pro Modell und Rolle. Technisch erfolgreiche Antworten allein belegen keine Qualität. Modellbewertungen sind Schätzungen; eine bestandene Prüfung erfordert tatsächliche Testergebnisse.</p>
    <x-ui.table>
        <thead class="text-slate-500"><tr><th class="pr-3">Rolle / Modell</th><th class="pr-3">Versuche</th><th class="pr-3">Technischer Erfolg</th><th class="pr-3">Qualität</th><th class="pr-3">Testquote</th><th class="pr-3">Ø Latenz</th><th>USD / Erfolg</th></tr></thead>
        <tbody>
            @forelse($modelRankings->filter(fn ($ranking) => str_starts_with($ranking->task_type, 'agent.')) as $ranking)
                <tr class="border-t border-slate-800"><td class="py-3 pr-3"><b>{{ $ranking->task_type }}</b><div class="break-all text-slate-400">{{ $ranking->model_id }}</div></td><td>{{ $ranking->sample_count }}</td><td>{{ number_format($ranking->success_rate * 100, 0) }}%</td><td>{{ $ranking->quality_score === null ? 'unbewertet' : number_format($ranking->quality_score * 100, 0).'%' }}</td><td>{{ $ranking->test_pass_rate === null ? 'ungeprüft' : number_format($ranking->test_pass_rate * 100, 0).'%' }}</td><td>{{ number_format($ranking->avg_latency_ms, 0, ',', '.') }} ms</td><td>{{ $ranking->cost_per_success === null ? 'unbekannt' : number_format($ranking->cost_per_success, 6, ',', '.') }}</td></tr>
            @empty
                <tr><td colspan="7" class="py-3 text-slate-400">Noch keine Rollenmessungen. Bis dahin gilt die recherchierte Admin-Reihenfolge.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</x-ui.panel>
