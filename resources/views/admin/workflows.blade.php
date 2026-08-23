{{-- SOLL §14 P16 — Workflow-Manager nach AUF-Vorbild: Listen (Spalten) + Task-Karten,
     natives HTML5-Drag&Drop, Task-Bibliothek als Slide-in, Routen-Overlay (SVG),
     Run-Preview mit Live-Polling. Drei Modi: Liste / Board-Editor (?wf=) / Preview (?run=). --}}

@php
    $wfStatusBadge = fn (string $s) => match ($s) {
        'queued' => 'bg-slate-400/10 text-slate-300 ring-slate-400/30',
        'ready' => 'bg-cyan-400/10 text-cyan-200 ring-cyan-400/30',
        'running' => 'bg-blue-400/10 text-blue-200 ring-blue-400/30',
        'awaiting_approval' => 'bg-amber-400/10 text-amber-200 ring-amber-400/30',
        'completed', 'active' => 'bg-emerald-400/10 text-emerald-200 ring-emerald-400/30',
        'failed' => 'bg-rose-400/10 text-rose-200 ring-rose-400/30',
        'cancelled', 'skipped', 'disabled' => 'bg-slate-500/10 text-slate-400 ring-slate-500/30',
        default => 'bg-slate-400/10 text-slate-300 ring-slate-400/30',
    };
    $wfStatusLabel = fn (string $s) => match ($s) {
        'queued' => 'Wartet', 'ready' => 'Bereit', 'running' => 'Läuft',
        'awaiting_approval' => 'Freigabe nötig', 'completed' => 'Fertig', 'failed' => 'Fehler',
        'cancelled' => 'Abgebrochen', 'skipped' => 'Übersprungen',
        'active' => 'Aktiv', 'disabled' => 'Inaktiv', default => $s,
    };
@endphp

@if ($workflowPreviewRun)
    @include('admin.workflows.run-preview')
@elseif ($workflowEditing)
    @include('admin.workflows.editor')
@else
    @include('admin.workflows.overview')
@endif
