@php
    $tierTabs = collect($draft['models'])->mapWithKeys(fn ($model, $index) => ['tier-'.$index => 'Stufe '.($index + 1)])->all();
    $activeTier = 'tier-0';
    foreach ($errors->keys() as $field) {
        if (preg_match('/^(?:profiles|models)\.(\d+)/', $field, $match)) {
            $activeTier = 'tier-'.$match[1];
            break;
        }
    }
@endphp
<x-app-layout>
    <x-ui.page title="Lokale Modelle · fünf Leistungsstufen" eyebrow="Administration" description="Fünf editierbare Profile. Luczor wählt das stärkste freigegebene Modell, das zu den verfügbaren Ressourcen passt.">
        <x-slot:actions><x-ui.button href="{{ route('admin.page', 'models') }}" variant="secondary">Externe Modelle & Routing</x-ui.button></x-slot:actions>
        @if(session('status'))<div class="ui-notice ui-notice--success" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="ui-notice ui-notice--danger" role="alert">{{ $errors->first() }}</div>@endif
        <section aria-label="Modellsignatur" class="space-y-2 border-b border-slate-700 pb-4 text-sm">
            @if($signingKey)
                <p>Modellsignatur: <strong>Bereit</strong></p>
                <dl class="grid gap-2 sm:grid-cols-2">
                    <div><dt>Schlüssel-ID</dt><dd class="break-all font-mono text-xs">{{ $signingKey['key_id'] }}</dd></div>
                    <div><dt>SHA-256</dt><dd class="break-all font-mono text-xs">{{ $signingKey['public_key_sha256'] }}</dd></div>
                </dl>
            @else
                <p role="alert">Modellsignatur nicht verfügbar: <code>{{ $signingError }}</code></p>
            @endif
        </section>
        <x-ui.panel title="Ein Modell, fünf Startprofile" description="Zum Start verwenden alle fünf Stufen dasselbe bisherige Modell mit denselben geprüften Speicheranforderungen. Identische Modelldateien werden gemeinsam genutzt und nicht fünfmal gespeichert.">
            <div class="flex flex-wrap items-center gap-3"><x-ui.badge tone="info">Katalogschema 2</x-ui.badge><x-ui.badge>Revision {{ $revision }}</x-ui.badge><span class="text-sm text-slate-400">Ein geladenes Modell bleibt für Folgeanfragen im Speicher.</span></div>
        </x-ui.panel>
        <form method="POST" action="{{ route('admin.local-models.update') }}" class="space-y-6">
            @csrf @method('PUT')
            <input type="hidden" name="revision" value="{{ $revision }}">
            <x-ui.tabs id="admin-local-models" :tabs="$tierTabs" :active="$activeTier" :force-active="$errors->any()">
                @foreach($draft['models'] as $index => $model)
                    <x-ui.tab-panel name="tier-{{ $index }}">
                        <x-ui.panel title="Stufe {{ $index + 1 }} · {{ $model['display_name'] }}">
                            <x-slot:actions><x-ui.badge :tone="$model['enabled'] ? 'success' : 'neutral'">{{ $model['enabled'] ? 'Aktiv konfiguriert' : 'Entwurf · noch nicht ausführbar' }}</x-ui.badge></x-slot:actions>
                            <div class="space-y-6">
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <x-ui.stat label="RAM zum Laden" :value="isset($model['capacity_policy']['min_available_ram_bytes']) ? round($model['capacity_policy']['min_available_ram_bytes'] / 1024 ** 3, 1).' GiB' : 'Offen'" />
                                    <x-ui.stat label="Mindest-RAM" :value="isset($model['capacity_policy']['min_total_ram_bytes']) ? round($model['capacity_policy']['min_total_ram_bytes'] / 1024 ** 3, 1).' GiB' : 'Offen'" />
                                </div>
                                <div class="grid gap-5 md:grid-cols-2">
                                    <label class="ui-field md:col-span-2">Anzeigename<x-ui.input name="profiles[{{ $index }}][name]" value="{{ old('profiles.'.$index.'.name', $model['display_name']) }}" required maxlength="160" /></label>
                                    @foreach(['total_ram' => ['Mindest-RAM (GiB)', 'min_total_ram_bytes'], 'free_ram' => ['Freier RAM zum Start (GiB)', 'min_available_ram_bytes'], 'vram' => ['GPU-Speicher (GiB; 0 = CPU erlaubt)', 'min_vram_bytes']] as $field => [$label, $policyKey])
                                        <label class="ui-field">{{ $label }}<x-ui.input type="number" step="0.1" min="{{ $field === 'vram' ? 0 : ($field === 'total_ram' ? 1 : 0.5) }}" max="1024" name="profiles[{{ $index }}][{{ $field }}]" value="{{ old('profiles.'.$index.'.'.$field, isset($model['capacity_policy'][$policyKey]) ? $model['capacity_policy'][$policyKey] / 1024 ** 3 : '') }}" required /></label>
                                    @endforeach
                                    <label class="ui-field">Kontextfenster (Tokens)<x-ui.input name="profiles[{{ $index }}][context]" type="number" min="512" max="2000000" value="{{ old('profiles.'.$index.'.context', $model['context_limit'] ?? 32768) }}" required /></label>
                                </div>
                                <div class="ui-record flex flex-wrap items-center justify-between gap-3">
                                    <input type="hidden" name="profiles[{{ $index }}][enabled]" value="0">
                                    <label class="inline-flex items-center gap-3 text-sm"><input type="checkbox" name="profiles[{{ $index }}][enabled]" value="1" @checked(old('profiles.'.$index.'.enabled', $model['enabled']))> Diese Stufe freigeben</label>
                                    <span class="break-all font-mono text-xs text-slate-400">Profil: {{ $model['id'] }}</span>
                                </div>
                                <details class="ui-disclosure">
                                    <summary>Modelldatei, Runtime, Lizenz und Prüfnachweise bearbeiten</summary>
                                    <p class="mt-4 text-sm text-slate-400">GPU-Beschleunigung benötigt einen passenden llama.cpp-Build. Optional legt <code>runtime.backend</code> die Variante fest: <code>auto</code>, <code>cuda</code> (NVIDIA), <code>vulkan</code>, <code>metal</code> oder <code>cpu</code>. Begleitbibliotheken können unter <code>runtime.files</code> mit Dateiname und SHA-256 verifiziert werden. Der Desktop meldet die tatsächlich genutzte Beschleunigung.</p>
                                    <label class="ui-field mt-5">Modell, Kontext, RAM/VRAM-Grenzen und verifizierte Dateien
                                        <x-ui.textarea class="font-mono text-xs" name="models[]" rows="20" required>{{ old('models.'.$index, json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) }}</x-ui.textarea>
                                    </label>
                                </details>
                            </div>
                        </x-ui.panel>
                    </x-ui.tab-panel>
                @endforeach
            </x-ui.tabs>
            <x-ui.panel title="Änderungen übernehmen" description="Entwürfe ändern den aktiven Katalog noch nicht. Zur Aktivierung werden Dateien und Prüfnachweise validiert und der Katalog signiert.">
                <div class="flex flex-wrap gap-3"><x-ui.button type="submit" variant="secondary" name="publish" value="0">Entwurf speichern</x-ui.button><x-ui.button type="submit" name="publish" value="1">Prüfen, signieren und aktivieren</x-ui.button></div>
                <p class="mt-4 text-sm text-slate-400">Aktivierung benötigt Desktop-Unterstützung für Katalogschema 2. Alle Geräte laden weiterhin nur signierte Modell- und Runtime-Dateien. RAM-Grenzen sind gemessene Startanforderungen, keine feste Windows-Reservierung.</p>
            </x-ui.panel>
        </form>
    </x-ui.page>
</x-app-layout>
