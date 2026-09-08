<div>
    <x-user-ui.page title="Benutzer" eyebrow="Administration" :count="number_format($counts['total'], 0, ',', '.')" description="Konten, Geräte und Projekte an einem Ort. Öffne ein Profil für Kontodaten und die zugeordnete Nutzung.">
        <x-slot:actions><x-ui.button variant="primary" type="button" wire:click="openCreate" class="gap-2"><span aria-hidden="true">+</span> Benutzer anlegen</x-ui.button></x-slot:actions>
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="text-slate-400">Konten</span>
            <x-user-ui.badge color="green">{{ $counts['active'] }} aktiv</x-user-ui.badge>
            <x-user-ui.badge :color="$counts['inactive'] ? 'red' : 'slate'">{{ $counts['inactive'] }} gesperrt</x-user-ui.badge>
            <span wire:loading.delay class="text-xs text-cyan-300" role="status">Liste wird aktualisiert …</span>
        </div>
        @if ($creating)
            <x-ui.panel aria-labelledby="create-user-title" x-data x-init="$nextTick(() => $refs.userName.focus())">
                <div class="flex items-center justify-between gap-4"><h2 id="create-user-title" class="font-semibold">Neuer Benutzer</h2><x-ui.button variant="ghost" wire:click="closeCreate">Schließen</x-ui.button></div>
                <p class="mt-2 text-sm text-slate-400">Das Konto wird als Benutzer angelegt. Bestehende Administratorkonten werden separat verwaltet.</p>
                <form wire:submit="createUser" class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div><label for="new-user-name" class="ui-label">Name</label><x-ui.input id="new-user-name" x-ref="userName" wire:model="newUser.name" required maxlength="160" autocomplete="off" />@error('name')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
                    <div><label for="new-user-email" class="ui-label">E-Mail</label><x-ui.input id="new-user-email" wire:model="newUser.email" type="email" required maxlength="255" autocomplete="off" />@error('email')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
                    <div><label for="new-user-password" class="ui-label">Startpasswort · mindestens 12 Zeichen</label><x-ui.input id="new-user-password" wire:model="newUser.password" type="password" required minlength="12" autocomplete="new-password" />@error('password')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
                    <div><label for="new-user-password-confirm" class="ui-label">Startpasswort bestätigen</label><x-ui.input id="new-user-password-confirm" wire:model="newUser.password_confirmation" type="password" required minlength="12" autocomplete="new-password" /></div>
                    <div class="flex gap-2 sm:col-span-2"><x-ui.button variant="primary" class="disabled:opacity-50" wire:loading.attr="disabled" type="submit">Benutzer anlegen</x-ui.button><x-ui.button variant="secondary" type="button" wire:click="closeCreate">Abbrechen</x-ui.button></div>
                </form>
            </x-ui.panel>
        @endif
        <x-ui.panel aria-label="Benutzerliste">
            <div class="grid gap-3 mb-5 rounded-2xl bg-white/[0.025] p-4 sm:grid-cols-2 xl:grid-cols-[minmax(14rem,1fr)_10rem_10rem_8rem_auto]">
                <div><label class="sr-only" for="users-search">Benutzer suchen</label><x-ui.input id="users-search" wire:model.live.debounce.300ms="search" type="search" placeholder="Name oder E-Mail suchen" maxlength="160" /></div>
                <div><label class="sr-only" for="users-role">Rolle</label><x-ui.select id="users-role" wire:model.live="role"><option value="">Alle Rollen</option><option value="user">Benutzer</option><option value="admin">Administratoren</option></x-ui.select></div>
                <div><label class="sr-only" for="users-status">Kontostatus</label><x-ui.select id="users-status" wire:model.live="accountStatus"><option value="">Alle Status</option><option value="active">Aktiv</option><option value="inactive">Gesperrt</option></x-ui.select></div>
                <div><label class="sr-only" for="users-page-size">Benutzer pro Seite</label><x-ui.select id="users-page-size" wire:model.live="perPage"><option value="15">15 / Seite</option><option value="30">30 / Seite</option><option value="50">50 / Seite</option></x-ui.select></div>
                <x-ui.button variant="ghost" wire:click="resetFilters">Zurücksetzen</x-ui.button>
            </div>
            <div class="hidden grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_4rem] gap-4 px-5 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500 md:grid">
                <button type="button" class="text-left hover:text-cyan-300" wire:click="sort('name')">Benutzer {{ $sortBy === 'name' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' }}</button><span>Status</span><span>Arbeitsbereich</span><button type="button" class="text-left hover:text-cyan-300" wire:click="sort('created_at')">Seit {{ $sortBy === 'created_at' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' }}</button><span class="sr-only">Profil</span>
            </div>
            <ul class="divide-y divide-white/5" aria-label="Gefundene Benutzer">
                @forelse($users as $user)
                    <li wire:key="user-row-{{ $user->id }}">
                        <a href="{{ route('admin.users.show', $user) }}" class="grid items-center gap-3 px-5 py-4 transition-colors duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] hover:bg-white/[0.025] focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-cyan-400 md:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_4rem] md:gap-4">
                            <div class="flex min-w-0 items-center gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-cyan-400/10 font-semibold text-cyan-200" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span><div class="min-w-0"><p class="truncate font-medium text-slate-100">{{ $user->name }}</p><p class="truncate text-xs text-slate-400">{{ $user->email }}</p></div></div>
                            <div class="flex flex-wrap gap-1.5"><x-user-ui.badge :color="$user->isAdmin() ? 'purple' : 'sky'">{{ $user->isAdmin() ? 'Admin' : 'Benutzer' }}</x-user-ui.badge>@unless($user->isActive())<x-user-ui.badge color="red">Gesperrt</x-user-ui.badge>@endunless @unless($user->hasVerifiedEmail())<x-user-ui.badge color="amber">Unbestätigt</x-user-ui.badge>@endunless</div>
                            <div class="text-xs text-slate-400"><span class="text-slate-200">{{ $user->devices_count }}</span> Geräte · <span class="text-slate-200">{{ $user->projects_count }}</span> Projekte</div>
                            <div class="text-xs text-slate-400"><time datetime="{{ $user->created_at?->toIso8601String() }}">{{ $user->created_at?->format('d.m.Y') }}</time>@if($user->last_device_seen_at)<p class="mt-1 {{ \Illuminate\Support\Carbon::parse($user->last_device_seen_at)->isAfter(now()->subMinutes(2)) ? 'text-emerald-300' : 'text-slate-500' }}">Gerät {{ \Illuminate\Support\Carbon::parse($user->last_device_seen_at)->locale('de')->diffForHumans() }}</p>@endif</div>
                            <span class="text-sm text-cyan-300 md:text-right">Profil <span aria-hidden="true">→</span></span>
                        </a>
                    </li>
                @empty
                    <li><x-ui.empty title="Keine Benutzer gefunden">Passe die Suche an oder setze die Filter zurück.</x-ui.empty></li>
                @endforelse
            </ul>
            <div class="mt-4 pt-4">{{ $users->links('livewire.users-pagination') }}</div>
        </x-ui.panel>
    </x-user-ui.page>
</div>
