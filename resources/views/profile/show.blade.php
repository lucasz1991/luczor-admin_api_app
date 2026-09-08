<x-app-layout>
    <x-ui.page title="Mein Profil" eyebrow="Konto" description="Deine persönlichen Daten und deine Anmeldung. Für alle verbundenen Geräte.">
        <x-slot:actions><x-ui.button :href="route('account.workspace')" variant="secondary">Zum Workspace</x-ui.button></x-slot:actions>
        <div class="max-w-5xl">
            <x-ui.tabs id="my-profile" :tabs="['personal' => 'Persönliche Daten', 'security' => 'Passwort & Anmeldung']" :active="($errors->getBag('updatePassword')->any() || session('status') === 'password-updated') ? 'security' : 'personal'" :force-active="$errors->getBag('updatePassword')->any()">
                <x-ui.tab-panel name="personal"><livewire:profile.profile-identity-card /></x-ui.tab-panel>
                <x-ui.tab-panel name="security">
                    <x-ui.panel title="Passwort & Anmeldung" description="Bestätige dein aktuelles Passwort, bevor du ein neues festlegst.">
                        @if(session('status') === 'password-updated')<p class="ui-notice mb-5" role="status">Dein Passwort wurde aktualisiert.</p>@endif
                        <form class="grid gap-5 sm:grid-cols-2" method="POST" action="{{ route('user-password.update') }}">
                            @csrf @method('PUT')
                            <div class="sm:col-span-2"><label class="ui-label" for="current-password">Aktuelles Passwort</label><x-ui.input class="sm:max-w-md" id="current-password" name="current_password" type="password" autocomplete="current-password" required />@error('current_password', 'updatePassword')<p role="alert" class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
                            <div><label class="ui-label" for="new-password">Neues Passwort</label><x-ui.input id="new-password" name="password" type="password" autocomplete="new-password" required />@error('password', 'updatePassword')<p role="alert" class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
                            <div><label class="ui-label" for="confirm-password">Neues Passwort bestätigen</label><x-ui.input id="confirm-password" name="password_confirmation" type="password" autocomplete="new-password" required /></div>
                            <div class="sm:col-span-2"><x-ui.button type="submit">Passwort speichern</x-ui.button></div>
                        </form>
                    </x-ui.panel>
                </x-ui.tab-panel>
            </x-ui.tabs>
        </div>
    </x-ui.page>
</x-app-layout>
