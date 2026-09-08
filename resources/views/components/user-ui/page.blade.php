{{-- Compatibility adapter for the RailTime-derived profile modules. --}}
@props(['title', 'eyebrow' => null, 'description' => null, 'count' => null, 'backUrl' => null])
<x-ui.page :title="$title" :eyebrow="$eyebrow" :description="$description" {{ $attributes }}>
    <x-slot:actions>
        @if($backUrl)<x-ui.button :href="$backUrl" variant="secondary">← Zur Benutzerliste</x-ui.button>@endif
        @if(!is_null($count))<x-ui.badge>{{ $count }} Konten</x-ui.badge>@endif
        @isset($actions){{ $actions }}@endisset
    </x-slot:actions>
    {{ $slot }}
</x-ui.page>
