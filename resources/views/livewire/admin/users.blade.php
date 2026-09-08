<div>
    <x-user-ui.page title="Benutzer" eyebrow="Administration" :count="number_format($counts['total'], 0, ',', '.')" description="Konten, Geräte und Projekte an einem Ort. Öffne ein Profil für Kontodaten und die zugeordnete Nutzung.">
        <x-slot:actions><button type="button" wire:click="openCreate" class="luczor-btn gap-2"><span aria-hidden="true">+</span> Benutzer anlegen</button></x-slot:actions>
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="text-slate-400">Konten</span>
            <x-user-ui.badge color="green">{{ $counts['active'] }} aktiv</x-user-ui.badge>
            <x-user-ui.badge :color="$counts['inactive'] ? 'red' : 'slate'">{{ $counts['inactive'] }} gesperrt</x-user-ui.badge>
            <span wire:loading.delay class="text-xs text-cyan-300" role="status">Liste wird aktualisiert …</span>
        </div>
        @if ($creating)
            <section class="rounded-2xl border border-cyan-400/30 bg-slate-900/80 p-5" aria-labelledby="create-user-title" x-data x-init="$nextTick(() => $refs.userName.focus())">
                <div class="flex items-center justify-between gap-4"><h2 id="create-user-title" class="font-semibold">Neuer Benutzer</h2><button type="button" wire:click="closeCreate" class="text-sm text-slate-400 hover:text-white">Schließen</button></div>
                <p class="mt-2 text-sm text-slate-400">Das Konto wird als Benutzer angelegt. Bestehende Administratorkonten werden separat verwaltet.</p>
                <form wire:submit="createUser" class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div><label for="new-user-name" class="text-sm text-slate-300">Name</label><input id="new-user-name" x-ref="userName" class="luczor-input" wire:model="newUser.name" required maxlength="160" autocomplete="off">@error('name')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
                    <div><label for="new-user-email" class="text-sm text-slate-300">E-Mail</label><input id="new-user-email" class="luczor-input" wire:model="newUser.email" type="email" required maxlength="255" autocomplete="off">@error('email')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
                    <div><label for="new-user-password" class="text-sm text-slate-300">Startpasswort · mindestens 12 Zeichen</label><input id="new-user-password" class="luczor-input" wire:model="newUser.password" type="password" required minlength="12" autocomplete="new-password">@error('password')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
                    <div><label for="new-user-password-confirm" class="text-sm text-slate-300">Startpasswort bestätigen</label><input id="new-user-password-confirm" class="luczor-input" wire:model="newUser.password_confirmation" type="password" required minlength="12" autocomplete="new-password"></div>
                    <div class="flex gap-2 sm:col-span-2"><button class="luczor-btn disabled:opacity-50" wire:loading.attr="disabled" type="submit">Benutzer anlegen</button><button type="button" wire:click="closeCreate" class="luczor-btn-secondary">Abbrechen</button></div>
                </form>
            </section>
        @endif
        <section class="overflow-hidden rounded-2xl border border-slate-700/70 bg-slate-900/40" aria-label="Benutzerliste">
            <div class="grid gap-3 border-b border-slate-700/60 bg-slate-900/70 p-4 sm:grid-cols-2 xl:grid-cols-[minmax(14rem,1fr)_10rem_10rem_8rem_auto]">
                <div><label class="sr-only" for="users-search">Benutzer suchen</label><input id="users-search" class="luczor-input !mt-0" wire:model.live.debounce.300ms="search" type="search" placeholder="Name oder E-Mail suchen" maxlength="160"></div>
                <div><label class="sr-only" for="users-role">Rolle</label><select id="users-role" class="luczor-input !mt-0" wire:model.live="role"><option value="">Alle Rollen</option><option value="user">Benutzer</option><option value="admin">Administratoren</option></select></div>
                <div><label class="sr-only" for="users-status">Kontostatus</label><select id="users-status" class="luczor-input !mt-0" wire:model.live="accountStatus"><option value="">Alle Status</option><option value="active">Aktiv</option><option value="inactive">Gesperrt</option></select></div>
                <div><label class="sr-only" for="users-page-size">Benutzer pro Seite</label><select id="users-page-size" class="luczor-input !mt-0" wire:model.live="perPage"><option value="15">15 / Seite</option><option value="30">30 / Seite</option><option value="50">50 / Seite</option></select></div>
                <button type="button" wire:click="resetFilters" class="rounded-md px-2 py-2 text-sm text-slate-400 hover:bg-slate-800 hover:text-white">Zurücksetzen</button>
            </div>
            <div class="hidden grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_4rem] gap-4 px-5 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500 md:grid">
                <button type="button" class="text-left hover:text-cyan-300" wire:click="sort('name')">Benutzer {{ $sortBy === 'name' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' }}</button><span>Status</span><span>Arbeitsbereich</span><button type="button" class="text-left hover:text-cyan-300" wire:click="sort('created_at')">Seit {{ $sortBy === 'created_at' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' }}</button><span class="sr-only">Profil</span>
            </div>
            <ul class="divide-y divide-slate-800/80" aria-label="Gefundene Benutzer">
                @forelse($users as $user)
                    <li wire:key="user-row-{{ $user->id }}">
                        <a href="{{ route('admin.users.show', $user) }}" class="grid items-center gap-3 px-5 py-4 transition-colors hover:bg-slate-800/50 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-cyan-400 md:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_4rem] md:gap-4">
                            <div class="flex min-w-0 items-center gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-cyan-400/10 font-semibold text-cyan-200" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span><div class="min-w-0"><p class="truncate font-medium text-slate-100">{{ $user->name }}</p><p class="truncate text-xs text-slate-400">{{ $user->email }}</p></div></div>
                            <div class="flex flex-wrap gap-1.5"><x-user-ui.badge :color="$user->isAdmin() ? 'purple' : 'sky'">{{ $user->isAdmin() ? 'Admin' : 'Benutzer' }}</x-user-ui.badge>@unless($user->isActive())<x-user-ui.badge color="red">Gesperrt</x-user-ui.badge>@endunless @unless($user->hasVerifiedEmail())<x-user-ui.badge color="amber">Unbestätigt</x-user-ui.badge>@endunless</div>
                            <div class="text-xs text-slate-400"><span class="text-slate-200">{{ $user->devices_count }}</span> Geräte · <span class="text-slate-200">{{ $user->projects_count }}</span> Projekte</div>
                            <div class="text-xs text-slate-400"><time datetime="{{ $user->created_at?->toIso8601String() }}">{{ $user->created_at?->format('d.m.Y') }}</time>@if($user->last_device_seen_at)<p class="mt-1 {{ \Illuminate\Support\Carbon::parse($user->last_device_seen_at)->isAfter(now()->subMinutes(2)) ? 'text-emerald-300' : 'text-slate-500' }}">Gerät {{ \Illuminate\Support\Carbon::parse($user->last_device_seen_at)->locale('de')->diffForHumans() }}</p>@endif</div>
                            <span class="text-sm text-cyan-300 md:text-right">Profil <span aria-hidden="true">→</span></span>
                        </a>
                    </li>
                @empty
                    <li class="px-5 py-14 text-center"><h2 class="font-medium text-slate-200">Keine Benutzer gefunden</h2><p class="mt-2 text-sm text-slate-400">Passe die Suche an oder setze die Filter zurück.</p></li>
                @endforelse
            </ul>
            <div class="border-t border-slate-700/60 p-4">{{ $users->links('livewire.users-pagination') }}</div>
        </section>
    </x-user-ui.page>
</div>
