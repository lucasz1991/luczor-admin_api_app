<x-guest-layout>
    <h1 class="text-xl font-semibold text-white">Einloggen</h1>
    <p class="mt-1 text-sm text-slate-400">Melde dich an, um Luczor-API, Modelle und Sync-Archive zu verwalten.</p>

    @if (session('status'))
        <div class="mt-4 rounded-md border border-emerald-400/30 bg-emerald-400/10 p-3 text-sm text-emerald-100">{{ session('status') }}</div>
    @endif

    <form class="mt-6 space-y-4" method="POST" action="{{ route('login') }}">
        @csrf
        <div>
            <label class="text-sm text-slate-200" for="email">E-Mail</label>
            <input class="luczor-input" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            @error('email') <p class="mt-1 text-sm text-rose-300">{{ $message }}</p> @enderror
        </div>
        <div>
            <div class="flex items-center justify-between">
                <label class="text-sm text-slate-200" for="password">Passwort</label>
                <a class="text-sm text-cyan-200 hover:text-cyan-100" href="{{ route('password.request') }}">Vergessen?</a>
            </div>
            <input class="luczor-input" id="password" name="password" type="password" required autocomplete="current-password">
            @error('password') <p class="mt-1 text-sm text-rose-300">{{ $message }}</p> @enderror
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-300">
            <input class="rounded border-slate-700 bg-slate-950 text-cyan-400" type="checkbox" name="remember">
            Angemeldet bleiben
        </label>
        <button class="luczor-btn w-full" type="submit">Einloggen</button>
        <p class="text-center text-sm text-slate-400">
            Noch kein Konto?
            <a class="text-cyan-200 hover:text-cyan-100" href="{{ route('register') }}">Registrieren</a>
        </p>
    </form>
</x-guest-layout>
