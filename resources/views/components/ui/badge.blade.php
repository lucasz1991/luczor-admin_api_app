{{-- RailTime ui/badge semantic tone map, using Luczor tokens. --}}
@props(['tone' => 'neutral'])
@php($color = in_array($tone, ['neutral','success','warning','danger','info']) ? $tone : 'neutral')
<span {{ $attributes->class('ui-badge ui-badge--'.$color) }}>{{ $slot }}</span>
