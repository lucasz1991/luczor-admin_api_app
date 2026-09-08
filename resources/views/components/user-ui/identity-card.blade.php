{{-- Adapted from RailTime admin/user-profile/partials/identity-card: person and account facts. --}}
@props(['user', 'lastSeen' => null])
@php
    $initials = collect(preg_split('/\s+/u', trim($user->name)))->filter()->take(2)->map(fn($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $online = $lastSeen && \Illuminate\Support\Carbon::parse($lastSeen)->isAfter(now()->subMinutes(2));
@endphp
<x-ui.panel aria-label="Benutzeridentität">
    <div class="grid gap-5 sm:grid-cols-2 sm:gap-8">
        <div class="flex min-w-0 items-center gap-4">
            <span class="relative shrink-0">
                <span class="grid h-16 w-16 place-items-center rounded-2xl bg-cyan-400/10 text-xl font-semibold text-cyan-200 ring-1 ring-cyan-400/20 sm:h-20 sm:w-20" aria-hidden="true">{{ $initials ?: 'U' }}</span>
                <span @class(['absolute -bottom-1 -right-1 h-4 w-4 rounded-full border-4 border-slate-900', 'bg-emerald-400' => $online && $user->isActive(), 'bg-slate-500' => !$online && $user->isActive(), 'bg-rose-400' => !$user->isActive()]) title="{{ !$user->isActive() ? 'Konto gesperrt' : ($online ? 'Gerät zuletzt online' : 'Kein aktives Gerät gemeldet') }}"></span>
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="break-words text-lg font-semibold text-white sm:text-xl">{{ $user->name }}</h2>
                <p class="mt-1 break-all text-sm text-slate-400">{{ $user->email }}</p>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    <x-user-ui.badge :color="$user->isAdmin() ? 'purple' : 'sky'">{{ $user->isAdmin() ? 'Administrator' : 'Benutzer' }}</x-user-ui.badge>
                    <x-user-ui.badge :color="$user->isActive() ? 'green' : 'red'">{{ $user->isActive() ? 'Aktiv' : 'Gesperrt' }}</x-user-ui.badge>
                    @unless($user->hasVerifiedEmail())<x-user-ui.badge color="amber">E-Mail unbestätigt</x-user-ui.badge>@endunless
                </div>
            </div>
        </div>
        <dl class="grid gap-3 pt-2 text-sm sm:pl-8 sm:pt-0">
            <div class="flex justify-between gap-4"><dt class="text-slate-400">Mitglied seit</dt><dd class="font-medium">{{ $user->created_at?->format('d.m.Y') ?? '–' }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-400">Benutzer-ID</dt><dd class="font-mono text-xs">#{{ $user->id }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-400">Geräte / Projekte</dt><dd class="font-medium">{{ $user->devices_count ?? 0 }} / {{ $user->projects_count ?? 0 }}</dd></div>
            @if($lastSeen)<div class="flex justify-between gap-4"><dt class="text-slate-400">Letztes Gerätesignal</dt><dd class="text-right">{{ \Illuminate\Support\Carbon::parse($lastSeen)->format('d.m.Y H:i') }}</dd></div>@endif
        </dl>
    </div>
</x-ui.panel>
