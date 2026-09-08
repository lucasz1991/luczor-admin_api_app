@props(['label' => 'Datentabelle'])
<div class="ui-table-scroll" role="region" aria-label="{{ $label }}" tabindex="0"><table {{ $attributes->class('ui-table') }}>{{ $slot }}</table></div>
