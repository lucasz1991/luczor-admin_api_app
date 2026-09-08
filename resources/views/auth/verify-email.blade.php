<x-guest-layout>
    <p class="ui-kicker">Noch ein Schritt</p>
    <h1 class="mt-3 text-3xl font-semibold tracking-tight">Prüfe dein Postfach.</h1>
    <p class="mt-3 text-sm leading-6 text-slate-400">Bestätige deine E-Mail-Adresse über den zugesandten Link. Danach kannst du deine Geräte und deinen Workspace nutzen.</p>
    @if(session('status') === 'verification-link-sent')<p class="ui-notice mt-5" role="status">Ein neuer Verifizierungslink wurde gesendet.</p>@endif
    <div class="mt-7 flex flex-wrap gap-3">
        <form method="POST" action="{{ route('verification.send') }}">@csrf<x-ui.button type="submit">Link erneut senden</x-ui.button></form>
        <form method="POST" action="{{ route('logout') }}">@csrf<x-ui.button variant="secondary" type="submit">Abmelden</x-ui.button></form>
    </div>
</x-guest-layout>
