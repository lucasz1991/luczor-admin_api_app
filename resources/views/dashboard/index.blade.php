<x-app-layout>
    <div class="mb-8">
        <h1 class="text-2xl font-semibold text-white">Luczor Admin API</h1>
        <p class="mt-2 text-sm text-slate-400">Auth, Device API Keys, Modell-Fallbacks und Brain-Sync Archiv.</p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-md border border-emerald-400/30 bg-emerald-400/10 p-3 text-sm text-emerald-100">{{ session('status') }}</div>
    @endif

    @if (session('plain_api_key'))
        <div class="mb-6 rounded-md border border-cyan-400/30 bg-cyan-400/10 p-4">
            <div class="text-sm font-semibold text-cyan-100">Neuer API Key, nur jetzt sichtbar:</div>
            <code class="mt-2 block break-all rounded bg-slate-950 p-3 text-sm text-cyan-200">{{ session('plain_api_key') }}</code>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-6 rounded-md border border-rose-400/30 bg-rose-400/10 p-4 text-sm text-rose-100">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="grid gap-4 md:grid-cols-5">
        @foreach ($archiveCounts as $label => $count)
            <div class="luczor-card p-4">
                <div class="text-xs uppercase tracking-wider text-slate-500">{{ str_replace('_', ' ', $label) }}</div>
                <div class="mt-2 text-3xl font-semibold text-cyan-100">{{ $count }}</div>
            </div>
        @endforeach
    </section>

    <section class="mt-8 grid gap-6 lg:grid-cols-2">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Device API Key erstellen</h2>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.api-keys.store') }}">
                @csrf
                <input class="luczor-input" name="name" placeholder="Name, z.B. Desktop LZ" required>
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="device_id" placeholder="Device ID optional">
                    <input class="luczor-input" name="device_name" placeholder="Device Name optional">
                </div>
                <input class="luczor-input" name="expires_at" type="datetime-local">
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

        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Provider Credential</h2>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.provider-credentials.store') }}">
                @csrf
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="provider" placeholder="openrouter, elevenlabs, ..." required>
                    <input class="luczor-input" name="label" placeholder="Label" required>
                </div>
                <input class="luczor-input" name="api_key" placeholder="API Key wird verschluesselt gespeichert" required>
                <input class="luczor-input" name="base_url" placeholder="Base URL optional">
                <button class="luczor-btn" type="submit">Credential speichern</button>
            </form>
        </div>
    </section>

    <section class="mt-8 grid gap-6 lg:grid-cols-2">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Einzelnes Modellprofil</h2>
            <p class="mt-1 text-sm text-slate-400">Diese Profile werden pro Use-Case in Fallback-Reihenfolge zusammengestellt.</p>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.model-profiles.store') }}">
                @csrf
                <input class="luczor-input" name="name" placeholder="Name, z.B. Planner Fast" required>
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="provider" placeholder="Provider" required>
                    <input class="luczor-input" name="model_id" placeholder="Model ID" required>
                </div>
                <div class="grid gap-3 md:grid-cols-3">
                    <input class="luczor-input" name="temperature" type="number" min="0" max="2" step="0.01" value="0.20" required>
                    <input class="luczor-input" name="max_tokens" type="number" min="1" value="1200" required>
                    <input class="luczor-input" name="purpose" placeholder="Zweck">
                </div>
                <button class="luczor-btn" type="submit">Modellprofil speichern</button>
            </form>
        </div>

        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Use-Case anlegen</h2>
            <p class="mt-1 text-sm text-slate-400">Jeder Fall bekommt danach eine eigene Modellkette.</p>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.model-use-cases.store') }}">
                @csrf
                <input class="luczor-input" name="name" placeholder="z.B. Browser Agent, Vision, TTS" required>
                <textarea class="luczor-input" name="description" rows="3" placeholder="Beschreibung optional"></textarea>
                <button class="luczor-btn" type="submit">Use-Case speichern</button>
            </form>
        </div>
    </section>

    <section class="mt-8 luczor-card p-5">
        <h2 class="text-lg font-semibold text-white">Modell-Fallbacks pro Use-Case</h2>
        <p class="mt-1 text-sm text-slate-400">Niedrige Sortierung wird zuerst genutzt. Faellt ein Modell aus, folgt das naechste aktive Profil.</p>

        <form class="mt-4 grid gap-3 md:grid-cols-4" method="POST" action="{{ route('dashboard.model-use-case-entries.store') }}">
            @csrf
            <select class="luczor-input" name="model_use_case_id" required>
                <option value="">Use-Case</option>
                @foreach ($modelUseCases as $useCase)
                    <option value="{{ $useCase->id }}">{{ $useCase->slug }}</option>
                @endforeach
            </select>
            <select class="luczor-input" name="model_profile_id" required>
                <option value="">Modellprofil</option>
                @foreach ($modelProfiles as $profile)
                    <option value="{{ $profile->id }}">{{ $profile->name }} / {{ $profile->model_id }}</option>
                @endforeach
            </select>
            <input class="luczor-input" name="sort_order" type="number" min="1" value="1" required>
            <button class="luczor-btn mt-1" type="submit">Fallback setzen</button>
        </form>

        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            @foreach ($modelUseCases as $useCase)
                <div class="rounded-lg border border-slate-800 bg-slate-950/50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-cyan-100">{{ $useCase->name }}</h3>
                            <p class="text-xs text-slate-500">{{ $useCase->slug }}</p>
                        </div>
                        <span class="rounded-full bg-cyan-400/10 px-2 py-1 text-xs text-cyan-200">{{ $useCase->entries->count() }} Modelle</span>
                    </div>
                    <ol class="mt-4 space-y-2">
                        @forelse ($useCase->entries->sortBy('sort_order') as $entry)
                            <li class="flex items-center justify-between rounded border border-slate-800 px-3 py-2 text-sm">
                                <span>
                                    <span class="font-mono text-cyan-200">#{{ $entry->sort_order }}</span>
                                    {{ $entry->modelProfile?->name }}
                                    <span class="text-slate-500">({{ $entry->modelProfile?->model_id }})</span>
                                </span>
                                <span class="text-xs {{ $entry->active ? 'text-emerald-300' : 'text-slate-500' }}">{{ $entry->active ? 'aktiv' : 'aus' }}</span>
                            </li>
                        @empty
                            <li class="text-sm text-slate-500">Noch keine Fallbacks gesetzt.</li>
                        @endforelse
                    </ol>
                </div>
            @endforeach
        </div>
    </section>

    <section class="mt-8 grid gap-6 lg:grid-cols-2">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Aktive API Keys</h2>
            <div class="mt-4 space-y-3">
                @forelse ($apiKeys as $key)
                    <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="font-semibold text-slate-100">{{ $key->name }}</div>
                                <div class="text-slate-500">{{ $key->device_name ?: 'kein Device Name' }} / {{ $key->device_id ?: 'keine Device ID' }}</div>
                            </div>
                            <form method="POST" action="{{ route('dashboard.api-keys.toggle', $key) }}">
                                @csrf
                                <button class="luczor-btn-secondary" type="submit">{{ $key->active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                            </form>
                        </div>
                        <div class="mt-2 text-xs text-slate-500">Abilities: {{ implode(', ', $key->abilities ?? []) }}</div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Noch keine API Keys.</p>
                @endforelse
            </div>
        </div>

        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Provider Credentials</h2>
            <div class="mt-4 space-y-3">
                @forelse ($providers as $provider)
                    <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                        <div class="font-semibold text-slate-100">{{ $provider->label }}</div>
                        <div class="text-slate-500">{{ $provider->provider }} / {{ $provider->base_url ?: 'default endpoint' }}</div>
                        <div class="mt-1 font-mono text-xs text-cyan-200">{{ $provider->maskedKey() }}</div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Noch keine Provider Credentials.</p>
                @endforelse
            </div>
        </div>
    </section>
</x-app-layout>
