<x-guest-layout>
    <h1 class="text-xl font-semibold text-white">Passwort bestaetigen</h1>
    <p class="mt-1 text-sm text-slate-400">Diese Aktion benoetigt eine erneute Passwortbestaetigung.</p>

    <form class="mt-6 space-y-4" method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <div>
            <label class="text-sm text-slate-200" for="password">Passwort</label>
            <input class="luczor-input" id="password" name="password" type="password" required autocomplete="current-password">
            @error('password') <p class="mt-1 text-sm text-rose-300">{{ $message }}</p> @enderror
        </div>
        <button class="luczor-btn w-full" type="submit">Bestaetigen</button>
    </form>
</x-guest-layout>
