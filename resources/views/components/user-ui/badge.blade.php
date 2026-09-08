{{-- RailTime-derived user badges share the Luczor UI tokens. --}}
@props(['color' => 'slate'])
@php($tone = match($color) { 'green' => 'success', 'red' => 'danger', 'amber' => 'warning', 'sky', 'purple' => 'info', default => 'neutral' })
<x-ui.badge :tone="$tone" {{ $attributes }}>{{ $slot }}</x-ui.badge>
