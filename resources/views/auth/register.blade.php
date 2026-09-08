<x-guest-layout>
    <p class="ui-kicker">Dein Konto</p>
    <h1 class="mt-3 text-3xl font-semibold tracking-tight">Mit Luczor starten.</h1>
    <p class="mt-3 text-sm leading-6 text-slate-400">Erstelle dein Konto und verbinde anschließend deine Geräte.</p>
    <form class="mt-7 space-y-5" method="POST" action="{{ route('register') }}">
        @csrf
        <div><label class="ui-label" for="name">Name</label><x-ui.input id="name" name="name" :value="old('name')" required autofocus autocomplete="name" />@error('name')<p role="alert" class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
        <div><label class="ui-label" for="email">E-Mail</label><x-ui.input id="email" name="email" type="email" :value="old('email')" required autocomplete="username" />@error('email')<p role="alert" class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
        <div><label class="ui-label" for="password">Passwort</label><x-ui.input id="password" name="password" type="password" required autocomplete="new-password" />@error('password')<p role="alert" class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
        <div><label class="ui-label" for="password_confirmation">Passwort bestätigen</label><x-ui.input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" /></div>
        <x-ui.button class="w-full" type="submit">Registrieren</x-ui.button>
        <p class="text-center text-sm text-slate-400">Schon registriert? <a class="text-cyan-200 hover:text-cyan-100" href="{{ route('login') }}">Einloggen</a></p>
    </form>
</x-guest-layout>
