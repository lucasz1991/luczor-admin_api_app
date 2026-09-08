{{-- Adapted from RailTime components/ui/badge.blade.php for Luczor's dark palette. --}}
@props(['color' => 'slate'])
@php
    $map = [
        'amber' => 'bg-amber-500/10 text-amber-300 ring-amber-500/30',
        'sky' => 'bg-sky-500/10 text-sky-300 ring-sky-500/30',
        'purple' => 'bg-purple-500/10 text-purple-300 ring-purple-500/30',
        'green' => 'bg-emerald-500/10 text-emerald-300 ring-emerald-500/30',
        'red' => 'bg-rose-500/10 text-rose-300 ring-rose-500/30',
        'slate' => 'bg-slate-500/10 text-slate-300 ring-slate-500/30',
    ];
@endphp
<span {{ $attributes->class('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset '.($map[$color] ?? $map['slate'])) }}>{{ $slot }}</span>
