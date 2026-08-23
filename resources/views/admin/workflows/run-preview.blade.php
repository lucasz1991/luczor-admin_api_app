    {{-- ══════════════════════ RUN-PREVIEW ══════════════════════ --}}
    <section class="luczor-card p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="text-[10px] font-semibold uppercase tracking-[.2em] text-cyan-300/70">Workflow-Vorschau</div>
                <h2 class="mt-1 text-lg font-semibold text-white">{{ $workflowPreviewRun->definition?->name ?? 'Workflow' }}</h2>
                <div class="mt-1 text-xs text-slate-500">Run #{{ $workflowPreviewRun->id }} · <span data-rp-duration>—</span>@if($workflowPreviewRun->sandbox) · <span class="rounded bg-amber-400/10 px-1.5 py-0.5 font-semibold text-amber-200">Sandbox (simuliert)</span>@endif · <span class="font-mono text-[10px]">{{ $workflowPreviewRun->public_id }}</span></div>
            </div>
            <div class="flex items-center gap-2">
                <span data-rp-badge class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $wfStatusBadge($workflowPreviewRun->status) }}">{{ $wfStatusLabel($workflowPreviewRun->status) }}</span>
                <button data-rp-cancel class="luczor-btn-secondary hidden text-rose-200 !border-rose-400/30 hover:!bg-rose-400/10">Abbrechen</button>
                <a data-rp-json download="workflow-run-{{ $workflowPreviewRun->id }}.json" class="luczor-btn-secondary" href="#">Run-JSON</a>
                @if($workflowPreviewRun->workflow_definition_id)<a class="luczor-btn-secondary" href="{{ route('admin.page', ['page' => 'workflows', 'wf' => $workflowPreviewRun->workflow_definition_id]) }}">Board öffnen</a>@endif
                <a class="luczor-btn-secondary" href="{{ route('admin.page', 'workflows') }}">Zur Liste</a>
            </div>
        </div>

        {{-- Kompakter Schritt-Streifen (Minimap) --}}
        <div data-rp-strip class="mt-5 flex items-stretch gap-1 overflow-x-auto pb-2"></div>
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-[1.4fr_1fr]">
        <section class="luczor-card p-5">
            <h2 class="font-semibold">Schritte &amp; Ergebnisse</h2>
            <div data-rp-steps class="mt-3 space-y-2"></div>
        </section>
        <div class="space-y-6">
            <section class="luczor-card p-5">
                <h2 class="font-semibold">Ablauf <span class="text-xs text-slate-500">(neueste zuerst)</span></h2>
                <div data-rp-timeline class="mt-3 max-h-80 space-y-1.5 overflow-y-auto text-xs"></div>
            </section>
            <section class="luczor-card p-5" data-rp-artifacts-card hidden>
                <h2 class="font-semibold">Artefakte</h2>
                <div data-rp-artifacts class="mt-3 grid gap-2 md:grid-cols-2"></div>
            </section>
            <section class="luczor-card p-5" data-rp-output-card hidden>
                <h2 class="font-semibold">Ausgabe</h2>
                <pre data-rp-output class="mt-3 max-h-64 overflow-auto rounded border border-slate-800 bg-slate-950/70 p-3 font-mono text-[11px] leading-relaxed text-slate-300"></pre>
            </section>
        </div>
    </div>


    @include('admin.workflows.run-preview-runtime')

