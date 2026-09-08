<x-ui.panel title="Rollenvergleich aus ausgeführten Aufgaben">
    <p class="mt-2 text-sm text-slate-400">Automatische Rangfolge erst ab fünf unterschiedlichen bewerteten Aufgaben pro Modell und Rolle. Technisch erfolgreiche Antworten allein belegen keine Qualität. Modellbewertungen sind Schätzungen; eine bestandene Prüfung erfordert tatsächliche Testergebnisse.</p>
    <x-ui.table>
        <thead class="text-slate-500">
<tr>
<th class="pr-3">Rolle / Modell</th>
<th class="pr-3">Versuche</th>
<th class="pr-3">Technischer Erfolg</th>
<th class="pr-3">Qualität</th>
<th class="pr-3">Testquote</th>
<th class="pr-3">Ø Latenz</th>
<th>USD / Erfolg</th>
</tr>
</thead>
        <tbody>
            @forelse($modelRankings->filter(fn ($ranking) => str_starts_with($ranking->task_type, 'agent.')) as $ranking)
                <tr>
<td class="py-3 pr-3"><b>{{ $ranking->task_type }}</b>
<div class="break-all text-slate-400">{{ $ranking->model_id }}</div></td>
<td>{{ $ranking->sample_count }}</td>
<td>{{ number_format($ranking->success_rate * 100, 0) }}%</td>
<td>{{ $ranking->quality_score === null ? 'unbewertet' : number_format($ranking->quality_score * 100, 0).'%' }}</td>
<td>{{ $ranking->test_pass_rate === null ? 'ungeprüft' : number_format($ranking->test_pass_rate * 100, 0).'%' }}</td>
<td>{{ number_format($ranking->avg_latency_ms, 0, ',', '.') }} ms</td>
<td>{{ $ranking->cost_per_success === null ? 'unbekannt' : number_format($ranking->cost_per_success, 6, ',', '.') }}</td>
</tr>
            @empty
                <tr>
<td colspan="7" class="py-3 text-slate-400">Noch keine Rollenmessungen. Bis dahin gilt die recherchierte Admin-Reihenfolge.</td>
</tr>
            @endforelse
        </tbody>
    </x-ui.table>
</x-ui.panel>
