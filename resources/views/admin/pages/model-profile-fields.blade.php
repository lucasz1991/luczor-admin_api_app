<div class="grid gap-5 sm:grid-cols-2">
    <label class="ui-field">Anzeigename<x-ui.input name="name" :value="$profile?->name" required /></label>
    <label class="ui-field">Provider<x-ui.input name="provider" :value="$profile?->provider ?? 'openrouter'" required /></label>
    <label class="ui-field">Provider-Zugang
        <x-ui.select name="provider_credential_id" required>
            <option value="">Zugang auswählen</option>
            @foreach($providers->where('active', true) as $credential)
                <option value="{{ $credential->id }}" @selected($profile?->provider_credential_id === $credential->id)>{{ $credential->provider }} · {{ $credential->label }}</option>
            @endforeach
        </x-ui.select>
    </label>
    <label class="ui-field">Modell-ID<x-ui.input name="model_id" :value="$profile?->model_id" placeholder="nvidia/…:free" required /></label>
    <label class="ui-field">Temperatur<x-ui.input name="temperature" :value="$profile?->temperature ?? '0.2'" type="number" step="0.05" min="0" max="2" /></label>
    <label class="ui-field">Maximale Antwort-Tokens<x-ui.input name="max_tokens" :value="$profile?->max_tokens ?? 2200" type="number" min="1" /></label>
    <label class="ui-field">Zweck<x-ui.input name="purpose" :value="$profile?->purpose ?? 'chat'" /></label>
    <label class="ui-field">Kontextfenster (Tokens)<x-ui.input name="context_window" type="number" min="1" max="2000000" :value="$profile?->context_window" placeholder="Optional" /></label>
    <label class="ui-field sm:col-span-2">Fähigkeiten als JSON<x-ui.textarea class="font-mono text-sm" name="capabilities" rows="2">{{ json_encode($profile ? ($profile->capabilities ?? []) : ['chat']) }}</x-ui.textarea>
</label>
</div>
