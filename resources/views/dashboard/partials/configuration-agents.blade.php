<section class="mt-8 grid gap-6 xl:grid-cols-[1fr_1.2fr]">
        <div class="luczor-card p-5">
            <h2 class="font-semibold text-white">Agent-Profil</h2>
            <form class="mt-4 grid gap-3 md:grid-cols-2" method="POST" action="{{ route('dashboard.agent-profiles.store') }}">@csrf<input type="hidden" name="_dashboard_tool_group" value="agents">
                <input class="luczor-input" name="key" placeholder="backend" aria-label="Schlüssel des Agent-Profils" required><input class="luczor-input" name="name" placeholder="Backend Agent" aria-label="Name des Agent-Profils" required>
                <input class="luczor-input" name="type" placeholder="backend" aria-label="Typ des Agent-Profils" required><select class="luczor-input" name="status" aria-label="Status des Agent-Profils"><option value="active">Aktiv</option><option value="draft">Entwurf</option><option value="disabled">Deaktiviert</option></select>
                <input class="luczor-input md:col-span-2" name="prompt_template_key" value="luczor.system" aria-label="Schlüssel des Prompt-Templates">
                <textarea class="luczor-input font-mono text-xs md:col-span-2" name="required_sources" rows="2" aria-label="JSON-Liste benötigter Quellen">["graphify","github","cognee"]</textarea>
                <textarea class="luczor-input font-mono text-xs md:col-span-2" name="capabilities" rows="2" aria-label="JSON-Liste der Fähigkeiten">[]</textarea>
                <textarea class="luczor-input font-mono text-xs md:col-span-2" name="config" rows="2" aria-label="JSON-Konfiguration des Agenten">{"parallel_safe":false}</textarea>
                <button class="luczor-btn">Agent speichern</button>
            </form>
        </div>
        <div class="luczor-card p-5"><h2 class="font-semibold text-white">Orchestrator-Agenten</h2><div class="mt-4 grid gap-3 md:grid-cols-2">@foreach($agentProfiles as $agent)<div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm"><div class="flex justify-between"><b class="text-cyan-100">{{ $agent->name }}</b><span class="text-xs text-slate-500">{{ $agent->status }}</span></div><div class="mt-1 font-mono text-xs text-slate-500">{{ $agent->type }} · {{ implode(', ', $agent->required_sources ?? []) }}</div></div>@endforeach</div></div>
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
                                <input type="hidden" name="_dashboard_tool_group" value="agents">
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
