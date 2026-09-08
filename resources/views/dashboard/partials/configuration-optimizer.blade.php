<section id="optimizer" class="mt-6 grid gap-6 xl:grid-cols-2">
        <x-ui.panel title="Prompt-Version"><form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.prompt-templates.store') }}">@csrf<input type="hidden" name="_dashboard_tool_group" value="optimizer"><x-ui.input class="luczor-input" name="key" placeholder="luczor.coding" aria-label="Schlüssel der Prompt-Version" required /><x-ui.input class="luczor-input" name="task_type" placeholder="coding.fix_bug" aria-label="Optionaler Aufgabentyp" /><x-ui.textarea class="luczor-input" name="body" rows="6" placeholder="System-/Prompt-Template" aria-label="Prompt-Text" required></x-ui.textarea><x-ui.button type="submit">Neue Version</x-ui.button></form><div class="mt-4 text-xs text-slate-500">{{ $promptTemplates->count() }} Versionen gespeichert</div></x-ui.panel>
        <x-ui.panel title="Kontextstrategie"><form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.context-strategies.store') }}">@csrf<input type="hidden" name="_dashboard_tool_group" value="optimizer"><x-ui.input class="luczor-input" name="key" value="context.memory_code_budgeted" aria-label="Schlüssel der Kontextstrategie" required /><x-ui.input class="luczor-input" name="name" value="Memory + Code budgetiert" aria-label="Name der Kontextstrategie" required /><x-ui.textarea class="luczor-input font-mono text-xs" name="config" rows="6" aria-label="JSON-Konfiguration der Kontextstrategie" required>{"git_tokens":250,"graph_tokens":1000,"memory_tokens":600,"raw_file_tokens":3500,"deduplicate":true}</x-ui.textarea><x-ui.button type="submit">Strategie speichern</x-ui.button></form></x-ui.panel>
        <x-ui.panel title="Netzwerk-/Kostenpolicy"><form class="mt-4 grid gap-3 md:grid-cols-2" method="POST" action="{{ route('dashboard.network-policies.store') }}">@csrf<input type="hidden" name="_dashboard_tool_group" value="optimizer"><x-ui.input class="luczor-input md:col-span-2" name="key" value="proxy.openrouter.default" aria-label="Schlüssel der Netzwerk-Policy" required /><x-ui.input class="luczor-input md:col-span-2" name="name" value="OpenRouter Default" aria-label="Name der Netzwerk-Policy" required /><x-ui.input class="luczor-input" name="connect_timeout_ms" type="number" value="10000" aria-label="Verbindungs-Timeout in Millisekunden" required /><x-ui.input class="luczor-input" name="request_timeout_ms" type="number" value="90000" aria-label="Anfrage-Timeout in Millisekunden" required /><x-ui.input class="luczor-input" name="max_attempts" type="number" value="3" aria-label="Maximale Versuche" required /><x-ui.input class="luczor-input" name="backoff_ms" type="number" value="250" aria-label="Wartezeit zwischen Versuchen in Millisekunden" required /><x-ui.input class="luczor-input" name="max_cost_usd" type="number" step="0.000001" placeholder="Max $ / Run" aria-label="Maximale Kosten je Lauf in US-Dollar" /><x-ui.input class="luczor-input" name="max_input_tokens" type="number" value="24000" placeholder="Max Input" aria-label="Maximale Eingabe-Tokens" /><x-ui.input class="luczor-input" name="max_output_tokens" type="number" value="8192" aria-label="Maximale Ausgabe-Tokens" /><x-ui.button type="submit">Policy speichern</x-ui.button></form></x-ui.panel>
    </section>

    <section id="experiments" class="mt-6 grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
        <x-ui.panel title="A/B-Modellversuch">
            <p class="mt-1 text-xs text-slate-500">Varianten dürfen ausschließlich bereits administrierte Modellprofile referenzieren. Das Routing bleibt serverseitig.</p>
            <form class="mt-4 grid gap-3 md:grid-cols-2" method="POST" action="{{ route('dashboard.llm-experiments.store') }}">@csrf<input type="hidden" name="_dashboard_tool_group" value="optimizer">
                <x-ui.input class="luczor-input" name="key" placeholder="coding-fast-v1" aria-label="Schlüssel des Experiments" required />
                <x-ui.input class="luczor-input" name="name" placeholder="Coding: Qualität gegen Kosten" aria-label="Name des Experiments" required />
                <x-ui.input class="luczor-input" name="task_type" value="coding" aria-label="Aufgabentyp des Experiments" required />
                <x-ui.select class="luczor-input" name="status" aria-label="Status des Experiments"><option value="draft">Entwurf</option><option value="active">Aktiv</option><option value="paused">Pausiert</option><option value="completed">Beendet</option></x-ui.select>
                <x-ui.input class="luczor-input" name="traffic_percent" type="number" min="0" max="100" value="10" aria-label="Traffic-Anteil in Prozent" required />
                <x-ui.textarea class="luczor-input font-mono text-xs md:col-span-2" name="variants" rows="4" aria-label="JSON-Liste der Modellvarianten" required>[{"model_profile_slug":"luczor-default","weight":100}]</x-ui.textarea>
                <x-ui.textarea class="luczor-input font-mono text-xs md:col-span-2" name="success_criteria" rows="3" aria-label="JSON-Erfolgskriterien">{"quality_min":0.8,"cost_max_usd":0.05,"latency_max_ms":15000}</x-ui.textarea>
                <x-ui.button type="submit">Experiment speichern</x-ui.button>
            </form>
        </x-ui.panel>
        <x-ui.panel title="Experimente">
            <div class="mt-4 space-y-3">
                @forelse($llmExperiments as $experiment)
                    <div class="rounded border border-slate-800 bg-slate-950/50 p-3 text-sm">
                        <div class="flex justify-between"><b class="text-cyan-100">{{ $experiment->name }}</b><span class="text-xs text-slate-400">{{ $experiment->status }}</span></div>
                        <div class="mt-1 font-mono text-xs text-slate-500">{{ $experiment->task_type }} · {{ $experiment->traffic_percent }}% Traffic</div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Noch keine Experimente angelegt.</p>
                @endforelse
            </div>
        </x-ui.panel>
    </section>
