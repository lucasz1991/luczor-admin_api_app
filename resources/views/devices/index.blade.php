<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Meine Geräte & Kosten</h1></x-slot>
    <div class="mx-auto max-w-6xl space-y-6 p-6">
        <p>Jedes Gerät verwendet seinen eigenen Schlüssel und kann selbstständig arbeiten. Das Master-Gerät darf zusätzlich Aufträge an deine anderen Geräte verteilen.</p>
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        <div class="grid gap-4 md:grid-cols-2">
        @forelse($devices as $device)
            <form class="luczor-card space-y-3 p-4" method="POST" action="{{ route('account.devices.update', $device) }}">
                @csrf @method('PATCH')
                <label class="block">Gerätename <input class="luczor-input w-full" name="name" value="{{ $device->name }}" maxlength="120" required></label>
                <p class="break-all text-sm">{{ $device->device_id }}</p>
                <p>{{ $device->last_seen_at?->gt(now()->subMinutes(2)) ? $device->status : 'offline' }} · Zuletzt gesehen: {{ $device->last_seen_at?->diffForHumans() ?? 'unbekannt' }}</p>
                <input type="hidden" name="master" value="0">
                <label><input type="checkbox" name="master" value="1" @checked($user->master_device_id === $device->id)> Als Master-Gerät verwenden</label>
                <button class="luczor-btn" @disabled($device->revoked_at)>Speichern</button>
            </form>
        @empty<p>Noch keine Geräte registriert. Verbinde zuerst deine Luczor-App.</p>@endforelse
        </div>
        <section class="luczor-card overflow-x-auto p-5">
            <h2 class="font-semibold">Meine Modellkosten nach Gerät · Gesamtzeitraum</h2>
            <p class="text-sm">Schätzwerte in USD. Läufe ohne Kostenangabe sind separat ausgewiesen.</p>
            <table class="mt-4 w-full text-left"><thead><tr><th>Gerät</th><th>Läufe</th><th>Geschätzte Kosten</th><th>Ohne Kostenangabe</th></tr></thead><tbody>
            @foreach($costs as $cost)<tr><td class="py-2">{{ $devices->firstWhere('device_id', $cost->client_id)?->name ?? $cost->client_id ?? 'Ohne Gerät' }}</td><td>{{ $cost->runs_count }}</td><td>{{ number_format($cost->estimated_cost_usd, 6, ',', '.') }} USD</td><td>{{ $cost->unknown_cost_count }}</td></tr>@endforeach
            </tbody></table>
        </section>
    </div>
</x-app-layout>
