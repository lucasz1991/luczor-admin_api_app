<section id="models" class="mt-8 grid gap-6 lg:grid-cols-2">
        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Einzelnes Modellprofil</h2>
            <p class="mt-1 text-sm text-slate-400">Diese Profile werden pro Use-Case in Fallback-Reihenfolge zusammengestellt.</p>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.model-profiles.store') }}">
                @csrf
                <input type="hidden" name="_dashboard_tool_group" value="routing">
                <input class="luczor-input" name="name" placeholder="Name, z.B. Planner Fast" aria-label="Name des Modellprofils" required>
                <div class="grid gap-3 md:grid-cols-2">
                    <input class="luczor-input" name="provider" placeholder="Provider" aria-label="Provider des Modellprofils" required>
                    <input class="luczor-input" name="model_id" placeholder="Model ID" aria-label="Modell-ID" required>
                </div>
                <select class="luczor-input" name="provider_credential_id" aria-label="Explizites Provider-Credential" required>
                    <option value="">Provider-Credential auswählen</option>
                    @foreach ($providers->where('active', true) as $credential)
                        <option value="{{ $credential->id }}">{{ $credential->provider }} · {{ $credential->label }}</option>
                    @endforeach
                </select>
                <div class="grid gap-3 md:grid-cols-3">
                    <input class="luczor-input" name="temperature" type="number" min="0" max="2" step="0.01" value="0.20" aria-label="Temperatur" required>
                    <input class="luczor-input" name="max_tokens" type="number" min="1" value="1200" aria-label="Maximale Ausgabe-Tokens" required>
                    <input class="luczor-input" name="purpose" placeholder="Zweck" aria-label="Zweck des Modellprofils">
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    <textarea class="luczor-input font-mono text-xs" name="capabilities" rows="2" aria-label="JSON-Liste der Modellfähigkeiten">["chat"]</textarea>
                    <input class="luczor-input" name="context_window" type="number" min="1" max="2000000" aria-label="Kontextfenster in Tokens" placeholder="Kontextfenster in Tokens">
                </div>
                <button class="luczor-btn" type="submit">Modellprofil speichern</button>
            </form>
        </div>

        <div class="luczor-card p-5">
            <h2 class="text-lg font-semibold text-white">Use-Case anlegen</h2>
            <p class="mt-1 text-sm text-slate-400">Jeder Fall bekommt danach eine eigene Modellkette.</p>
            <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.model-use-cases.store') }}">
                @csrf
                <input type="hidden" name="_dashboard_tool_group" value="routing">
                <input class="luczor-input" name="name" placeholder="z.B. Browser Agent, Vision, TTS" aria-label="Name des Use-Cases" required>
                <textarea class="luczor-input" name="description" rows="3" placeholder="Beschreibung optional" aria-label="Optionale Beschreibung des Use-Cases"></textarea>
                <button class="luczor-btn" type="submit">Use-Case speichern</button>
            </form>
        </div>
    </section>

    <section class="mt-8 luczor-card p-5">
        <h2 class="text-lg font-semibold text-white">Modell-Fallbacks pro Use-Case</h2>
        <p class="mt-1 text-sm text-slate-400">Niedrige Sortierung wird zuerst genutzt. Faellt ein Modell aus, folgt das naechste aktive Profil.</p>

        <form class="mt-4 grid gap-3 md:grid-cols-4" method="POST" action="{{ route('dashboard.model-use-case-entries.store') }}">
            @csrf
            <input type="hidden" name="_dashboard_tool_group" value="routing">
            <select class="luczor-input" name="model_use_case_id" aria-label="Use-Case" required>
                <option value="">Use-Case</option>
                @foreach ($modelUseCases as $useCase)
                    <option value="{{ $useCase->id }}">{{ $useCase->slug }}</option>
                @endforeach
            </select>
            <select class="luczor-input" name="model_profile_id" aria-label="Modellprofil" required>
                <option value="">Modellprofil</option>
                @foreach ($modelProfiles as $profile)
                    <option value="{{ $profile->id }}">{{ $profile->name }} / {{ $profile->model_id }}</option>
                @endforeach
            </select>
            <input class="luczor-input" name="sort_order" type="number" min="1" value="1" aria-label="Fallback-Sortierung" required>
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

    <section class="mt-8 luczor-card p-5"><h2 class="font-semibold text-white">Aktive Modellprofile (nur Admin)</h2><div class="mt-4 grid gap-3 lg:grid-cols-2">@foreach($modelProfiles as $profile)<div class="flex items-center justify-between rounded border border-slate-800 bg-slate-950/50 p-3 text-sm"><div><b class="text-cyan-100">{{ $profile->name }}</b><div class="font-mono text-xs text-slate-500">{{ $profile->model_id }} · {{ $profile->purpose }}</div></div><form method="POST" action="{{ route('dashboard.model-profiles.toggle', $profile) }}">@csrf<input type="hidden" name="_dashboard_tool_group" value="routing"><button class="luczor-btn-secondary">{{ $profile->active ? 'Deaktivieren' : 'Aktivieren' }}</button></form></div>@endforeach</div></section>
