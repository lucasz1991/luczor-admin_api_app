<x-guest-layout>
    <p class="ui-kicker">Wieder Zugang erhalten</p>
    <h1 class="mt-3 text-3xl font-semibold tracking-tight">Passwort vergessen?</h1>
    <p class="mt-3 text-sm leading-6 text-slate-400">Wir senden dir einen Link, mit dem du ein neues Passwort festlegen kannst.</p>
    @if(session('status'))<p class="ui-notice mt-5" role="status">{{ session('status') }}</p>@endif
    <form class="mt-7 space-y-5" method="POST" action="{{ route('password.email') }}">
        @csrf
        <div><label class="ui-label" for="email">E-Mail</label><x-ui.input id="email" name="email" type="email" :value="old('email')" required autofocus autocomplete="username" />@error('email')<p role="alert" class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
        <x-ui.button class="w-full" type="submit">Link zum Zurücksetzen senden</x-ui.button>
        <a class="block text-center text-sm text-cyan-200" href="{{ route('login') }}">Zurück zur Anmeldung</a>
    </form>
</x-guest-layout>
