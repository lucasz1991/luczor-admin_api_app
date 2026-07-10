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

    @if (! $isAdmin)
        <section class="grid gap-6 xl:grid-cols-[1.35fr_0.85fr]">
            <div class="luczor-card overflow-hidden border-cyan-400/30">
                <div class="border-b border-cyan-400/10 bg-cyan-400/5 px-5 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h1 class="font-mono text-xl font-semibold text-cyan-100">luczor terminal</h1>
                            <p class="mt-1 text-sm text-slate-400">Cloud-gesteuerter Zugriff auf deine verbundenen Geraete, Projekte und Repositories.</p>
                        </div>
                        <span class="rounded-full border border-emerald-400/30 bg-emerald-400/10 px-3 py-1 text-xs font-semibold text-emerald-200">USER MODE</span>
                    </div>
                </div>
                <div class="space-y-4 p-5 font-mono text-sm">
                    <div class="text-slate-500">$ luczor status --scope=user</div>
                    <div class="grid gap-3 md:grid-cols-4">
                        @foreach ($archiveCounts as $label => $count)
                            <div class="rounded-md border border-cyan-400/10 bg-slate-950/70 p-3">
                                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-500">{{ str_replace('_', ' ', $label) }}</div>
                                <div class="mt-2 text-2xl font-semibold text-cyan-100">{{ $count }}</div>
                            </div>
                        @endforeach
                    </div>
                    <div class="rounded-md border border-cyan-400/10 bg-slate-950/80 p-4">
                        <div class="text-cyan-300">&gt; online clients</div>
                        <div class="mt-2 text-slate-300">
                            {{ $clientIds->count() ? $clientIds->implode(', ') : 'Noch kein Device verbunden. Erzeuge unten ein Geraete-Token und verbinde die Tauri-App.' }}
                        </div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-3">
                        <div class="rounded-md border border-cyan-400/10 bg-slate-950/70 p-3">
                            <div class="text-slate-500">control</div>
                            <div class="mt-1 text-cyan-100">cloud queue bereit</div>
                        </div>
                        <div class="rounded-md border border-cyan-400/10 bg-slate-950/70 p-3">
                            <div class="text-slate-500">mode</div>
                            <div class="mt-1 text-cyan-100">agent assisted</div>
                        </div>
                        <div class="rounded-md border border-cyan-400/10 bg-slate-950/70 p-3">
                            <div class="text-slate-500">provider</div>
                            <div class="mt-1 text-cyan-100">server routed</div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="devices" class="luczor-card p-5">
                <h2 class="text-lg font-semibold text-white">Meine Geraete</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($apiKeys as $key)
                        <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-slate-100">{{ $key->device_name ?: $key->name }}</div>
                                    <div class="text-slate-500">{{ $key->device_id ?: 'keine Device ID' }}</div>
                                </div>
                                <span class="rounded-full px-2 py-1 text-xs {{ $key->active ? 'bg-emerald-400/10 text-emerald-200' : 'bg-rose-400/10 text-rose-200' }}">{{ $key->active ? 'aktiv' : 'inaktiv' }}</span>
                            </div>
                            <div class="mt-2 text-xs text-slate-500">Abilities: {{ implode(', ', $key->abilities ?? []) }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Noch keine Geraete verbunden.</p>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="mt-8 grid gap-6 lg:grid-cols-2">
            <div id="projects" class="luczor-card p-5">
                <h2 class="text-lg font-semibold text-white">Meine Projekte</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($userProjects as $project)
                        <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-cyan-100">{{ $project->name }}</div>
                                    <div class="font-mono text-xs text-slate-500">{{ $project->external_id }}</div>
                                </div>
                                <span class="text-xs text-slate-500">{{ optional($project->updated_at)->diffForHumans() }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Noch keine Projekte synchronisiert.</p>
                    @endforelse
                </div>
            </div>

            <div id="github" class="luczor-card p-5">
                <h2 class="text-lg font-semibold text-white">Cloud / GitHub</h2>
                <p class="mt-1 text-sm text-slate-400">Hier wird der Codex-aehnliche Einstieg fuer Online-Geraete und Repository-basierte Projekte gebuendelt.</p>
                <div class="mt-4 rounded-md border border-cyan-400/10 bg-slate-950/70 p-4 font-mono text-sm">
                    <div class="text-slate-500">$ luczor attach github --repo owner/repo</div>
                    <div class="mt-2 text-cyan-100">Repository-Verknuepfung ist als naechster Cloud-Workflow vorbereitet.</div>
                </div>
                <div class="mt-4">
                    <h3 class="text-sm font-semibold text-slate-200">Letzte Agent-Events</h3>
                    <div class="mt-3 space-y-2">
                        @forelse ($userEvents as $event)
                            <div class="rounded border border-slate-800 bg-slate-950/50 px-3 py-2 text-xs">
                                <span class="font-mono text-cyan-200">{{ $event->event_type }}</span>
                                <span class="ml-2 text-slate-500">{{ optional($event->created_at)->diffForHumans() }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">Noch keine Agent-Events.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        <section class="mt-8 luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Geraete-Verbindung erstellen</h2>
            <p class="mt-1 text-sm text-slate-400">Dieses Token verbindet deine Tauri-App mit deinem User-Bereich. Provider-API-Keys bleiben auf dem Server.</p>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.api-keys.store') }}">
                @csrf
                <input class="luczor-input" name="name" placeholder="Name, z.B. Mein Desktop" required>
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="device_id" placeholder="Device ID optional">
                    <input class="luczor-input" name="device_name" placeholder="Device Name optional">
                </div>
                <input class="luczor-input" name="expires_at" type="datetime-local">
                <div class="grid gap-2 md:grid-cols-2">
                    @foreach ($abilities as $ability)
                        <label class="flex items-center gap-2 rounded border border-slate-800 bg-slate-950/50 px-3 py-2 text-sm text-slate-200">
                            <input class="rounded border-slate-700 bg-slate-950 text-cyan-400" type="checkbox" name="abilities[]" value="{{ $ability }}" @checked(in_array($ability, ['sync.read', 'sync.write', 'brain.read', 'proxy.use'], true))>
                            {{ $ability }}
                        </label>
                    @endforeach
                </div>
                <button class="luczor-btn" type="submit">Verbindungstoken erzeugen</button>
            </form>
        </section>
    @else

    <section class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
        @foreach ($operations as $label => $count)
            <div class="luczor-card p-4">
                <div class="text-xs uppercase tracking-wider text-slate-500">{{ str_replace('_', ' ', $label) }}</div>
                <div class="mt-2 text-2xl font-semibold text-cyan-100">{{ $count }}</div>
            </div>
        @endforeach
    </section>

    <section id="archives" class="grid gap-4 md:grid-cols-5">
        @foreach ($archiveCounts as $label => $count)
            <div class="luczor-card p-4">
                <div class="text-xs uppercase tracking-wider text-slate-500">{{ str_replace('_', ' ', $label) }}</div>
                <div class="mt-2 text-3xl font-semibold text-cyan-100">{{ $count }}</div>
            </div>
        @endforeach
    </section>

    <section id="settings" class="mt-8">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Client-Einstellungen (Server-Defaults)</h2>
            <p class="mt-1 text-sm text-slate-400">Werden an die Desktop-App ausgeliefert (<code class="text-cyan-200">/api/v1/runtime-settings</code>).</p>
            <form class="mt-4" method="POST" action="{{ route('dashboard.settings.store') }}">
                @csrf
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($settings as $setting)
                        <div class="rounded border border-slate-800 bg-slate-950/50 px-3 py-2">
                            <div class="text-sm text-slate-200">{{ $setting->label ?? $setting->key }} <span class="text-xs text-slate-500">({{ $setting->key }})</span></div>
                            <div class="mt-2">
                                @if ($setting->type === 'bool')
                                    <label class="flex items-center gap-2 text-sm text-slate-300">
                                        <input type="checkbox" class="rounded border-slate-700 bg-slate-950 text-cyan-400" name="settings[{{ $setting->key }}]" value="1" @checked($setting->value['v'] ?? false)>
                                        aktiviert
                                    </label>
                                @elseif ($setting->type === 'number')
                                    <input type="number" step="any" class="luczor-input" name="settings[{{ $setting->key }}]" value="{{ $setting->value['v'] ?? 0 }}">
                                @else
                                    <input type="text" class="luczor-input" name="settings[{{ $setting->key }}]" value="{{ $setting->value['v'] ?? '' }}">
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

        <div id="providers" class="luczor-card p-5">
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

    <section id="models" class="mt-8 grid gap-6 lg:grid-cols-2">
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
    @endif
</x-app-layout>
