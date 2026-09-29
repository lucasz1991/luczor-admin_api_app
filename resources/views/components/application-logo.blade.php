<div {{ $attributes->merge(['class' => 'inline-flex items-center gap-3']) }}>
    <picture class="luczor-guest-mark" aria-hidden="true">
        <source media="(prefers-reduced-motion: reduce)" srcset="{{ asset('brand/luczor-icon.svg') }}" type="image/svg+xml">
        <img src="{{ asset('brand/luczor-animated.gif') }}" width="64" height="64" alt="">
    </picture>
    <span class="text-lg font-semibold tracking-[0.18em] text-slate-100">LUCZOR</span>
</div>
