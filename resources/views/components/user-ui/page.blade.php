{{-- RailTime page header/slot structure without its application-specific help and tracking services. --}}
@props(['title', 'eyebrow' => null, 'description' => null, 'count' => null, 'backUrl' => null])
<div {{ $attributes->class('min-w-0 space-y-6') }}>
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            @if ($backUrl)<a class="mb-3 inline-flex text-sm text-slate-400 hover:text-cyan-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-cyan-400" href="{{ $backUrl }}">← Zur Benutzerliste</a>@endif
            @if ($eyebrow)<p class="text-xs font-semibold uppercase tracking-widest text-cyan-300/80">{{ $eyebrow }}</p>@endif
            <div class="mt-1 flex items-center gap-3"><h1 class="break-words text-2xl font-semibold tracking-tight text-white">{{ $title }}</h1>@if(!is_null($count))<x-user-ui.badge>{{ $count }}</x-user-ui.badge>@endif</div>
            @if ($description)<p class="mt-2 max-w-3xl text-sm leading-relaxed text-slate-400">{{ $description }}</p>@endif
        </div>
        @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
    </header>
    {{ $slot }}
</div>
