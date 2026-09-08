<x-app-layout>
    <x-user-ui.page title="Mein Profil" eyebrow="Konto" description="Persönliche Daten, Anmeldung und die Verbindung zu deinen Geräten.">
        <div class="max-w-5xl space-y-6">
            <livewire:profile.profile-identity-card />
            <section class="rounded-2xl border border-slate-700/70 bg-slate-900/40 p-5 sm:p-6" aria-labelledby="password-title">
                <h2 id="password-title" class="text-lg font-semibold">Passwort & Anmeldung</h2>
                <p class="mt-2 text-sm text-slate-400">Zum Ändern bestätigst du zunächst dein aktuelles Passwort.</p>
                <form class="mt-5 grid gap-5 sm:grid-cols-2" method="POST" action="{{ route('user-password.update') }}">
                @csrf
                @method('PUT')
                    <div class="sm:col-span-2"><label class="text-sm text-slate-300" for="current-password">Aktuelles Passwort</label><input class="luczor-input sm:max-w-md" id="current-password" name="current_password" type="password" autocomplete="current-password" required>@error('current_password', 'updatePassword')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
                    <div><label class="text-sm text-slate-300" for="new-password">Neues Passwort</label><input class="luczor-input" id="new-password" name="password" type="password" autocomplete="new-password" required>@error('password', 'updatePassword')<p class="mt-1 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
                    <div><label class="text-sm text-slate-300" for="confirm-password">Neues Passwort bestätigen</label><input class="luczor-input" id="confirm-password" name="password_confirmation" type="password" autocomplete="new-password" required></div>
                    <div class="sm:col-span-2"><button class="luczor-btn" type="submit">Passwort speichern</button></div>
                </form>
            </section>
        </div>
    </x-user-ui.page>
</x-app-layout>
