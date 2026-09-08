<x-guest-layout>
    <p class="ui-kicker">Sichere Anmeldung</p>
    <h1 class="mt-3 text-3xl font-semibold tracking-tight">Kurz bestätigen.</h1>
    <p class="mt-3 text-sm leading-6 text-slate-400">Bestätige dein aktuelles Passwort, um mit dieser Aktion fortzufahren.</p>
    <form class="mt-7 space-y-5" method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <div><label class="ui-label" for="password">Passwort</label><x-ui.input id="password" name="password" type="password" required autofocus autocomplete="current-password" />@error('password')<p role="alert" class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
        <x-ui.button class="w-full" type="submit">Bestätigen</x-ui.button>
    </form>
</x-guest-layout>
