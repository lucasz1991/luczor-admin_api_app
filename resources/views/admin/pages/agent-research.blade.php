<x-ui.panel title="Modellrecherche" description="Katalogstand prüfen und geeignete Rollenmodelle vergleichen.">
<div class="mt-4 flex flex-wrap items-center gap-3">
        <form method="POST" action="{{ route('dashboard.agent-teams.research') }}">@csrf<x-ui.button type="submit" variant="secondary">OpenRouter-Katalog neu prüfen</x-ui.button>
</form>
        <span class="text-xs text-slate-400">Recherchestand: {{ $agentModelCatalog['researched_at'] }} · Preisstand 14 Tage gültig; danach neu prüfen und ergänzen.</span>
        <a class="text-sm text-cyan-300 underline" href="{{ route('admin.page', 'models') }}">Rollenketten und Modelle bearbeiten</a>
    </div>
    <p class="mt-3 text-xs text-amber-200">Startkandidaten aus aktueller Recherche, noch keine nachgewiesenen Luczor-Sieger. Free-Angebote können ausfallen oder begrenzt sein; es gibt keinen automatischen Wechsel zu kostenpflichtigen Spezialisten. Free-Provider können Daten für Training verwenden. Verfügbarkeit und Preise sind zeitabhängig.</p>
    <div class="mt-4 grid gap-3 lg:grid-cols-2">
        @foreach($agentModelCatalog['models'] as $model)
            <article class="min-w-0 ui-record text-sm">
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
