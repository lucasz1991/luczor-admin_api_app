<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Lokale Modelle · fünf Leistungsstufen</h1></x-slot>
    <form method="POST" action="{{ route('admin.local-models.update') }}" class="mx-auto max-w-7xl space-y-5 p-6">
        @csrf @method('PUT')<input type="hidden" name="revision" value="{{ $revision }}">
        <p>Luczor wählt das stärkste freigegebene und lokal vorbereitbare Modell, das zu den aktuellen Ressourcen passt. Ein geladenes Modell bleibt für Folgeanfragen im Speicher. Lokale Dateien und Benchmarks werden vor Nutzung geprüft.</p>
        <p>Dein bisheriges Modell ist als höchste Stufe übernommen. Neue Stufen sind Vorschläge und bleiben bis zur Hinterlegung geprüfter GGUF-Dateien, Runtime- und Benchmarkdaten deaktiviert.</p>
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<p role="alert" class="text-rose-300">{{ $errors->first() }}</p>@endif
        <div class="grid gap-4 xl:grid-cols-2">
            @foreach($draft['models'] as $index => $model)
            <section class="luczor-card space-y-3 p-5">
                <h2 class="font-semibold">Stufe {{ $index + 1 }} · {{ $model['display_name'] }}</h2>
                <p class="text-sm">{{ $model['enabled'] ? 'Aktiv konfiguriert' : 'Entwurf · noch nicht ausführbar' }} · Datei: {{ $model['id'] }}.gguf</p>
                <p class="text-sm">RAM zum Laden: {{ round(($model['capacity_policy']['min_available_ram_bytes'] ?? 0) / 1024 ** 3, 1) }} GiB · Mindest-RAM: {{ round(($model['capacity_policy']['min_total_ram_bytes'] ?? 0) / 1024 ** 3, 1) }} GiB</p>
                <label class="block">Modell, Kontext, RAM/VRAM-Grenzen und verifizierte Dateien
                    <textarea class="luczor-input mt-2 w-full font-mono text-xs" name="models[]" rows="24" required>{{ old('models.'.$index, json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) }}</textarea>
                </label>
            </section>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-3"><button class="luczor-btn-secondary" name="publish" value="0">Entwurf speichern</button><button class="luczor-btn" name="publish" value="1">Prüfen, signieren und aktivieren</button></div>
        <p class="text-sm">Aktivierung benötigt Desktop-Unterstützung für Katalogschema 2. Alle Geräte laden weiterhin nur signierte Modell- und Runtime-Dateien. RAM-Grenzen sind gemessene Startanforderungen, keine feste Windows-Reservierung.</p>
    </form>
</x-app-layout>
