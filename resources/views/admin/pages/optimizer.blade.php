<x-ui.panel title="Persönlichkeit und Skills gemeinsam ausarbeiten">
    <p class="mt-2 text-sm text-slate-400">Der Grundentwurf spricht Deutsch, ist klar und freundlich und unterstützt Laravel, Livewire, Alpine.js, Tailwind CSS sowie lokale Diagnose. Alle Texte sind hier einsehbar und bearbeitbar. Aktive Prompt-Skills gelten für den jeweiligen Nutzer und globale Skills für alle; Workflows starten ausschließlich auf Anforderung.</p>
    <form class="mt-3" method="POST" action="{{ route('dashboard.assistant-defaults.store') }}">@csrf
        <x-ui.button class="" type="submit" variant="secondary">Grundentwurf ergänzen</x-ui.button>
        <p class="mt-2 text-xs text-slate-500">Ergänzt nur fehlende Einträge. Vorhandene Texte, deaktivierte Skills und eine bestehende Persönlichkeitsauswahl bleiben erhalten.</p>
    </form>
</x-ui.panel>
<div class="grid gap-6 lg:grid-cols-2">
    <x-ui.panel title="Prompt-Version veröffentlichen">
        <p class="mt-1 text-xs text-slate-500">Server-Prompt: luczor.system → Persönlichkeit → aktive Prompt-Skills → Use-Case → Rollen-Regeln → Verlauf. Die Desktop-App lädt Persönlichkeit und Skills auch für lokale Antworten.</p>
        <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.prompt-templates.store') }}">@csrf
            <x-ui.input class="" name="key" placeholder="Key (z. B. luczor.role.coder)" required aria-label="key" />
            <div class="grid gap-3 sm:grid-cols-3">
                <x-ui.input class="" name="role" placeholder="Rolle (chat|coder|planner|analyst|all)" aria-label="role" />
                <x-ui.input class="" name="task_type" placeholder="Task-Type (optional)" aria-label="task type" />
                <x-ui.input class="" name="priority" type="number" value="100" placeholder="Priorität" aria-label="priority" />
            </div>
            <x-ui.textarea class=" font-mono text-xs" name="body" rows="6" placeholder="Prompt-Text / Regel" required></x-ui.textarea>
            <x-ui.button class="" type="submit" variant="primary">Veröffentlichen (neue Version)</x-ui.button>
        </form>
    </x-ui.panel>
    <x-ui.panel title="Prompt-Vorlagen">
        <x-ui.table><thead class="text-slate-500"><tr><th>Key</th><th>v</th><th>Rolle</th><th>Prio</th><th>Status</th></tr></thead>
        <tbody>@foreach($promptTemplates as $t)<tr class="border-t border-slate-800"><td class="py-1.5 text-cyan-100">{{ $t->key }}</td><td>{{ $t->version }}</td><td>{{ $t->role ?? '—' }}</td><td>{{ $t->priority ?? '—' }}</td><td class="{{ $t->status==='active'?'text-emerald-300':'text-slate-500' }}">{{ $t->status }}</td></tr>@endforeach</tbody></x-ui.table>
    </x-ui.panel>
</div>
<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <x-ui.panel title="KI-Persönlichkeit anlegen">
        <p class="mt-1 text-xs text-slate-500">Wird direkt nach dem System-Prompt injiziert (globale Persönlichkeit).</p>
        <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.personas.store') }}">@csrf
            <x-ui.input class="" name="name" placeholder="Name (z. B. Sachlich-Knapp)" required aria-label="name" />
            <x-ui.textarea class=" font-mono text-xs" name="prompt" rows="5" placeholder="Persönlichkeits-Prompt / Tonalität" required></x-ui.textarea>
            <x-ui.button class="" type="submit" variant="primary">Speichern</x-ui.button>
        </form>
    </x-ui.panel>
    <x-ui.panel><div class="flex items-center justify-between"><h2 class="font-semibold">Persönlichkeiten</h2><form method="POST" action="{{ route('dashboard.personas.deactivate') }}">@csrf<x-ui.button class="" type="submit" variant="secondary">Keine aktiv</x-ui.button></form></div>
        <div class="mt-3 space-y-3">@forelse($personas as $p)
            <div class="rounded border border-slate-800 p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div><b class="text-cyan-100">{{ $p->name }}</b>@if($p->active)<span class="ml-2 rounded border border-emerald-400/30 px-1.5 text-[10px] text-emerald-200">aktiv</span>@endif<div class="font-mono text-[10px] text-slate-500">{{ $p->slug }}</div></div>
                    @unless($p->active)<form method="POST" action="{{ route('dashboard.personas.activate', $p) }}">@csrf<x-ui.button class="" type="submit" variant="secondary">Aktivieren</x-ui.button></form>@endunless
                </div>
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm text-cyan-200">Text ansehen und bearbeiten</summary>
                    <form class="mt-3 space-y-3" method="POST" action="{{ route('dashboard.personas.update', $p) }}">@csrf @method('PATCH')
                        <label class="block text-xs text-slate-400">Name<x-ui.input class="mt-1" name="name" value="{{ $p->name }}" required maxlength="120" aria-label="name" /></label>
                        <label class="block text-xs text-slate-400">Persönlichkeit und Tonalität<x-ui.textarea class=" mt-1 text-sm" name="prompt" rows="10" required maxlength="20000">{{ $p->prompt }}</x-ui.textarea></label>
                        <x-ui.button class="" type="submit" variant="primary">Änderungen speichern</x-ui.button>
                    </form>
                </details>
            </div>
        @empty<p class="text-xs text-slate-500">Noch keine Persönlichkeiten. Der Grundentwurf kann oben ergänzt werden.</p>@endforelse</div>
    </x-ui.panel>
</div>
@include('admin.optimizer-extras')
<x-ui.panel title="Netzwerk-Policies">
    <div class="mt-3 grid gap-3 md:grid-cols-3">@foreach($networkPolicies as $policy)<div class="rounded border border-slate-800 p-3"><b>{{ $policy->name }}</b><div class="text-xs text-slate-500">{{ $policy->key }}</div></div>@endforeach</div>
</x-ui.panel>
