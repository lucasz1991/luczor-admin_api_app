<form method="POST" action="{{ route('dashboard.internal-model-profiles.update') }}" class="mb-8 space-y-5" id="internal-model-profiles">
    @csrf
    @method('PUT')
    <x-ui.panel title="Persönlichkeit & System-Prompt interner Modelle">
        <p class="mt-2 text-sm text-slate-400">Diese Vorgaben gelten global für alle internen, lokal ausgeführten Modelle auf verbundenen Geräten. Für den Externagentenmodus lässt sich ein eigenes Profil festlegen: Es gilt für die internen Agenten, die externe Agenten koordinieren oder deren Ergebnisse verarbeiten.</p>
        <p class="mt-2 text-sm text-slate-400">Externe Provider sowie Codex- und Claude-Agenten erhalten diese Profile nicht. Ein rein externer Modelllauf verwendet kein internes Profil. Aktive Prompt-Skills bleiben ergänzend wirksam; Werkzeugfreigaben und Ausführungsmodus werden weiter separat gesteuert.</p>
        @if($errors->internalModels->any())
            <div class="ui-notice ui-notice--danger mt-4" role="alert">{{ $errors->internalModels->first() }}</div>
        @endif
        <div class="mt-5 grid gap-6 xl:grid-cols-2">
            @foreach(['standard' => 'Interner Betrieb', 'external_agents' => 'Externagentenmodus · interne Modelle'] as $mode => $label)
                <fieldset class="ui-record min-w-0 space-y-4">
                    <legend class="text-base font-semibold text-slate-100">{{ $label }}</legend>
                    <input type="hidden" name="profiles[{{ $mode }}][enabled]" value="0">
                    <label class="flex items-center gap-2 text-sm text-slate-200">
                        <input type="checkbox" name="profiles[{{ $mode }}][enabled]" value="1" @checked(old('profiles.'.$mode.'.enabled', $internalModelProfiles[$mode]['enabled']))>
                        Eigenes Profil verwenden
                    </label>
                    <p class="text-xs text-slate-400">
                        {{ $mode === 'standard' ? 'Deaktiviert: Die bisherige globale Persönlichkeit bleibt aktiv.' : 'Deaktiviert: Das Profil für den internen Betrieb wird übernommen. Aktiviert: Dieses Profil ersetzt dessen Persönlichkeit und System-Prompt vollständig.' }}
                        Aktivierte, leere Felder bedeuten ausdrücklich keine eigene Vorgabe.
                    </p>
                    <label class="ui-field" for="internal-{{ $mode }}-personality">Persönlichkeit
                        <x-ui.textarea id="internal-{{ $mode }}-personality" name="profiles[{{ $mode }}][personality]" rows="5" maxlength="2000" aria-describedby="internal-personality-help" :aria-invalid="$errors->internalModels->has('profiles.'.$mode.'.personality') ? 'true' : 'false'">{{ old('profiles.'.$mode.'.personality', $internalModelProfiles[$mode]['personality']) }}</x-ui.textarea>
                    </label>
                    @error('profiles.'.$mode.'.personality', 'internalModels')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
                    <label class="ui-field" for="internal-{{ $mode }}-system">System-Prompt
                        <x-ui.textarea id="internal-{{ $mode }}-system" name="profiles[{{ $mode }}][system_prompt]" rows="8" maxlength="4000" aria-describedby="internal-system-help" :aria-invalid="$errors->internalModels->has('profiles.'.$mode.'.system_prompt') ? 'true' : 'false'">{{ old('profiles.'.$mode.'.system_prompt', $internalModelProfiles[$mode]['system_prompt']) }}</x-ui.textarea>
                    </label>
                    @error('profiles.'.$mode.'.system_prompt', 'internalModels')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
                </fieldset>
            @endforeach
        </div>
        <p id="internal-personality-help" class="mt-4 text-xs text-slate-400">Persönlichkeit: Ton, Auftreten und bevorzugte Arbeitsweise, maximal 2.000 Zeichen.</p>
        <p id="internal-system-help" class="mt-1 text-xs text-slate-400">System-Prompt: Eigene Anweisungen für interne Modelle, maximal 4.000 Zeichen. Wird vor Persönlichkeit und Prompt-Skills eingefügt. Die konkreten Aufgabenregeln gelten weiterhin.</p>
        <div class="mt-5 flex flex-wrap items-center gap-4">
            <x-ui.button type="submit" variant="primary">Interne Profile global speichern</x-ui.button>
            <span class="text-xs text-slate-400">Verbundene Clients laden Änderungen beim nächsten Profilabruf; laufende Antworten behalten ihr Profil.</span>
        </div>
    </x-ui.panel>
</form>
