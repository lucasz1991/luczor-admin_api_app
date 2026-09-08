@props(['type' => 'button', 'variant' => 'primary', 'href' => null, 'arrow' => false])
@php($tone = in_array($variant, ['primary','secondary','danger','ghost']) ? $variant : 'primary')
@if($href)
<a href="{{ $href }}" {{ $attributes->class('ui-button ui-button--'.$tone) }}><span>{{ $slot }}</span>@if($arrow)<span class="ui-button__arrow" aria-hidden="true">↗</span>@endif</a>
@else
<button type="{{ $type }}" {{ $attributes->class('ui-button ui-button--'.$tone) }}><span>{{ $slot }}</span>@if($arrow)<span class="ui-button__arrow" aria-hidden="true">↗</span>@endif</button>
@endif
