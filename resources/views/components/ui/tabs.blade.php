{{-- RailTime accordion tabs contract, narrowed to keyboard tabs without carousel/persist dependencies. --}}
@props(['id', 'tabs' => [], 'active' => null, 'label' => 'Bereiche', 'forceActive' => false])
@php($initial = array_key_exists($active ?? '', $tabs) ? $active : array_key_first($tabs))
<div {{ $attributes->class('ui-tabs') }} x-data="luczorTabs({ id: @js($id), initial: @js($initial), names: @js(array_keys($tabs)), forceActive: @js($forceActive) })" data-ui-tabs="{{ $id }}" wire:ignore.self
     x-on:ui-tab-select.window="if ($event.detail?.id === tabsId) selectTab($event.detail.name)"
     x-on:hashchange.window="openHash()">
    <div class="ui-tabs__rail" role="tablist" aria-label="{{ $label }}" aria-orientation="horizontal"
         x-on:keydown.right.prevent.stop="moveTab(1)" x-on:keydown.left.prevent.stop="moveTab(-1)"
         x-on:keydown.home.prevent.stop="moveToBoundary(false)" x-on:keydown.end.prevent.stop="moveToBoundary(true)">
        @foreach($tabs as $name => $tab)
            <button type="button" class="ui-tab" role="tab" id="{{ $id }}-tab-{{ $name }}" aria-controls="{{ $id }}-panel-{{ $name }}"
                    data-ui-tab="{{ $name }}" x-on:click="selectTab(@js((string) $name))"
                    x-bind:aria-selected="openTab === @js((string) $name)" x-bind:tabindex="openTab === @js((string) $name) ? 0 : -1">
                {{ is_array($tab) ? $tab['label'] : $tab }}
            </button>
        @endforeach
    </div>
    <div class="ui-tabs__content">{{ $slot }}</div>
</div>
