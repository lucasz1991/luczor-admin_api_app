<x-guest-layout>
    <p class="ui-kicker">Zugang erneuern</p>
    <h1 class="mt-3 text-3xl font-semibold tracking-tight">Dein neues Passwort.</h1>
    <p class="mt-3 text-sm leading-6 text-slate-400">Lege ein neues Passwort für dein Luczor-Konto fest.</p>
    <form class="mt-7 space-y-5" method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <div><label class="ui-label" for="email">E-Mail</label><x-ui.input id="email" name="email" type="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />@error('email')<p role="alert" class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
        <div><label class="ui-label" for="password">Passwort</label><x-ui.input id="password" name="password" type="password" required autocomplete="new-password" />@error('password')<p role="alert" class="mt-2 text-sm text-rose-300">{{ $message }}</p>@enderror</div>
        <div><label class="ui-label" for="password_confirmation">Passwort bestätigen</label><x-ui.input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" /></div>
        <x-ui.button class="w-full" type="submit">Passwort speichern</x-ui.button>
    </form>
</x-guest-layout>
