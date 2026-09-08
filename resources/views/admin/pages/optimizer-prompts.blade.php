<div class="grid gap-6 lg:grid-cols-2">
    <x-ui.panel title="Prompt-Version veröffentlichen">
        <p class="mt-1 text-xs text-slate-500">Server-Prompt: luczor.system → Persönlichkeit → aktive Prompt-Skills → Use-Case → Rollen-Regeln → Verlauf. Die Desktop-App lädt Persönlichkeit und Skills auch für lokale Antworten.</p>
        <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.prompt-templates.store') }}">@csrf
            <label class="ui-field">Prompt-Schlüssel
<x-ui.input name="key" placeholder="Key (z. B. luczor.role.coder)" required />
</label>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="ui-field">Rolle
<x-ui.input name="role" placeholder="Rolle (chat|coder|planner|analyst|all)" />
</label>
                <label class="ui-field">Aufgabentyp (optional)
<x-ui.input name="task_type" placeholder="Task-Type (optional)" />
</label>
                <label class="ui-field">Priorität
<x-ui.input name="priority" type="number" value="100" placeholder="Priorität" />
</label>
            </div>
            <label class="ui-field">Prompt-Text / Regel
<x-ui.textarea class="font-mono text-xs" name="body" rows="6" placeholder="Prompt-Text / Regel" required></x-ui.textarea>
</label>
            <x-ui.button type="submit" variant="primary">Veröffentlichen (neue Version)</x-ui.button>
        </form>
    </x-ui.panel>
    <x-ui.panel title="Prompt-Vorlagen">
        <x-ui.table>
<thead class="text-slate-500">
<tr>
<th>Key</th>
<th>v</th>
<th>Rolle</th>
<th>Prio</th>
<th>Status</th>
</tr>
</thead>
        <tbody>@foreach($promptTemplates as $t)<tr>
<td class="py-1.5 text-cyan-100">{{ $t->key }}</td>
<td>{{ $t->version }}</td>
<td>{{ $t->role ?? '—' }}</td>
<td>{{ $t->priority ?? '—' }}</td>
<td class="{{ $t->status==='active'?'text-emerald-300':'text-slate-500' }}">{{ $t->status }}</td>
</tr>@endforeach</tbody>
</x-ui.table>
    </x-ui.panel>
</div>
