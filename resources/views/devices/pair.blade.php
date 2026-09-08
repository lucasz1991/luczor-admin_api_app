<x-app-layout>
    <x-ui.page title="Dein nächstes Gerät." eyebrow="Geräte verbinden" description="Verbinde die Desktop-App mit deinem Luczor-Konto.">
        <div class="max-w-2xl">
            <x-ui.panel title="Verbindung bestätigen" description="Bestätige eine Anmeldung, die du gerade in deiner Luczor-App gestartet hast.">
                <dl class="grid gap-5 sm:grid-cols-2">
                    <div><dt class="ui-kicker">Gerät</dt><dd class="mt-2 text-lg font-semibold">{{ $pairing->name }}</dd></div>
                    <div><dt class="ui-kicker">Dein Konto</dt><dd class="mt-2 font-medium">{{ auth()->user()->name }}</dd><dd class="mt-1 break-all text-sm text-slate-400">{{ auth()->user()->email }}</dd></div>
                    <div class="sm:col-span-2"><dt class="ui-kicker">Client-ID</dt><dd class="mt-2 break-all font-mono text-xs text-slate-400">{{ $pairing->client_id }}</dd></div>
                </dl>
                @if(session('status'))<p class="ui-notice mt-5" role="status">{{ session('status') }}</p>@endif
                @if(!$pairing->user_id)
                    <form class="mt-6" method="POST" action="{{ route('devices.pair.approve', $pairing->id) }}">@csrf<x-ui.button type="submit">Dieses Gerät verbinden</x-ui.button></form>
                @else
                    <div class="mt-6 space-y-3"><x-ui.badge tone="success">Verbindung freigegeben</x-ui.badge><p class="text-sm text-slate-400">Die Freigabe wurde gespeichert. Du kannst dieses Fenster schließen.</p><x-ui.button :href="route('account.devices')" variant="secondary">Meine Geräte öffnen</x-ui.button></div>
                @endif
            </x-ui.panel>
        </div>
    </x-ui.page>
</x-app-layout>
