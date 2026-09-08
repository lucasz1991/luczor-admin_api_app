@php
    $adminPages = [
        'overview' => ['Systemübersicht', 'Betrieb, Nutzung und die wichtigsten Verwaltungsbereiche auf einen Blick.'],
        'providers' => ['Provider & Zugänge', 'Externe Modellzugänge zentral verwalten und sicher bereitstellen.'],
        'models' => ['Modelle & Routing', 'Modellprofile pflegen und die Reihenfolge pro Anwendungsfall bestimmen.'],
        'telemetry' => ['Nutzung & Leistung', 'Kosten, Geschwindigkeit und Erfolgsraten aus tatsächlichen Läufen vergleichen.'],
        'optimizer' => ['Assistent & Regeln', 'Persönlichkeit, Skills, Prompts und Qualitätsprüfung an einem Ort.'],
        'experiments' => ['Modell-Experimente', 'Den Status angelegter Experimente nachvollziehen.'],
        'workflows' => ['Workflows', 'Abläufe planen, bearbeiten und ihre Ausführung nachvollziehen.'],
        'agents' => ['Agenten & Ereignisse', 'Teams konfigurieren, Modelle vergleichen und laufende Aufgaben verfolgen.'],
        'users' => ['Benutzer & Zuordnung', 'Konten, Projekte und Geräte zentral zuordnen.'],
        'costs' => ['Kostenanalyse', 'Ausgaben nach Benutzer, Projekt oder Gerät getrennt betrachten.'],
        'devices' => ['Geräte-Diagnose', 'Verbundene Geräte überprüfen und Diagnoseberichte anfordern.'],
        'api-keys' => ['Geräte & Schlüssel', 'Die eingerichteten Geräteschlüssel und ihre Zuordnung überblicken.'],
        'archives' => ['Erinnerungen & Archive', 'Kanonische Erinnerungen, ihre Verbindungen und das Sync-Archiv erkunden.'],
        'settings' => ['Server-Einstellungen', 'Servervorgaben nach Themen geordnet bearbeiten.'],
    ];
    [$adminTitle, $adminDescription] = $adminPages[$page];
@endphp
<x-app-layout>
    @if($page === 'workflows')
        @if(session('status'))<div class="ui-notice ui-notice--success" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="ui-notice ui-notice--danger" role="alert">{{ $errors->first() }}</div>@endif
        @include('admin.workflows')
    @else
    <x-ui.page :title="$adminTitle" eyebrow="Administration" :description="$adminDescription">
        @if(in_array($page, ['overview', 'models', 'settings'], true))
            <x-slot:actions><x-ui.button href="{{ route('admin.local-models') }}" variant="secondary">Lokale Modelle</x-ui.button></x-slot:actions>
        @endif
        @if(session('status'))<div class="ui-notice ui-notice--success" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="ui-notice ui-notice--danger" role="alert">{{ $errors->first() }}</div>@endif
        {{-- Controller validates this page against the same fixed administration allowlist. --}}
        @include('admin.pages.'.$page)
    </x-ui.page>
    @endif
</x-app-layout>
