<x-guest-layout>
    <p class="ui-kicker">Willkommen zurück</p>
    <h1 class="mt-3 text-3xl font-semibold tracking-tight">Dein Luczor wartet.</h1>
    <p class="mt-3 text-sm leading-6 text-slate-400">Ein Konto für deine Geräte, Gespräche und Erinnerungen.</p>
    @if(session('status'))<p class="ui-notice mt-5" role="status">{{ session('status') }}</p>@endif
    <form class="mt-7 space-y-5" method="POST" action="{{ route('login') }}">
        @csrf
        <div><label class="ui-label" for="email">E-Mail</label><x-ui.input id="email" name="email" type="email" :value="old('email')" required autofocus autocomplete="username" aria-describedby="email-error" />@error('email')<p id="email-error" role="alert" class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
        <div><div class="flex items-center justify-between gap-3"><label class="ui-label" for="password">Passwort</label><a class="text-sm text-cyan-200 hover:text-cyan-100" href="{{ route('password.request') }}">Vergessen?</a></div><x-ui.input id="password" name="password" type="password" required autocomplete="current-password" />@error('password')<p role="alert" class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
        <label class="flex items-center gap-3 text-sm text-slate-300"><input type="checkbox" name="remember">Angemeldet bleiben</label>
        <x-ui.button class="w-full" type="submit">Einloggen</x-ui.button>
        <p class="text-center text-sm text-slate-400">Noch kein Konto? <a class="text-cyan-200 hover:text-cyan-100" href="{{ route('register') }}">Registrieren</a></p>
    </form>
</x-guest-layout>
