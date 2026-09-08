    {{-- ══════════════════════ RUN-PREVIEW ══════════════════════ --}}
    <x-ui.panel>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="text-[10px] font-semibold uppercase tracking-[.2em] text-cyan-300/70">Workflow-Vorschau</div>
                <h2 class="mt-1 text-lg font-semibold text-white">{{ $workflowPreviewRun->definition?->name ?? 'Workflow' }}</h2>
                <div class="mt-1 text-xs text-slate-500">Run #{{ $workflowPreviewRun->id }} · <span data-rp-duration>—</span>@if($workflowPreviewRun->sandbox) · <span class="rounded bg-amber-400/10 px-1.5 py-0.5 font-semibold text-amber-200">Sandbox (simuliert)</span>@endif · <span class="font-mono text-[10px]">{{ $workflowPreviewRun->public_id }}</span></div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span data-rp-badge class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $wfStatusBadge($workflowPreviewRun->status) }}">{{ $wfStatusLabel($workflowPreviewRun->status) }}</span>
                <x-ui.button variant="secondary" data-rp-cancel class="hidden text-rose-200 !border-rose-400/30 hover:!bg-rose-400/10" type="button">Abbrechen</x-ui.button>
                <x-ui.button variant="secondary" data-rp-json download="workflow-run-{{ $workflowPreviewRun->id }}.json" href="#">Run-JSON</x-ui.button>
                @if($workflowPreviewRun->workflow_definition_id)<x-ui.button variant="secondary" href="{{ route('admin.page', ['page' => 'workflows', 'wf' => $workflowPreviewRun->workflow_definition_id]) }}">Board öffnen</x-ui.button>@endif
                <x-ui.button variant="secondary" href="{{ route('admin.page', 'workflows') }}">Zur Liste</x-ui.button>
            </div>
        </div>

        {{-- Kompakter Schritt-Streifen (Minimap) --}}
        <div data-rp-strip class="mt-5 flex items-stretch gap-1 overflow-x-auto pb-2"></div>
    </x-ui.panel>

    <x-ui.tabs id="workflow-run-preview" :tabs="['steps' => 'Schritte & Ergebnisse', 'timeline' => 'Verlauf', 'output' => 'Ausgaben & Dateien']" active="steps">
        <x-ui.tab-panel name="steps">
            <x-ui.panel title="Schritte & Ergebnisse" description="Status und Ergebnis jedes einzelnen Arbeitsschritts.">
                <div data-rp-steps class="space-y-3"></div>
            </x-ui.panel>
        </x-ui.tab-panel>
        <x-ui.tab-panel name="timeline">
            <x-ui.panel title="Verlauf" description="Die jüngsten Ereignisse stehen oben.">
                <div data-rp-timeline class="max-h-[36rem] space-y-3 overflow-y-auto text-sm"></div>
            </x-ui.panel>
        </x-ui.tab-panel>
        <x-ui.tab-panel name="output">
            <div class="space-y-6">
                <p class="text-sm text-slate-400">Bereitgestellte Dateien und Ausgaben erscheinen hier während des Laufs.</p>
                <x-ui.panel title="Dateien" data-rp-artifacts-card hidden>
                    <div data-rp-artifacts class="grid gap-4 md:grid-cols-2"></div>
                </x-ui.panel>
                <x-ui.panel title="Ausgabe" data-rp-output-card hidden>
                    <pre data-rp-output class="max-h-[36rem] overflow-auto rounded-xl bg-black/20 p-4 font-mono text-xs leading-relaxed text-slate-300"></pre>
                </x-ui.panel>
            </div>
        </x-ui.tab-panel>
    </x-ui.tabs>

    @include('admin.workflows.run-preview-runtime')

