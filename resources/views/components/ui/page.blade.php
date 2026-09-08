{{-- Adapted from RailTime ui/page-header: named actions, bounded copy and shared page rhythm. --}}
@props(['title', 'eyebrow' => null, 'description' => null])
<div {{ $attributes->class('ui-page') }} data-ui-page>
    <header class="ui-page-header">
        <div class="ui-page-header__copy">
            @if($eyebrow)<span class="ui-eyebrow">{{ $eyebrow }}</span>@endif
            <h1>{{ $title }}</h1>
            @if($description)<p>{{ $description }}</p>@endif
        </div>
        @isset($actions)<div class="ui-page-header__actions">{{ $actions }}</div>@endisset
    </header>
    {{ $slot }}
</div>
