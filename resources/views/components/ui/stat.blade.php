{{-- RailTime dashboard/stat-card definition-list structure. --}}
@props(['label', 'value', 'hint' => null, 'tone' => 'neutral'])
<article {{ $attributes->class('ui-stat') }}>
    <dl><dt>{{ $label }}</dt><dd>{{ $value }}</dd></dl>
    @if($hint)<p>{{ $hint }}</p>@endif
    @if(trim($slot))<div class="ui-stat__detail">{{ $slot }}</div>@endif
</article>
