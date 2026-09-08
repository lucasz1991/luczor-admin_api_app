<div class="grid gap-6 xl:grid-cols-[1.2fr_.8fr]">
    <x-ui.panel title="Benutzer, Projekte und Geräte">
        <p class="mt-1 text-sm text-slate-400">Laravel-User sind die zentrale Besitz- und Authentifizierungsquelle für Luczor-Clients, API-Keys, Projekte und lokale Geräte.</p>
        <x-ui.table>
            <thead class="text-xs uppercase text-slate-500">
<tr>
<th>User</th>
<th>Rolle</th>
<th>Projekte</th>
<th>Geräte</th>
<th>Keys</th>
<th>Status</th>
</tr>
</thead>
            <tbody>
            @forelse($users as $user)
                <tr>
                    <td class="py-3"><b class="text-cyan-100">{{ $user->name }}</b>
<div class="font-mono text-xs text-slate-500">{{ $user->email }}</div>
                        @unless($user->isAdmin())
                            <details class="mt-2"><summary>Bearbeiten</summary>
                                <form class="grid gap-2 py-3" method="POST" action="{{ route('dashboard.users.update', $user) }}">@csrf @method('PATCH')
                                    <label>Name<x-ui.input name="name" value="{{ $user->name }}" required maxlength="160" /></label>
                                    <label>E-Mail<x-ui.input name="email" type="email" value="{{ $user->email }}" required /></label>
                                    <input type="hidden" name="status" value="0">
<label><input type="checkbox" name="status" value="1" @checked($user->status)> Konto aktiv</label>
                                    <x-ui.button type="submit" variant="primary">Speichern</x-ui.button>
                                </form>
                            </details>
                        @endunless
                    </td>
                    <td>{{ $user->role }}</td>
                    <td>{{ $user->projects_count }}</td>
                    <td>{{ $user->devices_count }}</td>
                    <td>{{ $user->api_keys_count }}</td>
                    <td><span class="rounded-full px-2 py-0.5 text-xs {{ $user->status ? 'bg-emerald-400/10 text-emerald-200' : 'bg-rose-400/10 text-rose-200' }}">{{ $user->status ? 'aktiv' : 'gesperrt' }}</span></td>
                </tr>
            @empty
                <tr>
<td colspan="6" class="py-6 text-slate-500">Noch keine Benutzer.</td>
</tr>
            @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.panel>
    <x-ui.panel title="Benutzer anlegen">
        <form class="mt-4 grid gap-3" method="POST" action="{{ route('dashboard.users.store') }}">@csrf
            <label>Name<x-ui.input class="w-full" name="name" required maxlength="160" /></label>
            <label>E-Mail<x-ui.input class="w-full" name="email" type="email" required /></label>
            <label>Passwort<x-ui.input class="w-full" name="password" type="password" autocomplete="new-password" minlength="12" required /></label>
            <label>Passwort bestätigen<x-ui.input class="w-full" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required /></label>
            <x-ui.button type="submit" variant="primary">Benutzer anlegen</x-ui.button>
        </form>
        <h2 class="mt-6 font-semibold">Master-Gerät-Prinzip</h2>
        <div class="mt-4 space-y-3 text-sm text-slate-300">
            <p>Ein Benutzer kann mehrere Geräte besitzen. Jedes Gerät meldet sich über einen eigenen API-Key und Client-Identifier am Laravel-Backend.</p>
            <p>Das Master-Gerät ist die Orchestrierungsinstanz für Agenten-Teams; andere Geräte bleiben als autonome Worker adressierbar, solange ihre lokalen Modelle verfügbar sind.</p>
            <p class="text-slate-500">Die Master-Auswahl erfolgt unter „Meine Geräte & Kosten“. Laravel prüft die Zuordnung beim Verteilen von Geräteaufträgen.</p>
        </div>
    </x-ui.panel>
</div>
