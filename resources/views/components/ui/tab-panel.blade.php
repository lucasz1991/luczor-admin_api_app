@props(['name'])
@aware(['id' => 'section'])
<div {{ $attributes->class('ui-tab-panel') }} id="{{ $id }}-panel-{{ $name }}" role="tabpanel" aria-labelledby="{{ $id }}-tab-{{ $name }}"
     data-ui-tab-panel="{{ $name }}" tabindex="0" x-show="openTab === @js((string) $name)" x-cloak
     x-bind:inert="openTab !== @js((string) $name)" wire:ignore.self>
    {{ $slot }}
</div>
