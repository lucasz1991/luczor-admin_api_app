<x-app-layout>
    <div class="max-w-2xl">
        <h1 class="text-2xl font-semibold text-white">Profil</h1>
        <p class="mt-2 text-sm text-slate-400">Accountdaten fuer die Luczor Admin API.</p>

        <section class="mt-6 luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Profilinformationen</h2>
            <form class="mt-4 space-y-4" method="POST" action="{{ route('user-profile-information.update') }}">
                @csrf
                @method('PUT')
                <div>
                    <label class="text-sm text-slate-200" for="name">Name</label>
                    <input class="luczor-input" id="name" name="name" value="{{ old('name', auth()->user()->name) }}" required>
                </div>
                <div>
                    <label class="text-sm text-slate-200" for="email">E-Mail</label>
                    <input class="luczor-input" id="email" type="email" name="email" value="{{ old('email', auth()->user()->email) }}" required>
                </div>
                <button class="luczor-btn" type="submit">Speichern</button>
            </form>
        </section>

        <section class="mt-6 luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Passwort</h2>
            <form class="mt-4 space-y-4" method="POST" action="{{ route('user-password.update') }}">
                @csrf
                @method('PUT')
                <input class="luczor-input" name="current_password" type="password" placeholder="Aktuelles Passwort" required>
                <input class="luczor-input" name="password" type="password" placeholder="Neues Passwort" required>
                <input class="luczor-input" name="password_confirmation" type="password" placeholder="Neues Passwort bestaetigen" required>
                <button class="luczor-btn" type="submit">Passwort speichern</button>
            </form>
        </section>
    </div>
</x-app-layout>
