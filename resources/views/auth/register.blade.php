<x-guest-layout>
    <h1 class="text-xl font-semibold text-white">Luczor Admin registrieren</h1>
    <p class="mt-1 text-sm text-slate-400">Registrierung ist fuer diese Instanz aktiviert.</p>

    <form class="mt-6 space-y-4" method="POST" action="{{ route('register') }}">
        @csrf
        <div>
            <label class="text-sm text-slate-200" for="name">Name</label>
            <input class="luczor-input" id="name" name="name" type="text" value="{{ old('name') }}" required autofocus autocomplete="name">
            @error('name') <p class="mt-1 text-sm text-rose-300">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-sm text-slate-200" for="email">E-Mail</label>
            <input class="luczor-input" id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username">
            @error('email') <p class="mt-1 text-sm text-rose-300">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-sm text-slate-200" for="password">Passwort</label>
            <input class="luczor-input" id="password" name="password" type="password" required autocomplete="new-password">
            @error('password') <p class="mt-1 text-sm text-rose-300">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="text-sm text-slate-200" for="password_confirmation">Passwort bestaetigen</label>
            <input class="luczor-input" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
        </div>
        <button class="luczor-btn w-full" type="submit">Registrieren</button>
        <p class="text-center text-sm text-slate-400">
            Schon registriert?
            <a class="text-cyan-200 hover:text-cyan-100" href="{{ route('login') }}">Einloggen</a>
        </p>
    </form>
</x-guest-layout>
