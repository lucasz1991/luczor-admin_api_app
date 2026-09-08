<section id="settings" class="mt-8">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Client-Einstellungen (Server-Defaults)</h2>
            <p class="mt-1 text-sm text-slate-400">Werden an die Desktop-App ausgeliefert (<code class="text-cyan-200">/api/v1/runtime-settings</code>).</p>
            <form class="mt-4" method="POST" action="{{ route('dashboard.settings.store') }}">
                @csrf
                <input type="hidden" name="_dashboard_tool_group" value="access">
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($settings as $setting)
                        <div class="rounded border border-slate-800 bg-slate-950/50 px-3 py-2">
                            <div class="text-sm text-slate-200">{{ $setting->label ?? $setting->key }} <span class="text-xs text-slate-500">({{ $setting->key }})</span></div>
                            <div class="mt-2">
                                @if ($setting->type === 'bool')
                                    <label class="flex items-center gap-2 text-sm text-slate-300">
                                        <input type="checkbox" class="rounded border-slate-700 bg-slate-950 text-cyan-400" name="settings[{{ $setting->key }}]" value="1" aria-label="{{ $setting->label ?? $setting->key }} aktiviert" @checked($setting->value['v'] ?? false)>
                                        aktiviert
                                    </label>
                                @elseif ($setting->type === 'number')
                                    <input type="number" step="any" class="luczor-input" name="settings[{{ $setting->key }}]" value="{{ $setting->value['v'] ?? 0 }}" aria-label="{{ $setting->label ?? $setting->key }}">
                                @else
                                    <input type="text" class="luczor-input" name="settings[{{ $setting->key }}]" value="{{ $setting->value['v'] ?? '' }}" aria-label="{{ $setting->label ?? $setting->key }}">
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <button class="luczor-btn mt-4" type="submit">Einstellungen speichern</button>
            </form>
        </div>
    </section>

    <section class="mt-8 grid gap-6 lg:grid-cols-2">
        <div id="api-keys" class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Device API Key erstellen</h2>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.api-keys.store') }}">
                @csrf
                <input type="hidden" name="_dashboard_tool_group" value="access">
                <input class="luczor-input" name="name" placeholder="Name, z.B. Desktop LZ" aria-label="Name des API-Schlüssels" required>
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="device_id" placeholder="Device ID optional" aria-label="Optionale Geräte-ID">
                    <input class="luczor-input" name="device_name" placeholder="Device Name optional" aria-label="Optionaler Gerätename">
                </div>
                <input class="luczor-input" name="expires_at" type="datetime-local" aria-label="Ablaufzeitpunkt des API-Schlüssels">
                <div class="grid gap-2 md:grid-cols-2">
                    @foreach ($abilities as $ability)
                        <label class="flex items-center gap-2 rounded border border-slate-800 bg-slate-950/50 px-3 py-2 text-sm text-slate-200">
                            <input class="rounded border-slate-700 bg-slate-950 text-cyan-400" type="checkbox" name="abilities[]" value="{{ $ability }}" @checked($ability === 'all')>
                            {{ $ability }}
                        </label>
                    @endforeach
                </div>
                <button class="luczor-btn" type="submit">Key erzeugen</button>
            </form>
        </div>

        <div id="providers" class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Provider Credential</h2>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.provider-credentials.store') }}">
                @csrf
                <input type="hidden" name="_dashboard_tool_group" value="access">
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="provider" placeholder="openrouter, elevenlabs, ..." aria-label="Provider-Kennung" required>
                    <input class="luczor-input" name="label" placeholder="Label" aria-label="Anzeigename des Provider-Credentials" required>
                </div>
                <select class="luczor-input" name="request_format" aria-label="Provider-Anfrageformat" required>
                    <option value="chat_completions">Chat Completions</option>
                    <option value="responses">OpenAI Responses</option>
                    <option value="messages">Anthropic Messages</option>
                </select>
                <input class="luczor-input" name="api_key" placeholder="API Key wird verschluesselt gespeichert" aria-label="Provider API-Schlüssel" required>
                <input class="luczor-input" name="base_url" placeholder="Base URL optional" aria-label="Optionale Provider-Basis-URL">
                <button class="luczor-btn" type="submit">Credential speichern</button>
            </form>
        </div>
    </section>

    <section class="mt-8 grid gap-6 xl:grid-cols-2">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Provider-Preissnapshot</h2>
            <p class="mt-1 text-sm text-slate-400">Fallback, falls der Provider keine Kosten meldet. Historische Läufe behalten ihren Snapshot.</p>
            <form class="mt-4 grid gap-3 md:grid-cols-2" method="POST" action="{{ route('dashboard.provider-prices.store') }}">@csrf<input type="hidden" name="_dashboard_tool_group" value="access">
                <input class="luczor-input" name="provider_id" value="openrouter" aria-label="Provider-Kennung für den Preis" required><input class="luczor-input" name="model_id" placeholder="provider/model-id" aria-label="Modell-ID für den Preis" required>
                <input class="luczor-input" name="input_per_million" type="number" min="0" step="0.00000001" placeholder="Input $ / 1M" aria-label="Input-Kosten pro Million Tokens" required><input class="luczor-input" name="output_per_million" type="number" min="0" step="0.00000001" placeholder="Output $ / 1M" aria-label="Output-Kosten pro Million Tokens" required>
                <input class="luczor-input" name="cache_read_per_million" type="number" min="0" step="0.00000001" placeholder="Cache read $ / 1M" aria-label="Cache-Lesekosten pro Million Tokens"><input class="luczor-input" name="cache_write_per_million" type="number" min="0" step="0.00000001" placeholder="Cache write $ / 1M" aria-label="Cache-Schreibkosten pro Million Tokens">
                <input type="hidden" name="currency" value="USD"><input class="luczor-input" name="valid_from" type="datetime-local" value="{{ now()->format('Y-m-d\TH:i') }}" aria-label="Gültig ab" required>
                <button class="luczor-btn" type="submit">Preisversion speichern</button>
            </form>
            <div class="mt-4 space-y-2">@foreach($providerPrices->take(10) as $price)<div class="rounded border border-slate-800 p-2 text-xs"><span class="font-mono text-cyan-200">{{ $price->model_id }}</span><span class="ml-2 text-slate-500">in ${{ $price->input_per_million }} · out ${{ $price->output_per_million }} / 1M · ab {{ $price->valid_from }}</span></div>@endforeach</div>
        </div>
        <div class="luczor-card p-5"><h2 class="text-lg font-semibold text-white">Provider-Status</h2><div class="mt-4 space-y-3">@forelse($providers as $provider)<div class="flex items-center justify-between rounded border border-slate-800 bg-slate-950/50 p-3 text-sm"><div><b>{{ $provider->label }}</b><div class="text-slate-500">{{ $provider->provider }} · {{ $provider->maskedKey() }}</div></div><form method="POST" action="{{ route('dashboard.provider-credentials.toggle', $provider) }}">@csrf<input type="hidden" name="_dashboard_tool_group" value="access"><button class="luczor-btn-secondary">{{ $provider->active ? 'Deaktivieren' : 'Aktivieren' }}</button></form></div>@empty<p class="text-slate-500">Keine Provider.</p>@endforelse</div></div>
    </section>
