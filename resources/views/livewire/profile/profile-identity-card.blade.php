<div class="space-y-5">
    <x-user-ui.identity-card :user="$user" />
    <x-ui.panel aria-labelledby="identity-title">
        <div class="flex flex-wrap items-start justify-between gap-4"><div><h2 id="identity-title" class="text-lg font-semibold">Persönliche Daten</h2><p class="mt-2 text-sm text-slate-400">Dein Name und deine Anmeldung gelten für alle verbundenen Luczor-Geräte.</p></div><a href="{{ route('account.devices') }}" class="text-sm text-cyan-300 hover:text-cyan-100">Meine Geräte →</a></div>
        @if(session('identity-status'))<p class="mt-4 text-sm text-emerald-300" role="status">{{ session('identity-status') }}</p>@endif
        <form wire:submit="saveIdentity" class="mt-5 grid gap-5 sm:grid-cols-2">
            <div><label for="identity-name" class="ui-label">Name</label><x-ui.input id="identity-name" wire:model="name" maxlength="255" autocomplete="name" required />@error('name')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
            <div><label for="identity-email" class="ui-label">E-Mail</label><x-ui.input id="identity-email" wire:model="email" type="email" maxlength="255" autocomplete="email" required />@error('email')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror<p class="mt-2 text-xs text-slate-500">Bei einer Änderung erhältst du einen neuen Bestätigungslink.</p></div>
            <div class="flex flex-wrap items-center gap-3 sm:col-span-2"><x-ui.button variant="primary" type="submit" class="disabled:opacity-50" wire:loading.attr="disabled">Profil speichern</x-ui.button><span wire:loading wire:target="saveIdentity" class="text-sm text-cyan-300" role="status">Wird gespeichert …</span><span wire:dirty class="text-xs text-amber-300">Ungespeicherte Änderungen</span></div>
        </form>
    </x-ui.panel>
</div>
