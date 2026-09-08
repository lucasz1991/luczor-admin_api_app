<x-ui.tabs id="admin-costs" :tabs="['users' => 'Nach Benutzer', 'projects' => 'Nach Projekt', 'devices' => 'Nach Gerät']" active="users">
    <x-ui.tab-panel name="users">
<x-ui.panel title="Kosten je Benutzer">
        <x-ui.table>
            <thead class="text-xs uppercase text-slate-500">
<tr>
<th>User</th>
<th>Rolle</th>
<th>Läufe</th>
<th>Geschätzte Kosten</th>
<th>Letzter Lauf</th>
</tr>
</thead>
            <tbody>
            @forelse($userCostOverview as $row)
                <tr>
<td class="py-3"><b class="text-cyan-100">{{ $row->name }}</b>
<div class="font-mono text-xs text-slate-500">{{ $row->email }}</div></td>
<td>{{ $row->role }}</td>
<td>{{ $row->runs_count }}</td>
<td>${{ number_format((float) $row->estimated_cost_usd, 6) }}</td>
<td>{{ $row->last_run_at ?: '—' }}</td>
</tr>
            @empty
                <tr>
<td colspan="5" class="py-6 text-slate-500">Noch keine Kostenläufe.</td>
</tr>
            @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.panel>
</x-ui.tab-panel>
    <x-ui.tab-panel name="projects">
<x-ui.panel title="Projektkosten">
    <x-ui.table>
        <thead class="text-xs uppercase text-slate-500">
<tr>
<th>Projekt-ID</th>
<th>Läufe</th>
<th>Geschätzte Kosten</th>
<th>Letzter Lauf</th>
</tr>
</thead>
        <tbody>@forelse($projectCostOverview as $row)<tr>
<td class="py-3 font-mono text-xs text-cyan-100">{{ $row->project_id }}</td>
<td>{{ $row->runs_count }}</td>
<td>${{ number_format((float) $row->estimated_cost_usd, 6) }}</td>
<td>{{ $row->last_run_at ?: '—' }}</td>
</tr>@empty<tr>
<td colspan="4" class="py-6 text-slate-500">Noch keine Projektkosten.</td>
</tr>@endforelse</tbody>
    </x-ui.table>
</x-ui.panel>
</x-ui.tab-panel>
    <x-ui.tab-panel name="devices">
<x-ui.panel title="Geräte-Kosten">
        <div class="mt-3 space-y-2">@forelse($deviceCostOverview as $row)<div class="ui-record"><b class="font-mono text-xs text-cyan-100">{{ $row->client_id }}</b>
<div class="mt-1 text-xs text-slate-400">{{ $row->runs_count }} Läufe · ${{ number_format((float) $row->estimated_cost_usd, 6) }}</div></div>@empty<p class="text-xs text-slate-500">Noch keine Geräte-Läufe.</p>@endforelse</div>
    </x-ui.panel>
</x-ui.tab-panel>
</x-ui.tabs>
