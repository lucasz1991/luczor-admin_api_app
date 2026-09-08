<div>
    <x-user-ui.page title="Benutzerprofil" eyebrow="Administration" :back-url="route('admin.users.index')">
        <x-slot:actions>@if($user->is(auth()->user()))<a href="{{ route('profile.show') }}" class="luczor-btn-secondary">Mein Konto bearbeiten</a>@endif</x-slot:actions>
        <x-user-ui.identity-card :user="$user" :last-seen="$lastSeen" />
        @if(session('status'))<p class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 px-4 py-3 text-sm text-emerald-300" role="status">{{ session('status') }}</p>@endif
        <nav class="flex flex-wrap gap-2 border-b border-slate-700/70 pb-3" aria-label="Profilbereiche">
            @foreach(['overview' => 'Übersicht', 'account' => 'Kontodaten', 'usage' => 'Nutzung & Kosten'] as $key => $label)
                <button type="button" wire:click="selectTab('{{ $key }}')" @class(['rounded-lg px-4 py-2 text-sm font-medium transition-colors', 'bg-cyan-400/10 text-cyan-200 ring-1 ring-cyan-400/25' => $tab === $key, 'text-slate-400 hover:bg-slate-800 hover:text-white' => $tab !== $key]) @if($tab === $key) aria-current="page" @endif>{{ $label }}</button>
            @endforeach
        </nav>
        @if($tab === 'account')
            <section class="max-w-3xl rounded-2xl border border-slate-700/70 bg-slate-900/40 p-5 sm:p-6" aria-labelledby="account-data-title">
                <h2 id="account-data-title" class="text-lg font-semibold">Kontodaten</h2>
                @if($user->isAdmin())
                    <p class="mt-3 text-sm leading-relaxed text-slate-400">Dieses Administratorkonto ist hier geschützt. Eigene Zugangsdaten lassen sich im persönlichen Profil bearbeiten.</p>
                @else
                    <p class="mt-2 text-sm leading-relaxed text-slate-400">Ein gesperrtes Konto kann sich nicht mehr mit seinen Geräten verbinden. Eine neue E-Mail-Adresse muss erneut bestätigt werden.</p>
                    <form wire:submit="save" class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div><label for="user-profile-name" class="text-sm text-slate-300">Name</label><input id="user-profile-name" class="luczor-input" wire:model="name" maxlength="160" required>@error('name')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
                        <div><label for="user-profile-email" class="text-sm text-slate-300">E-Mail</label><input id="user-profile-email" type="email" class="luczor-input" wire:model="email" maxlength="255" required>@error('email')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
                        <label class="flex items-start gap-3 rounded-xl border border-slate-700 p-4 sm:col-span-2"><input type="checkbox" wire:model="active" class="mt-1 rounded border-slate-600 bg-slate-900 text-cyan-400 focus:ring-cyan-400"><span><span class="block text-sm font-medium">Konto aktiv</span><span class="mt-1 block text-xs text-slate-400">Gilt für den Web-Zugang und alle zugeordneten Geräte.</span></span></label>
                        <div class="flex flex-wrap items-center gap-3 sm:col-span-2"><button type="submit" class="luczor-btn disabled:opacity-50" wire:loading.attr="disabled">Änderungen speichern</button><span wire:loading wire:target="save" class="text-sm text-cyan-300" role="status">Wird gespeichert …</span><span wire:dirty class="text-xs text-amber-300">Ungespeicherte Änderungen</span></div>
                    </form>
                @endif
            </section>
        @elseif($tab === 'usage')
            <section class="rounded-2xl border border-slate-700/70 bg-slate-900/40 p-5 sm:p-6">
                <h2 class="text-lg font-semibold">Nutzung der letzten 30 Tage</h2>
                <p class="mt-2 text-sm text-slate-400">{{ number_format((float) $usage->estimated_usd, 4, ',', '.') }} USD geschätzte Kosten aus {{ $usage->runs }} Modellaufrufen. {{ $usage->unknown_costs }} Aufrufe ohne Kostenmeldung.</p>
                <div class="mt-5 overflow-x-auto"><table class="w-full min-w-[36rem] text-left text-sm"><caption class="sr-only">Die acht letzten zugeordneten Modellaufrufe</caption><thead class="text-xs uppercase tracking-wider text-slate-500"><tr><th class="pb-3 font-medium">Zeitpunkt</th><th class="pb-3 font-medium">Modell</th><th class="pb-3 font-medium">Status</th><th class="pb-3 text-right font-medium">Geschätzt · USD</th></tr></thead><tbody class="divide-y divide-slate-800">@forelse($recentRuns as $run)<tr wire:key="profile-run-{{ $run->id }}"><td class="py-3 text-slate-400">{{ $run->created_at->format('d.m.Y H:i') }}</td><td class="max-w-xs truncate py-3" title="{{ $run->model_id }}">{{ $run->model_id }}</td><td class="py-3 text-slate-400">{{ $run->status ?: 'Unbekannt' }}</td><td class="py-3 text-right">{{ is_null($run->estimated_cost_usd) ? 'Nicht gemeldet' : number_format($run->estimated_cost_usd, 4, ',', '.') }}</td></tr>@empty<tr><td colspan="4" class="py-8 text-center text-slate-400">Noch keine Modellaufrufe in diesem Zeitraum.</td></tr>@endforelse</tbody></table></div>
            </section>
        @else
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach([['Geräte', $user->devices_count, 'Dem Konto zugeordnet'], ['Projekte', $user->projects_count, 'Im Backend vorhanden'], ['Modellaufrufe', $usage->runs, 'Letzte 30 Tage'], ['Geschätzte Kosten', number_format((float) $usage->estimated_usd, 4, ',', '.').' USD', $usage->unknown_costs.' Aufrufe ohne Kostenmeldung']] as [$label, $value, $hint])
                    <section class="rounded-xl border border-slate-700/70 bg-slate-900/40 p-5"><h2 class="text-sm text-slate-400">{{ $label }}</h2><p class="mt-3 break-words text-2xl font-semibold text-white">{{ $value }}</p><p class="mt-2 text-xs text-slate-500">{{ $hint }}</p></section>
                @endforeach
            </div>
            <section class="rounded-2xl border border-slate-700/70 bg-slate-900/40 p-5 sm:p-6"><h2 class="font-semibold">Konto & Zuordnung</h2><dl class="mt-4 grid gap-4 sm:grid-cols-2"><div><dt class="text-xs uppercase tracking-wider text-slate-500">E-Mail-Bestätigung</dt><dd class="mt-2 text-sm">{{ $user->email_verified_at ? $user->email_verified_at->format('d.m.Y H:i') : 'Noch nicht bestätigt' }}</dd></div><div><dt class="text-xs uppercase tracking-wider text-slate-500">Gerätekoordination</dt><dd class="mt-2 text-sm">{{ $user->master_device_id ? 'Master-Gerät ausgewählt' : 'Geräte arbeiten eigenständig' }}</dd></div></dl></section>
        @endif
    </x-user-ui.page>
</div>
