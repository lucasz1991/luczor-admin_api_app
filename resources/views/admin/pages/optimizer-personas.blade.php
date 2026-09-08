<x-ui.panel title="Persönlichkeit und Skills gemeinsam ausarbeiten">
    <p class="mt-2 text-sm text-slate-400">Der Grundentwurf spricht Deutsch, ist klar und freundlich und unterstützt Laravel, Livewire, Alpine.js, Tailwind CSS sowie lokale Diagnose. Alle Texte sind hier einsehbar und bearbeitbar. Aktive Prompt-Skills gelten für den jeweiligen Nutzer und globale Skills für alle; Workflows starten ausschließlich auf Anforderung.</p>
    <form class="mt-3" method="POST" action="{{ route('dashboard.assistant-defaults.store') }}">@csrf
        <x-ui.button type="submit" variant="secondary">Grundentwurf ergänzen</x-ui.button>
        <p class="mt-2 text-xs text-slate-500">Ergänzt nur fehlende Einträge. Vorhandene Texte, deaktivierte Skills und eine bestehende Persönlichkeitsauswahl bleiben erhalten.</p>
    </form>
</x-ui.panel>
<div class="grid gap-6 lg:grid-cols-2">
    <x-ui.panel title="KI-Persönlichkeit anlegen">
        <p class="mt-1 text-xs text-slate-500">Wird direkt nach dem System-Prompt injiziert (globale Persönlichkeit).</p>
        <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.personas.store') }}">@csrf
            <label class="ui-field">Name
<x-ui.input name="name" placeholder="Name (z. B. Sachlich-Knapp)" required />
</label>
            <label class="ui-field">Anweisungen
<x-ui.textarea class="font-mono text-xs" name="prompt" rows="5" placeholder="Persönlichkeits-Prompt / Tonalität" required></x-ui.textarea>
</label>
            <x-ui.button type="submit" variant="primary">Speichern</x-ui.button>
        </form>
    </x-ui.panel>
    <x-ui.panel>
<div class="flex items-center justify-between"><h2 class="font-semibold">Persönlichkeiten</h2>
<form method="POST" action="{{ route('dashboard.personas.deactivate') }}">@csrf<x-ui.button type="submit" variant="secondary">Keine aktiv</x-ui.button>
</form>
</div>
        <div class="mt-3 space-y-3">@forelse($personas as $p)
            <div class="ui-record">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div><b class="text-cyan-100">{{ $p->name }}</b>@if($p->active)<span class="ml-2 rounded border border-emerald-400/30 px-1.5 text-[10px] text-emerald-200">aktiv</span>@endif<div class="font-mono text-[10px] text-slate-500">{{ $p->slug }}</div></div>
                    @unless($p->active)<form method="POST" action="{{ route('dashboard.personas.activate', $p) }}">@csrf<x-ui.button type="submit" variant="secondary">Aktivieren</x-ui.button>
</form>@endunless
                </div>
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm text-cyan-200">Text ansehen und bearbeiten</summary>
                    <form class="mt-3 space-y-3" method="POST" action="{{ route('dashboard.personas.update', $p) }}">@csrf @method('PATCH')
                        <label class="block text-xs text-slate-400">Name<x-ui.input class="mt-1" name="name" value="{{ $p->name }}" required maxlength="120" /></label>
                        <label class="block text-xs text-slate-400">Persönlichkeit und Tonalität<x-ui.textarea class="mt-1 text-sm" name="prompt" rows="10" required maxlength="20000">{{ $p->prompt }}</x-ui.textarea>
</label>
                        <x-ui.button type="submit" variant="primary">Änderungen speichern</x-ui.button>
                    </form>
                </details>
            </div>
        @empty<p class="text-xs text-slate-500">Noch keine Persönlichkeiten. Der Grundentwurf kann oben ergänzt werden.</p>@endforelse</div>
    </x-ui.panel>
</div>
