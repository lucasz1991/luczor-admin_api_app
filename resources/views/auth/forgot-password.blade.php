<x-guest-layout>
    <h1 class="text-xl font-semibold text-white">Passwort zuruecksetzen</h1>
    <p class="mt-1 text-sm text-slate-400">Du bekommst einen Reset-Link an deine E-Mail-Adresse.</p>

    @if (session('status'))
        <div class="mt-4 rounded-md border border-emerald-400/30 bg-emerald-400/10 p-3 text-sm text-emerald-100">{{ session('status') }}</div>
    @endif

    <form class="mt-6 space-y-4" method="POST" action="{{ route('password.email') }}">
        @csrf
        <div>
            <label class="text-sm text-slate-200" for="email">E-Mail</label>
            <input class="luczor-input" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
            @error('email') <p class="mt-1 text-sm text-rose-300">{{ $message }}</p> @enderror
        </div>
        <button class="luczor-btn w-full" type="submit">Reset-Link senden</button>
    </form>
</x-guest-layout>
