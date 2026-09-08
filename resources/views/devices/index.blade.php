<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Meine Geräte & Kosten</h1></x-slot>
    <div class="mx-auto max-w-6xl space-y-6 p-6">
        <header class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs uppercase tracking-widest text-sky-400">Persönlicher Gerätepark</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">Meine Geräte & Kosten</h1><p class="mt-3 max-w-2xl text-sm leading-6 text-slate-400">Deine lokalen Modelle arbeiten eigenständig. Ein Master-Gerät darf Aufträge an deine anderen Geräte verteilen; im Web-Workspace wählst du das Ziel direkt aus.</p></div><a class="luczor-btn" href="{{ route('account.workspace') }}">Workspace öffnen ↗</a></header>
        <section class="grid gap-4 sm:grid-cols-3" aria-label="Geräteübersicht"><div class="luczor-card p-5"><p class="text-xs text-slate-500">Verbundene Geräte</p><p class="mt-3 text-3xl font-semibold">{{ $devices->whereNull('revoked_at')->count() }}</p></div><div class="luczor-card p-5"><p class="text-xs text-slate-500">Jetzt online</p><p class="mt-3 text-3xl font-semibold text-emerald-400">{{ $devices->filter(fn($device) => !$device->revoked_at && $device->last_seen_at?->gt(now()->subMinutes(2)))->count() }}</p></div><div class="luczor-card p-5"><p class="text-xs text-slate-500">Geschätzte Modellkosten · gesamt</p><p class="mt-3 text-3xl font-semibold">{{ number_format($costs->sum('estimated_cost_usd'), 2, ',', '.') }} <span class="text-sm text-slate-500">USD</span></p></div></section>
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        <div class="grid gap-4 md:grid-cols-2">
        @forelse($devices as $device)
            <form class="luczor-card space-y-4 p-5" method="POST" action="{{ route('account.devices.update', $device) }}">
                @csrf @method('PATCH')
                <div class="flex items-center justify-between gap-3"><span class="flex h-11 w-11 items-center justify-center rounded-xl bg-sky-500/10 text-sky-300" aria-hidden="true">▣</span><span class="rounded-full border border-white/10 px-3 py-1 text-xs text-slate-400">{{ $device->revoked_at ? 'Zugriff entzogen' : ($device->last_seen_at?->gt(now()->subMinutes(2)) ? 'Online' : 'Offline') }}</span></div>
                <label class="block text-xs text-slate-400">Gerätename <input class="luczor-input mt-2 w-full" name="name" value="{{ $device->name }}" maxlength="120" required @disabled($device->revoked_at)></label>
                <p class="break-all font-mono text-xs text-slate-500">{{ $device->device_id }}</p>
                <p class="text-xs text-slate-500">Zuletzt gesehen: {{ $device->last_seen_at?->locale('de')->diffForHumans() ?? 'unbekannt' }}</p>
                <input type="hidden" name="master" value="0">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="master" value="1" @checked((int)$user->master_device_id === (int)$device->id) @disabled($device->revoked_at)> Als Master-Gerät verwenden</label>
                <button class="luczor-btn" @disabled($device->revoked_at)>Speichern</button>
            </form>
        @empty<p>Noch keine Geräte registriert. Verbinde zuerst deine Luczor-App.</p>@endforelse
        </div>
        <section class="luczor-card overflow-x-auto p-5">
            <h2 class="font-semibold">Meine Modellkosten nach Gerät · Gesamtzeitraum</h2>
            <p class="text-sm">Schätzwerte in USD. Läufe ohne Kostenangabe sind separat ausgewiesen.</p>
            <table class="mt-4 w-full text-left text-sm"><thead class="border-b border-white/10 text-xs text-slate-500"><tr><th class="pb-3">Gerät</th><th class="pb-3">Läufe</th><th class="pb-3">Geschätzte Kosten</th><th class="pb-3">Ohne Kostenangabe</th></tr></thead><tbody>
            @foreach($costs as $cost)<tr><td class="py-2">{{ $devices->firstWhere('device_id', $cost->client_id)?->name ?? $cost->client_id ?? 'Ohne Gerät' }}</td><td>{{ $cost->runs_count }}</td><td>{{ number_format($cost->estimated_cost_usd, 6, ',', '.') }} USD</td><td>{{ $cost->unknown_cost_count }}</td></tr>@endforeach
            </tbody></table>
        </section>
    </div>
</x-app-layout>
