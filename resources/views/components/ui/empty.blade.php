@props(['title' => 'Noch keine Einträge'])
<div {{ $attributes->class('ui-empty') }}>
    <span class="ui-empty__mark" aria-hidden="true">—</span><h3>{{ $title }}</h3>
    @if(trim($slot))<div>{{ $slot }}</div>@endif
</div>
