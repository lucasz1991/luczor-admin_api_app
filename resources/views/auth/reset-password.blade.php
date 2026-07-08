<x-guest-layout>
    <h1 class="text-xl font-semibold text-white">Neues Passwort setzen</h1>

    <form class="mt-6 space-y-4" method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <div>
            <label class="text-sm text-slate-200" for="email">E-Mail</label>
            <input class="luczor-input" id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required autofocus>
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
        <button class="luczor-btn w-full" type="submit">Passwort speichern</button>
    </form>
</x-guest-layout>
