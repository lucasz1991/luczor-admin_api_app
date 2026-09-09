@php
    abort_unless((int) $workflowEditing->user_id === (int) auth()->id(), 404);
    $workflowEditorManifest = public_path('workflow-editor/manifest.json');
    $workflowEditorAssets = (new \Illuminate\Foundation\Vite)->useHotFile(storage_path('workflow-editor.hot'));
@endphp

<x-ui.panel>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <p class="text-xs text-slate-400">Workflow bearbeiten</p>
            <h2 class="truncate text-lg font-semibold text-slate-100">{{ $workflowEditing->name }}</h2>
        </div>
        <x-ui.button variant="secondary" href="{{ route('admin.page', 'workflows') }}">Zur Übersicht</x-ui.button>
    </div>
    <p class="mt-3 text-sm text-slate-400">Definition und Simulation stehen hier bereit. Geräteausführung und automatische Reparaturfreigaben verwaltest du auf dem verbundenen Gerät.</p>
</x-ui.panel>

<div class="mt-5" data-luczor-workflow-editor data-state-url="{{ route('dashboard.workflows.editor.state', $workflowEditing) }}">
    <p role="status" class="rounded-xl border border-slate-700 bg-slate-900/60 p-5 text-sm text-slate-300">
        @if(is_file($workflowEditorManifest))
            Workfloweditor wird geladen …
        @else
            Die Editor-Oberfläche wird für diese Installation vorbereitet. Deine gespeicherten Workflows bleiben erhalten.
        @endif
    </p>
</div>
<noscript><p class="mt-3 text-sm text-amber-200">Bitte JavaScript aktivieren, um den Workfloweditor zu verwenden.</p></noscript>

@if(is_file($workflowEditorManifest))
    {{ $workflowEditorAssets(['src/workflow-editor-web.ts'], 'workflow-editor') }}
@endif