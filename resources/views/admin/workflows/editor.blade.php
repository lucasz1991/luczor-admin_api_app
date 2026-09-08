    {{-- ══════════════════════ BOARD-EDITOR ══════════════════════ --}}
    <x-ui.panel>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex w-full min-w-0 items-center gap-3 lg:w-auto">
                <x-ui.button variant="secondary" class="shrink-0" href="{{ route('admin.page', 'workflows') }}" title="Zur Übersicht">← Übersicht</x-ui.button>
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <input data-ed-name class="luczor-input !mt-0 w-full max-w-72 !bg-transparent !border-transparent px-0 text-lg font-semibold text-white focus:!border-slate-700 focus:!bg-slate-950/70" aria-label="Workflow-Name" value="{{ $workflowEditing->name }}" maxlength="160" @if($workflowEditing->is_edit_locked) disabled @endif>
                        @if($workflowEditing->is_edit_locked)
                            <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-400/20 text-amber-300" title="{{ $workflowEditing->is_included ? 'In anderem Workflow eingebettet' : 'Edit-Lock aktiv' }}">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd"/></svg>
                            </span>
                        @endif
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px]">
                        <span class="rounded-md bg-slate-400/10 px-2 py-0.5 text-slate-300"><span class="opacity-75">Version</span> <b class="tabular-nums">{{ $workflowEditing->version }}</b></span>
                        <span class="rounded-md bg-blue-400/10 px-2 py-0.5 text-blue-200"><span class="opacity-75">Listen</span> <b data-ed-count-lists class="tabular-nums">0</b></span>
                        <span class="rounded-md bg-amber-400/10 px-2 py-0.5 text-amber-200"><span class="opacity-75">Tasks</span> <b data-ed-count-cards class="tabular-nums">0</b></span>
                        <span class="rounded-md px-2 py-0.5 ring-1 {{ $wfStatusBadge($workflowEditing->status) }}">{{ $wfStatusLabel($workflowEditing->status) }}</span>
                        <span data-ed-dirty class="hidden rounded-md bg-amber-400/10 px-2 py-0.5 font-semibold text-amber-200">ungespeichert</span>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button variant="secondary" data-ed-library-toggle type="button">Task-Bibliothek</x-ui.button>
                <form method="POST" action="{{ route('dashboard.workflows.start', $workflowEditing) }}">@csrf<input type="hidden" name="sandbox" value="1"><button class="luczor-btn-secondary" @if($workflowEditing->status !== 'active') disabled title="Workflow ist deaktiviert" @endif title="Simuliert mutierende/Geräte-Tasks">Sandbox</button></form>
                <form method="POST" action="{{ route('dashboard.workflows.start', $workflowEditing) }}">@csrf<button class="luczor-btn-secondary" @if($workflowEditing->status !== 'active') disabled title="Workflow ist deaktiviert" @endif>Testlauf</button></form>
                <x-ui.button variant="secondary" href="{{ route('dashboard.workflows.export', $workflowEditing) }}">Export</x-ui.button>
                @unless($workflowEditing->is_edit_locked)<x-ui.button variant="primary" data-ed-save disabled type="button">Speichern</x-ui.button>@endunless
            </div>
        </div>
        @if($workflowEditing->is_edit_locked)
            <div class="mt-4 rounded border border-amber-400/30 bg-amber-400/10 px-4 py-2 text-sm text-amber-100">
                Dieser Workflow ist schreibgeschützt{{ $workflowEditing->is_included ? ', weil er in einem anderen Workflow eingebettet ist' : ' (Edit-Lock)' }}. Zum Bearbeiten duplizieren{{ $workflowEditing->is_included ? '' : ' oder über die Übersicht entsperren' }}.
            </div>
        @endif
        <div data-ed-dag-note class="mt-4 hidden rounded border border-amber-400/30 bg-amber-400/10 px-4 py-2 text-xs text-amber-100">
            Dieser Workflow enthält manuell gesetzte Abhängigkeiten (paralleles DAG-Layout). Karten mit <b>DAG</b>-Markierung behalten ihre Abhängigkeiten — Umsortieren im Board ändert nur die übrigen Karten.
        </div>
        <div data-ed-error class="mt-4 hidden rounded border border-rose-400/30 bg-rose-400/10 px-4 py-2 text-sm text-rose-100"></div>
    </x-ui.panel>

    <x-ui.panel data-ed-shell class="mt-6">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 px-4 py-2.5">
            <div class="text-xs text-slate-500">Aufgaben als Listen, Tasks als Karten — Karten aus der Bibliothek auf eine Liste ziehen.</div>
            <div class="flex items-center gap-3 text-[11px] text-slate-400">
                <span class="flex items-center gap-1.5"><span class="inline-block h-0.5 w-5 rounded bg-emerald-400"></span> Erfolg</span>
                <span class="flex items-center gap-1.5"><span class="inline-block h-0.5 w-5 rounded bg-rose-400" style="background-image:linear-gradient(90deg,currentColor 60%,transparent 60%);background-size:8px 2px"></span> Fehler</span>
                <button data-ed-routes-toggle class="rounded border border-slate-700 px-2 py-1 hover:bg-slate-800">Routen ausblenden</button>
                <button data-ed-fullscreen class="rounded border border-slate-700 px-2 py-1 hover:bg-slate-800">Vollbild</button>
            </div>
        </div>
        <div data-ed-surface class="relative overflow-x-auto overflow-y-hidden" style="min-height:60vh">
            <svg data-ed-svg class="pointer-events-none absolute left-0 top-0 z-10" width="0" height="0">
                <defs>
                    <marker id="wf-arrow-green" markerWidth="7" markerHeight="7" refX="6" refY="3.5" orient="auto"><path d="M0,0 L7,3.5 L0,7 Z" fill="#34d399"/></marker>
                    <marker id="wf-arrow-red" markerWidth="7" markerHeight="7" refX="6" refY="3.5" orient="auto"><path d="M0,0 L7,3.5 L0,7 Z" fill="#fb7185"/></marker>
                </defs>
                <g data-ed-svg-lines></g>
            </svg>
            <div data-ed-board class="flex items-start gap-8 px-5 pb-8 pt-10" style="min-height:60vh"></div>
        </div>
    </x-ui.panel>

    {{-- Task-Bibliothek (Slide-in rechts) --}}
    <button data-ed-library-edge class="fixed right-0 top-1/2 z-40 -translate-y-1/2 rounded-l-xl border border-r-0 border-cyan-400/30 bg-slate-950 px-2 py-4 text-xs font-semibold text-cyan-200 shadow-none hover:bg-slate-900" style="writing-mode:vertical-rl">Task-Bibliothek</button>
    <aside data-ed-library class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md translate-x-full flex-col border-l border-slate-800 bg-slate-950 shadow-none transition-transform duration-300 ease-[cubic-bezier(0.32,0.72,0,1)]">
        <div class="flex items-center justify-between border-b border-slate-800 px-4 py-3">
            <div><b class="text-cyan-100">Task-Bibliothek</b><div class="text-[11px] text-slate-500">Karten per Drag &amp; Drop auf eine Liste ziehen</div></div>
            <button data-ed-library-close class="rounded px-2 py-1 text-slate-400 hover:bg-slate-800">✕</button>
        </div>
        <div data-ed-library-tabs class="flex gap-1 overflow-x-auto border-b border-slate-800 px-3 pt-2"></div>
        <div data-ed-library-cards class="flex-1 space-y-2 overflow-y-auto p-3"></div>
    </aside>

    {{-- Karten-/Listen-Modal --}}
    <div data-ed-modal class="fixed inset-0 z-[60] hidden items-start justify-center overflow-y-auto bg-black/60 p-4 pt-[6vh]">
        <div class="w-full max-w-2xl rounded-lg border border-slate-700 bg-slate-950 shadow-none">
            <div class="flex items-center justify-between border-b border-slate-800 px-5 py-3">
                <b data-ed-modal-title class="text-cyan-100">Task bearbeiten</b>
                <button data-ed-modal-close class="rounded px-2 py-1 text-slate-400 hover:bg-slate-800">✕</button>
            </div>
            <div data-ed-modal-body class="max-h-[70vh] overflow-y-auto px-5 py-4"></div>
            <div class="flex justify-end gap-2 border-t border-slate-800 px-5 py-3">
                <x-ui.button variant="secondary" data-ed-modal-cancel type="button">Abbrechen</x-ui.button>
                <x-ui.button variant="primary" data-ed-modal-save type="button">Übernehmen</x-ui.button>
            </div>
        </div>
    </div>

    <x-ui.panel class="mt-6">
        <details>
            <summary class="cursor-pointer text-sm font-semibold text-cyan-200">Experten-Modus: JSON-Definition</summary>
            <x-ui.textarea data-ed-json aria-label="Workflow-Definition als JSON" class="luczor-input mt-3 font-mono text-xs" rows="12" spellcheck="false"></x-ui.textarea>
            <div class="mt-2 flex gap-2">
                <x-ui.button variant="secondary" data-ed-json-apply type="button">JSON ins Board übernehmen</x-ui.button>
                <span class="self-center text-xs text-slate-500">Änderungen wirken erst nach „Speichern".</span>
            </div>
        </details>
    </x-ui.panel>


    @include('admin.workflows.editor-runtime')

