<x-ui.tabs id="admin-agents" :tabs="['teams' => 'Teams', 'research' => 'Modellrecherche', 'quality' => 'Rollenvergleich', 'runs' => 'Agent-Läufe', 'audit' => 'Ereignisse']" active="teams">
    <x-ui.tab-panel name="teams">@include('admin.agent-teams')</x-ui.tab-panel>
    <x-ui.tab-panel name="research">@include('admin.pages.agent-research')</x-ui.tab-panel>
    <x-ui.tab-panel name="quality">@include('admin.pages.agent-ranking')</x-ui.tab-panel>
    <x-ui.tab-panel name="runs">
<x-ui.panel title="Agent-Läufe">
    <x-ui.table>
<thead class="text-slate-500">
<tr>
<th>Ziel / Task</th>
<th>Status</th>
<th>Modell</th>
<th>Aufgaben</th>
<th>Gestartet</th>
</tr>
</thead>
    <tbody>@forelse($agentRuns as $run)<tr>
<td class="py-2"><span class="text-cyan-100">{{ \Illuminate\Support\Str::limit($run->goal ?? $run->task_type, 60) }}</span>
<div class="text-slate-500">{{ $run->task_type }}</div></td>
<td>{{ $run->status }}</td>
<td>{{ $run->model_id ?? '—' }}</td>
<td>{{ $run->tasks_count }}</td>
<td>{{ optional($run->started_at)->format('d.m. H:i') ?? '—' }}</td>
</tr>@empty<tr>
<td colspan="5" class="py-3 text-slate-500">Noch keine Agent-Läufe.</td>
</tr>@endforelse</tbody>
</x-ui.table>
</x-ui.panel>
</x-ui.tab-panel>
    <x-ui.tab-panel name="audit">
<x-ui.panel title="Ereignisprotokoll (Audit)">
    <x-ui.table>
<thead class="text-slate-500">
<tr>
<th>Ereignis</th>
<th>Tool</th>
<th>Ergebnis</th>
<th>Risiko</th>
<th>Zeit</th>
</tr>
</thead>
    <tbody>@forelse($agentEvents as $e)<tr>
<td class="py-2 text-cyan-100">{{ $e->event_type }}</td>
<td>{{ $e->tool ?? '—' }}</td>
<td class="{{ $e->outcome==='failed'?'text-rose-300':($e->outcome==='completed'?'text-emerald-300':'') }}">{{ $e->outcome ?? '—' }}</td>
<td>{{ $e->risk_level ?? '—' }}</td>
<td>{{ optional($e->created_at)->format('d.m. H:i:s') ?? '—' }}</td>
</tr>@empty<tr>
<td colspan="5" class="py-3 text-slate-500">Noch keine Ereignisse.</td>
</tr>@endforelse</tbody>
</x-ui.table>
</x-ui.panel>
</x-ui.tab-panel>
</x-ui.tabs>
