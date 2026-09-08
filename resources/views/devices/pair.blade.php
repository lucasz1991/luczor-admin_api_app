<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Luczor-Gerät verbinden</h1></x-slot>
    <section class="luczor-card mx-auto mt-8 max-w-xl space-y-4 p-6">
        <h1 class="text-2xl font-semibold">Luczor-Gerät verbinden</h1>
        <p>Gerät: <strong>{{ $pairing->name }}</strong></p>
        <p class="break-all text-sm">Client-ID: {{ $pairing->client_id }}</p>
        <p>Zuordnung zu {{ auth()->user()->name }} ({{ auth()->user()->email }}).</p>
        <p>Bestätige nur eine Anmeldung, die du gerade in deiner Luczor-App gestartet hast.</p>
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if(!$pairing->user_id)
            <form method="POST" action="{{ route('devices.pair.approve', $pairing->id) }}">@csrf<button class="luczor-btn">Dieses Gerät verbinden</button></form>
        @else
            <p>Die Freigabe wurde gespeichert. Du kannst dieses Fenster schließen.</p>
        @endif
    </section>
</x-app-layout>
