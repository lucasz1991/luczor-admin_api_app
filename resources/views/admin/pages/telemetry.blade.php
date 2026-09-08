@php
    $metricLabels = ['runs_30d' => 'Läufe · 30 Tage', 'success_rate' => 'Erfolgsrate', 'cost_30d' => 'Kosten · 30 Tage (USD)', 'cost_per_success' => 'Kosten / Erfolg (USD)', 'avg_latency_ms' => 'Ø Latenz (ms)', 'avg_ttft_ms' => 'Ø Erste Ausgabe (ms)', 'avg_tokens_per_second' => 'Ø Tokens / Sekunde', 'fallback_rate' => 'Fallback-Rate', 'input_tokens' => 'Eingabe-Tokens', 'output_tokens' => 'Ausgabe-Tokens'];
@endphp
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach($telemetry as $label => $value)<x-ui.stat :label="$metricLabels[$label] ?? str_replace('_', ' ', $label)" :value="in_array($label, ['success_rate', 'fallback_rate']) ? $value.'%' : $value" />@endforeach
</div>
<x-ui.tabs id="admin-telemetry" :tabs="['trend' => 'Verlauf & Export', 'models' => 'Modell-Telemetrie', 'ranking' => 'Benchmark']" active="trend">
    <x-ui.tab-panel name="trend">
<x-ui.panel title="Verlauf (30 T)">
    <svg viewBox="0 0 560 140" class="mt-3 w-full" preserveAspectRatio="none" role="img" aria-label="Läufe, Kosten und Erfolgsrate über 30 Tage">
        <line x1="0" y1="130" x2="560" y2="130" stroke="rgba(148,197,236,0.15)"/>
        @foreach(($telemetryCharts['bars'] ?? []) as $b)<rect x="{{ $b['x'] }}" y="{{ $b['y'] }}" width="{{ $b['w'] }}" height="{{ $b['h'] }}" fill="rgba(34,211,238,0.5)"><title>{{ $b['runs'] }} Läufe</title></rect>@endforeach
        @if(!empty($telemetryCharts['sr_points']))<polyline points="{{ $telemetryCharts['sr_points'] }}" fill="none" stroke="rgb(52,211,153)" stroke-width="1.5"/>@endif
        @if(!empty($telemetryCharts['cost_points']))<polyline points="{{ $telemetryCharts['cost_points'] }}" fill="none" stroke="rgb(245,158,11)" stroke-width="1.5"/>@endif
    </svg>
    <div class="mt-1 flex gap-4 text-xs text-slate-500"><span><span class="inline-block h-2 w-2 rounded-sm" style="background:rgba(34,211,238,.5)"></span> Läufe</span><span><span class="inline-block h-2 w-2 rounded-sm" style="background:rgb(52,211,153)"></span> Erfolgsrate</span><span><span class="inline-block h-2 w-2 rounded-sm" style="background:rgb(245,158,11)"></span> Kosten</span></div>
</x-ui.panel>
<div class="mt-6 flex gap-3">
<x-ui.button href="{{ route('dashboard.telemetry.export',['format'=>'csv']) }}">CSV Export</x-ui.button>
<x-ui.button variant="secondary" href="{{ route('dashboard.telemetry.export',['format'=>'jsonl']) }}">JSONL Export</x-ui.button>
</div>
</x-ui.tab-panel>
    <x-ui.tab-panel name="models">
<x-ui.panel title="Modell-Telemetrie (30 T)">
<x-ui.table>
<thead class="text-slate-500">
<tr>
<th>Modell</th>
<th>Task</th>
<th>Runs</th>
<th>Erfolg</th>
<th>Tempo</th>
<th>Kosten</th>
</tr>
</thead>
<tbody>@foreach($modelTelemetry as $item)<tr>
<td class="py-2 text-cyan-100">{{ $item->model_id }}</td>
<td>{{ $item->task_type }}</td>
<td>{{ $item->runs }}</td>
<td>{{ number_format($item->success_rate*100,1) }}%</td>
<td>{{ round($item->avg_latency_ms) }} ms</td>
<td>${{ number_format($item->total_cost,6) }}</td>
</tr>@endforeach</tbody>
</x-ui.table>
</x-ui.panel>
</x-ui.tab-panel>
    <x-ui.tab-panel name="ranking">
<x-ui.panel title="Modell-Benchmark (gemessenes Ranking)">
<x-ui.table>
<thead class="text-slate-500">
<tr>
<th>Modell</th>
<th>Task</th>
<th>Läufe</th>
<th>Score</th>
<th>Erfolg</th>
<th>Ø Latenz</th>
<th>Kosten/Erfolg</th>
</tr>
</thead>
<tbody>@forelse($modelRankings as $r)<tr>
<td class="py-2 text-cyan-100">{{ $r->model_id }}</td>
<td>{{ $r->task_type }}</td>
<td>{{ $r->sample_count }}</td>
<td>
<div class="flex items-center gap-2">
<div class="h-1.5 w-16 rounded bg-slate-800">
<div class="h-1.5 rounded" style="width:{{ round(min(1,max(0,$r->score))*100) }}%;background:rgba(34,211,238,.7)"></div></div><span>{{ number_format($r->score,3) }}</span></div></td>
<td>{{ number_format(($r->success_rate ?? 0)*100,1) }}%</td>
<td>{{ round($r->avg_latency_ms) }} ms</td>
<td>${{ number_format($r->cost_per_success ?? 0,6) }}</td>
</tr>@empty<tr>
<td colspan="7" class="py-3 text-slate-500">Noch keine Rankings (mind. einige Läufe nötig).</td>
</tr>@endforelse</tbody>
</x-ui.table>
</x-ui.panel>
</x-ui.tab-panel>
</x-ui.tabs>
