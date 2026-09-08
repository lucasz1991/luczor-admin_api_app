@props(['title' => null, 'description' => null])
<section {{ $attributes->class('ui-panel') }} data-ui-panel>
    <div class="ui-panel__core">
        @if($title || $description || isset($actions))
        <header class="ui-panel__header">
            <div>@if($title)<h2>{{ $title }}</h2>@endif @if($description)<p>{{ $description }}</p>@endif</div>
            @isset($actions)<div class="ui-panel__actions">{{ $actions }}</div>@endisset
        </header>
        @endif
        <div class="ui-panel__body">{{ $slot }}</div>
    </div>
</section>
