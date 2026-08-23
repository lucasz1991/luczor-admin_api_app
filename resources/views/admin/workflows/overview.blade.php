    {{-- ══════════════════════ ÜBERSICHT (Liste) ══════════════════════ --}}
    @php
        $wfActive = $workflowDefinitions->where('status', 'active')->count();
        $wfRunsTotal = $workflowDefinitions->sum('runs_count');
    @endphp
    <div class="mb-4 flex flex-wrap items-center gap-1.5 text-[11px]">
        <span class="rounded-md bg-slate-400/10 px-2 py-1 text-slate-300"><span class="opacity-75">Workflows</span> <b class="tabular-nums">{{ $workflowDefinitions->count() }}</b></span>
        <span class="rounded-md bg-emerald-400/10 px-2 py-1 text-emerald-200"><span class="opacity-75">Aktiv</span> <b class="tabular-nums">{{ $wfActive }}</b></span>
        <span class="rounded-md bg-slate-400/10 px-2 py-1 text-slate-300"><span class="opacity-75">Läufe</span> <b class="tabular-nums">{{ $wfRunsTotal }}</b></span>
        <span class="rounded-md bg-amber-400/10 px-2 py-1 text-amber-200"><span class="opacity-75">Task-Typen</span> <b class="tabular-nums">{{ count($taskCatalog) }}</b></span>
    </div>

    <section class="luczor-card overflow-visible p-5">
        <div class="flex items-center justify-between gap-3"><h2 class="font-semibold">Workflows</h2>
            <span class="text-xs text-slate-500">Board öffnen zum Bearbeiten mit Listen, Tasks &amp; Routen</span></div>
        <div class="mt-4 overflow-x-auto">
        <table class="min-w-full text-left text-sm"><thead class="text-xs uppercase text-slate-500"><tr><th class="pb-2">Workflow</th><th class="pb-2">Daten</th><th class="pb-2">Status</th><th class="pb-2">v</th><th class="pb-2"></th></tr></thead>
        <tbody class="divide-y divide-slate-800">
        @forelse($workflowDefinitions as $wf)
            @php $stepCount = count($wf->definition['steps'] ?? []); @endphp
            <tr class="hover:bg-slate-900/30">
                <td class="py-2.5 pr-3">
                    <div class="flex items-center gap-2">
                        <a class="font-semibold text-cyan-100 hover:text-cyan-300" href="{{ route('admin.page', ['page' => 'workflows', 'wf' => $wf->id]) }}">{{ $wf->name }}</a>
                        @if($wf->is_edit_locked)
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-amber-400/20 text-amber-300" title="{{ $wf->is_included ? 'In anderem Workflow eingebettet' : 'Edit-Lock aktiv' }}">
                                <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd"/></svg>
                            </span>
                        @endif
                    </div>
                </td>
                <td class="py-2.5 pr-3">
                    <div class="flex flex-wrap gap-1 text-[10px] font-semibold">
                        <span class="rounded-full bg-blue-400/10 px-2 py-0.5 text-blue-200 ring-1 ring-blue-400/30" title="Schritte">S {{ $stepCount }}</span>
                        <span class="rounded-full bg-slate-400/10 px-2 py-0.5 text-slate-300 ring-1 ring-slate-400/30" title="Läufe">B {{ $wf->runs_count }}</span>
                        <span class="rounded-full bg-emerald-400/10 px-2 py-0.5 text-emerald-200 ring-1 ring-emerald-400/30" title="Erfolgreich">OK {{ $wf->successful_runs_count }}</span>
                        <span class="rounded-full bg-rose-400/10 px-2 py-0.5 text-rose-200 ring-1 ring-rose-400/30" title="Fehlgeschlagen">F {{ $wf->failed_runs_count }}</span>
                    </div>
                </td>
                <td class="py-2.5 pr-3"><span class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1 {{ $wfStatusBadge($wf->status) }}">{{ $wfStatusLabel($wf->status) }}</span></td>
                <td class="py-2.5 pr-3 text-slate-400">{{ $wf->version }}</td>
                <td class="py-2.5">
                    <div class="flex items-center justify-end gap-2">
                        <a class="luczor-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.page', ['page' => 'workflows', 'wf' => $wf->id]) }}">Board</a>
                        <form method="POST" action="{{ route('dashboard.workflows.start', $wf) }}">@csrf<button class="luczor-btn-secondary !px-3 !py-1.5 text-xs" @if($wf->status !== 'active') disabled title="Workflow ist deaktiviert" @endif>Start</button></form>
                        <details class="relative">
                            <summary class="cursor-pointer list-none rounded border border-slate-700 px-2 py-1 text-slate-300 hover:bg-slate-800" aria-label="Aktionen für {{ $wf->name }}">⋮</summary>
                            <div class="absolute right-0 z-20 mt-1 w-52 rounded border border-slate-700 bg-slate-950 p-1 shadow-xl">
                                <form method="POST" action="{{ route('dashboard.workflows.duplicate', $wf) }}">@csrf<button class="block w-full rounded px-3 py-2 text-left text-xs text-cyan-100 hover:bg-cyan-400/10">Duplizieren</button></form>
                                <form method="POST" action="{{ route('dashboard.workflows.toggle', $wf) }}">@csrf<button class="block w-full rounded px-3 py-2 text-left text-xs text-cyan-100 hover:bg-cyan-400/10">{{ $wf->status === 'active' ? 'Deaktivieren' : 'Aktivieren' }}</button></form>
                                @unless($wf->is_included)
                                    <form method="POST" action="{{ route('dashboard.workflows.lock', $wf) }}">@csrf<button class="block w-full rounded px-3 py-2 text-left text-xs text-cyan-100 hover:bg-cyan-400/10">{{ $wf->is_locked ? 'Entsperren' : 'Sperren (Edit-Lock)' }}</button></form>
                                @endunless
                                <a class="block rounded px-3 py-2 text-xs text-cyan-100 hover:bg-cyan-400/10" href="{{ route('dashboard.workflows.export', $wf) }}">Export (JSON)</a>
                                @unless($wf->is_edit_locked)
                                    <form class="border-t border-slate-800 pt-1" method="POST" action="{{ route('dashboard.workflows.destroy', $wf) }}" onsubmit="return confirm('Workflow „{{ $wf->name }}" wirklich löschen?');">@csrf @method('DELETE')<button class="block w-full rounded px-3 py-2 text-left text-xs text-rose-300 hover:bg-rose-400/10">Löschen</button></form>
                                @endunless
                            </div>
                        </details>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-4 text-slate-500">Noch keine Workflows — unten anlegen oder importieren.</td></tr>
        @endforelse
        </tbody></table>
        </div>
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="luczor-card p-5"><h2 class="font-semibold">Neuer Workflow</h2>
            <p class="mt-1 text-xs text-slate-500">Legt einen Start-Workflow an — danach im Board mit Listen &amp; Tasks ausbauen.</p>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.workflows.store') }}">@csrf
                <input class="luczor-input" name="name" placeholder="Name" required maxlength="160">
                <details><summary class="cursor-pointer text-xs text-slate-500">JSON-Definition (optional, Experten)</summary>
                    <textarea class="luczor-input mt-2 font-mono text-xs" name="definition_json" rows="6">{"lists":[{"key":"liste-1","name":"Ablauf"}],"steps":[{"key":"start","type":"manual","payload":{"title":"Start","list":"liste-1"},"routes":{"success":{"type":"end"}}}]}</textarea>
                </details>
                <button class="luczor-btn">Anlegen &amp; validieren</button>
            </form>
            <form class="mt-4 flex items-center gap-2 border-t border-slate-800 pt-4" method="POST" action="{{ route('dashboard.workflows.import') }}" enctype="multipart/form-data">@csrf
                <input class="luczor-input" type="file" name="file" accept="application/json,.json" required>
                <button class="luczor-btn-secondary shrink-0">Import</button>
            </form>
        </section>
        <section class="luczor-card p-5"><h2 class="font-semibold">Freigegebene Task-Bibliothek</h2>
            <p class="mt-1 text-xs text-slate-500">Nur diese Task-Keys sind in Definitionen erlaubt — im Board per Drag &amp; Drop verfügbar.</p>
            <div class="mt-3 flex flex-wrap gap-2">@foreach($taskCatalog as $task)<span class="rounded border border-slate-800 px-2 py-1 text-xs {{ $task['allowed_in_definition'] ? 'text-cyan-100' : 'text-slate-500' }}" title="{{ $task['runner'] }} · {{ $task['kind'] }}{{ $task['allowed_in_definition'] ? '' : ' (geplant)' }}">{{ $task['key'] }}</span>@endforeach</div>
        </section>
    </div>

    <section class="mt-6 luczor-card p-5"><h2 class="font-semibold">Vorlagen</h2>
        <p class="mt-1 text-xs text-slate-500">Katalog-hydrierte Start-Workflows — anlegen und im Board anpassen.</p>
        <div class="mt-3 grid gap-3 md:grid-cols-3">
            @foreach($workflowTemplates as $tplKey => $tpl)
                <div class="flex flex-col rounded border border-slate-800 p-4">
                    <b class="text-cyan-100">{{ $tpl['name'] }}</b>
                    <p class="mt-1 flex-1 text-xs text-slate-400">{{ $tpl['description'] }}</p>
                    <div class="mt-2 flex flex-wrap gap-1">
                        @foreach(array_slice(array_column($tpl['definition']['steps'], 'type'), 0, 6) as $tplType)
                            <span class="rounded border border-slate-800 px-1.5 py-0.5 font-mono text-[9px] text-slate-500">{{ $tplType }}</span>
                        @endforeach
                    </div>
                    <form class="mt-3" method="POST" action="{{ route('dashboard.workflows.template') }}">@csrf
                        <input type="hidden" name="template" value="{{ $tplKey }}">
                        <button class="luczor-btn-secondary w-full !px-3 !py-1.5 text-xs">Anlegen &amp; im Board öffnen</button>
                    </form>
                </div>
            @endforeach
        </div>
    </section>

    <section class="mt-6 luczor-card overflow-x-auto p-5"><h2 class="font-semibold">Letzte Läufe</h2>
        <table class="mt-4 min-w-full text-left text-xs"><thead class="text-slate-500"><tr><th class="pb-1">Workflow</th><th class="pb-1">Status</th><th class="pb-1">Dauer</th><th class="pb-1">Gestartet</th><th class="pb-1"></th></tr></thead>
        <tbody>@forelse($workflowRuns as $run)<tr class="border-t border-slate-800">
            <td class="py-2 text-cyan-100">{{ $run->definition?->name ?? '—' }}</td>
            <td><span class="rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 {{ $wfStatusBadge($run->status) }}">{{ $wfStatusLabel($run->status) }}</span></td>
            <td>{{ $run->duration_ms ? round($run->duration_ms / 1000, 1).' s' : '—' }}</td>
            <td>{{ optional($run->started_at)->format('d.m. H:i') ?? '—' }}</td>
            <td class="text-right"><a class="text-cyan-200 hover:text-cyan-100" href="{{ route('admin.page', ['page' => 'workflows', 'run' => $run->id]) }}">Ansehen →</a></td>
        </tr>@empty<tr><td colspan="5" class="py-3 text-slate-500">Noch keine Läufe.</td></tr>@endforelse</tbody></table>
    </section>

