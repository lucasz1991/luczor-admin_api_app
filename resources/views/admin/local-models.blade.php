<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold">Lokale Modelle · fünf Leistungsstufen</h1></x-slot>
    <form method="POST" action="{{ route('admin.local-models.update') }}" class="mx-auto max-w-7xl space-y-5 p-6">
        <h1 class="text-2xl font-semibold">Lokale Modelle · fünf Leistungsstufen</h1>
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
                <label class="block">Anzeigename<input class="luczor-input w-full" name="profiles[{{ $index }}][name]" value="{{ old('profiles.'.$index.'.name', $model['display_name']) }}" required maxlength="160"></label>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach(['total_ram' => ['Mindest-RAM (GiB)', 'min_total_ram_bytes', 4], 'free_ram' => ['Freier RAM zum Start (GiB)', 'min_available_ram_bytes', 2], 'vram' => ['GPU-Speicher (GiB; 0 = CPU erlaubt)', 'min_vram_bytes', 0]] as $field => [$label, $policyKey, $fallback])
                    <label>{{ $label }}<input class="luczor-input w-full" type="number" step="0.1" min="{{ $field === 'vram' ? 0 : 0.5 }}" max="1024" name="profiles[{{ $index }}][{{ $field }}]" value="{{ old('profiles.'.$index.'.'.$field, ($model['capacity_policy'][$policyKey] ?? $fallback * 1024 ** 3) / 1024 ** 3) }}" required></label>
                    @endforeach
                    <label>Kontextfenster (Tokens)<input class="luczor-input w-full" name="profiles[{{ $index }}][context]" type="number" min="512" max="2000000" value="{{ old('profiles.'.$index.'.context', $model['context_limit'] ?? 32768) }}" required></label>
                </div>
                <input type="hidden" name="profiles[{{ $index }}][enabled]" value="0"><label><input type="checkbox" name="profiles[{{ $index }}][enabled]" value="1" @checked(old('profiles.'.$index.'.enabled', $model['enabled']))> Diese Stufe freigeben</label>
                <details><summary>Modelldatei, Runtime, Lizenz und Prüfnachweise bearbeiten</summary>
                <label class="block">Modell, Kontext, RAM/VRAM-Grenzen und verifizierte Dateien
                    <textarea class="luczor-input mt-2 w-full font-mono text-xs" name="models[]" rows="24" required>{{ old('models.'.$index, json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) }}</textarea>
                </label>
                </details>
            </section>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-3"><button class="luczor-btn-secondary" name="publish" value="0">Entwurf speichern</button><button class="luczor-btn" name="publish" value="1">Prüfen, signieren und aktivieren</button></div>
        <p class="text-sm">Aktivierung benötigt Desktop-Unterstützung für Katalogschema 2. Alle Geräte laden weiterhin nur signierte Modell- und Runtime-Dateien. RAM-Grenzen sind gemessene Startanforderungen, keine feste Windows-Reservierung.</p>
    </form>
</x-app-layout>
