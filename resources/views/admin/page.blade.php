<x-app-layout>
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div><div class="font-mono text-xs uppercase tracking-[.2em] text-cyan-300/70">Admin / {{ $page }}</div><h1 class="mt-2 text-2xl font-semibold text-white">{{ match($page) {'overview'=>'System Overview','providers'=>'Provider & Preise','models'=>'Modelle & Routing','telemetry'=>'Telemetry & Kosten','optimizer'=>'Prompt, Kontext & Policy','experiments'=>'Modell-Experimente','workflows'=>'Workflows','agents'=>'Agenten & Ereignisse','devices'=>'Geraete-Debug','api-keys'=>'Geraete & Keys','archives'=>'Archive & Audit','settings'=>'Server Settings'} }}</h1></div>
        @if(session('status'))<div class="rounded border border-emerald-400/30 bg-emerald-400/10 px-4 py-2 text-sm text-emerald-100">{{ session('status') }}</div>@endif
    </div>
    @if($errors->any())<div class="mb-6 rounded border border-rose-400/30 bg-rose-400/10 p-4 text-sm text-rose-100">{{ $errors->first() }}</div>@endif

    @if($page === 'overview')
        <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">@foreach($operations as $label=>$value)<div class="luczor-card p-4"><div class="text-xs uppercase tracking-wider text-slate-500">{{ str_replace('_',' ',$label) }}</div><div class="mt-2 text-2xl font-semibold text-cyan-100">{{ $value }}</div></div>@endforeach</div>
        <div class="mt-6 grid gap-4 lg:grid-cols-3">
            <section class="luczor-card p-5 lg:col-span-2"><div class="flex items-baseline justify-between"><h2 class="font-semibold">Läufe / Tag <span class="text-xs text-slate-500">(14 T)</span></h2><span class="text-xs text-slate-400">{{ $charts['total_runs'] ?? 0 }} Läufe · ${{ $charts['total_cost'] ?? 0 }}</span></div>
                <svg viewBox="0 0 560 140" class="mt-3 w-full" preserveAspectRatio="none" role="img" aria-label="Läufe pro Tag">
                    <line x1="0" y1="130" x2="560" y2="130" stroke="rgba(148,197,236,0.15)"/>
                    @foreach(($charts['bars'] ?? []) as $b)<rect x="{{ $b['x'] }}" y="{{ $b['y'] }}" width="{{ $b['w'] }}" height="{{ $b['h'] }}" rx="2" fill="rgba(34,211,238,0.55)"><title>{{ $b['runs'] }} Läufe</title></rect><text x="{{ $b['x'] + $b['w']/2 }}" y="139" fill="rgba(107,142,166,0.8)" font-size="7" text-anchor="middle">{{ $b['day'] }}</text>@endforeach
                    @if(!empty($charts['cost_points']))<polyline points="{{ $charts['cost_points'] }}" fill="none" stroke="rgb(245,158,11)" stroke-width="1.5" stroke-linejoin="round"/>@endif
                </svg>
                <div class="mt-1 flex gap-4 text-xs text-slate-500"><span><span class="inline-block h-2 w-2 rounded-sm" style="background:rgba(34,211,238,.55)"></span> Läufe</span><span><span class="inline-block h-2 w-2 rounded-sm" style="background:rgb(245,158,11)"></span> Kosten</span></div>
            </section>
            <section class="luczor-card p-5"><h2 class="font-semibold">Provider (30 T)</h2>
                <div class="mt-3 space-y-2">@forelse(($charts['providers'] ?? []) as $p)<div><div class="flex justify-between text-xs"><span class="text-slate-300">{{ $p['label'] }}</span><span class="text-slate-500">{{ $p['value'] }}</span></div><div class="mt-1 h-2 rounded bg-slate-800"><div class="h-2 rounded" style="width:{{ $p['pct'] }}%;background:rgba(34,211,238,.6)"></div></div></div>@empty<p class="text-xs text-slate-500">Noch keine Läufe.</p>@endforelse</div>
                <h2 class="mt-5 font-semibold">Workflow-Status</h2>
                <div class="mt-3 space-y-2">@forelse(($charts['workflow_status'] ?? []) as $s)<div><div class="flex justify-between text-xs"><span class="text-slate-300">{{ $s['label'] }}</span><span class="text-slate-500">{{ $s['value'] }}</span></div><div class="mt-1 h-2 rounded bg-slate-800"><div class="h-2 rounded" style="width:{{ $s['pct'] }}%;background:rgba(52,211,153,.6)"></div></div></div>@empty<p class="text-xs text-slate-500">Noch keine Läufe.</p>@endforelse</div>
            </section>
        </div>
        <div class="mt-8 grid gap-4 md:grid-cols-3"><a href="{{ route('admin.page','models') }}" class="luczor-card p-5 hover:border-cyan-400/40"><b>Modelle verwalten</b><p class="mt-2 text-sm text-slate-400">Free-Model-Ketten, Fallbacks und Messwerte.</p></a><a href="{{ route('admin.page','telemetry') }}" class="luczor-card p-5 hover:border-cyan-400/40"><b>Telemetry auswerten</b><p class="mt-2 text-sm text-slate-400">Kosten, Geschwindigkeit und Erfolgsrate.</p></a><a href="{{ route('admin.page','devices') }}" class="luczor-card p-5 hover:border-cyan-400/40"><b>Geraete debuggen</b><p class="mt-2 text-sm text-slate-400">Stille Debug-Anforderung und Download.</p></a><a href="{{ route('admin.page','workflows') }}" class="luczor-card p-5 hover:border-cyan-400/40"><b>Workflows</b><p class="mt-2 text-sm text-slate-400">AI-Abläufe orchestrieren, starten und exportieren.</p></a><a href="{{ route('admin.page','agents') }}" class="luczor-card p-5 hover:border-cyan-400/40"><b>Agenten & Ereignisse</b><p class="mt-2 text-sm text-slate-400">Agent-Läufe und Audit-Ereignisprotokoll.</p></a></div>
    @elseif($page === 'models')
        <div class="grid gap-6 xl:grid-cols-[.8fr_1.2fr]"><section class="luczor-card p-5"><h2 class="font-semibold">Neues Modellprofil</h2><p class="mt-1 text-sm text-slate-400">Nur Admins bestimmen Modelle und Fallbacks.</p><form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.model-profiles.store') }}">@csrf<input class="luczor-input" name="name" placeholder="Anzeigename" required><input class="luczor-input" name="provider" value="openrouter" required><input class="luczor-input" name="model_id" placeholder="nvidia/...:free" required><div class="grid grid-cols-3 gap-2"><input class="luczor-input" name="temperature" value="0.2" type="number" step="0.05" min="0" max="2"><input class="luczor-input" name="max_tokens" value="2200" type="number" min="1"><input class="luczor-input" name="purpose" value="chat"></div><button class="luczor-btn">Profil anlegen</button></form></section>
        <section class="luczor-card overflow-x-auto p-5"><h2 class="font-semibold">Profile</h2><table class="mt-4 min-w-full text-left text-sm"><thead class="text-xs uppercase text-slate-500"><tr><th>Name / Modell</th><th>Zweck</th><th>Status</th><th></th></tr></thead><tbody class="divide-y divide-slate-800">@foreach($modelProfiles as $profile)<tr><td class="py-3"><b class="text-cyan-100">{{ $profile->name }}</b><div class="font-mono text-xs text-slate-500">{{ $profile->model_id }}</div></td><td>{{ $profile->purpose }}</td><td>{{ $profile->active?'aktiv':'aus' }}</td><td><details><summary class="cursor-pointer text-cyan-200">Bearbeiten</summary><form class="mt-3 grid gap-2" method="POST" action="{{ route('dashboard.model-profiles.update',$profile) }}">@csrf @method('PUT')<input class="luczor-input" name="name" value="{{ $profile->name }}"><input class="luczor-input" name="provider" value="{{ $profile->provider }}"><input class="luczor-input" name="model_id" value="{{ $profile->model_id }}"><div class="grid grid-cols-3 gap-2"><input class="luczor-input" name="temperature" value="{{ $profile->temperature }}"><input class="luczor-input" name="max_tokens" value="{{ $profile->max_tokens }}"><input class="luczor-input" name="purpose" value="{{ $profile->purpose }}"></div><button class="luczor-btn">Speichern</button></form><form class="mt-2 inline" method="POST" action="{{ route('dashboard.model-profiles.toggle',$profile) }}">@csrf<button class="luczor-btn-secondary">{{ $profile->active?'Deaktivieren':'Aktivieren' }}</button></form><form class="ml-2 inline" method="POST" action="{{ route('dashboard.model-profiles.destroy',$profile) }}">@csrf @method('DELETE')<button class="text-rose-300">Loeschen</button></form></details></td></tr>@endforeach</tbody></table></section></div>
        <section class="mt-6 luczor-card p-5">
            <h2 class="font-semibold">Fallback-Ketten</h2>
            <form class="mt-4 grid gap-3 md:grid-cols-4" method="POST" action="{{ route('dashboard.model-use-case-entries.store') }}">
                @csrf
                <select class="luczor-input" name="model_use_case_id">
                    @foreach($modelUseCases as $case)<option value="{{ $case->id }}">{{ $case->name }}</option>@endforeach
                </select>
                <select class="luczor-input" name="model_profile_id">
                    @foreach($modelProfiles as $profile)<option value="{{ $profile->id }}">{{ $profile->name }}</option>@endforeach
                </select>
                <input class="luczor-input" name="sort_order" type="number" value="1">
                <button class="luczor-btn">Zur Kette</button>
            </form>

            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                @foreach($modelUseCases as $case)
                    <div class="rounded border border-slate-800 p-4" data-chain-card>
                        <div class="flex items-center justify-between gap-3">
                            <b class="text-cyan-100">{{ $case->name }}</b>
                            <div class="flex items-center gap-2 text-xs">
                                <span class="text-slate-500">Einträge ziehen zum Sortieren</span>
                                <span class="text-slate-500" data-chain-status role="status"></span>
                            </div>
                        </div>

                        <ul class="mt-3 space-y-1 text-sm" data-chain>
                            @forelse($case->entries->sortBy('sort_order') as $entry)
                                <li class="flex items-center justify-between gap-3 rounded border border-slate-800 bg-slate-900/40 px-2 py-2 {{ $entry->active ? '' : 'opacity-60' }}" draggable="true" data-entry-id="{{ $entry->id }}">
                                    <span class="min-w-0 cursor-grab select-none">
                                        <span aria-hidden="true">⠿</span>
                                        {{ $entry->modelProfile?->name }}
                                        <span class="text-slate-500">{{ $entry->modelProfile?->model_id }}</span>
                                    </span>

                                    <div class="flex shrink-0 items-center gap-2">
                                        <span class="rounded-full px-2 py-0.5 text-xs {{ $entry->active ? 'bg-emerald-400/10 text-emerald-200' : 'bg-slate-700/50 text-slate-400' }}">{{ $entry->active ? 'aktiv' : 'inaktiv' }}</span>
                                        <details class="relative" data-entry-actions>
                                            <summary class="cursor-pointer list-none rounded px-2 py-1 text-lg leading-none text-cyan-200 hover:bg-cyan-400/10" aria-label="Aktionen für {{ $entry->modelProfile?->name }}">⋮</summary>
                                            <div class="absolute right-0 z-20 mt-1 w-52 rounded border border-slate-700 bg-slate-950 p-1 text-left shadow-lg">
                                                <form method="POST" action="{{ route('dashboard.model-use-case-entries.toggle', $entry) }}">
                                                    @csrf
                                                    <button class="block w-full rounded px-3 py-2 text-left text-xs text-cyan-100 hover:bg-cyan-400/10" type="submit">{{ $entry->active ? 'Deaktivieren' : 'Aktivieren' }}</button>
                                                </form>
                                                <form class="border-t border-slate-800 pt-1" method="POST" action="{{ route('dashboard.model-use-case-entries.destroy', $entry) }}" onsubmit="return confirm('Dieses Modell nur aus diesem Anwendungsfall entfernen?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="block w-full rounded px-3 py-2 text-left text-xs text-rose-300 hover:bg-rose-400/10" type="submit">Aus Anwendungsfall entfernen</button>
                                                </form>
                                            </div>
                                        </details>
                                    </div>
                                </li>
                            @empty
                                <li class="rounded border border-dashed border-slate-800 px-2 py-3 text-sm text-slate-500">Noch keine Modelle in dieser Kette.</li>
                            @endforelse
                        </ul>

                        <form class="hidden" method="POST" action="{{ route('dashboard.model-use-case-entries.reorder') }}" data-chain-form>
                            @csrf
                            <input type="hidden" name="model_use_case_id" value="{{ $case->id }}">
                        </form>
                    </div>
                @endforeach
            </div>

            <script>
document.querySelectorAll('[data-chain]').forEach(function(list){
  var dragEl = null;
  var previousOrder = [];

  list.querySelectorAll('li[draggable]').forEach(function(li){
    li.addEventListener('dragstart', function(e){
      var isAction = e.target instanceof Element && e.target.closest('[data-entry-actions]');
      if (list.dataset.saving === 'true' || isAction) {
        e.preventDefault();
        return;
      }

      dragEl = li;
      previousOrder = Array.from(list.querySelectorAll('li[data-entry-id]'));
      li.style.opacity = '.4';
    });

    li.addEventListener('dragend', function(){
      if (!dragEl) return;

      li.style.opacity = '';
      var currentOrder = Array.from(list.querySelectorAll('li[data-entry-id]'));
      if (!previousOrder.every(function(entry, index){ return entry === currentOrder[index]; })) {
        submitChain(list, previousOrder);
      }
      dragEl = null;
    });

    li.addEventListener('dragover', function(e){
      e.preventDefault();
      var target = e.currentTarget;
      if (!dragEl || target === dragEl) return;

      var rect = target.getBoundingClientRect();
      var after = (e.clientY - rect.top) > rect.height / 2;
      list.insertBefore(dragEl, after ? target.nextSibling : target);
    });
  });
});

function submitChain(list, previousOrder){
  var card = list.closest('[data-chain-card]');
  var form = card && card.querySelector('[data-chain-form]');
  if (!form) return;

  form.querySelectorAll('input[name="entry_ids[]"]').forEach(function(input){ input.remove(); });
  list.querySelectorAll('li[data-entry-id]').forEach(function(li){
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'entry_ids[]';
    input.value = li.dataset.entryId;
    form.appendChild(input);
  });

  list.dataset.saving = 'true';
  setChainStatus(list, 'Speichern…', 'pending');

  fetch(form.action, {
    method: 'POST',
    body: new FormData(form),
    credentials: 'same-origin',
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
    .then(function(response){
      if (!response.ok) throw new Error('reorder_failed');
      return response.json();
    })
    .then(function(payload){
      setChainStatus(list, payload.message || 'Reihenfolge gespeichert.', 'success');
    })
    .catch(function(){
      previousOrder.forEach(function(entry){ list.appendChild(entry); });
      setChainStatus(list, 'Reihenfolge konnte nicht gespeichert werden.', 'error');
    })
    .finally(function(){
      delete list.dataset.saving;
    });
}

function setChainStatus(list, message, state){
  var card = list.closest('[data-chain-card]');
  var status = card && card.querySelector('[data-chain-status]');
  if (!status) return;

  status.textContent = message;
  status.classList.remove('text-slate-500', 'text-emerald-300', 'text-rose-300');
  status.classList.add(state === 'success' ? 'text-emerald-300' : state === 'error' ? 'text-rose-300' : 'text-slate-500');
}
</script>
        </section>
    @elseif($page === 'providers')
        <div class="grid gap-6 lg:grid-cols-2"><section class="luczor-card p-5"><h2 class="font-semibold">Provider Credential</h2><form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.provider-credentials.store') }}">@csrf<input class="luczor-input" name="provider" value="openrouter"><input class="luczor-input" name="label" placeholder="Label" required><input class="luczor-input" name="api_key" placeholder="API-Key (verschluesselt)" required><input class="luczor-input" name="base_url" value="https://openrouter.ai/api/v1"><button class="luczor-btn">Speichern</button></form></section><section class="luczor-card p-5"><h2 class="font-semibold">Aktive Credentials</h2><div class="mt-4 space-y-2">@foreach($providers as $provider)<div class="flex justify-between rounded border border-slate-800 p-3"><span>{{ $provider->label }} <small class="text-slate-500">{{ $provider->maskedKey() }}</small></span><form method="POST" action="{{ route('dashboard.provider-credentials.toggle',$provider) }}">@csrf<button class="text-cyan-200">{{ $provider->active?'Deaktivieren':'Aktivieren' }}</button></form></div>@endforeach</div></section></div>
    @elseif($page === 'telemetry')
        <div class="grid gap-4 md:grid-cols-4">@foreach($telemetry as $label=>$value)<div class="luczor-card p-4"><small class="text-slate-500">{{ str_replace('_',' ',$label) }}</small><div class="mt-2 text-xl text-cyan-100">{{ $value }}</div></div>@endforeach</div><section class="mt-6 luczor-card p-5"><h2 class="font-semibold">Verlauf <span class="text-xs text-slate-500">(30 T)</span></h2>
            <svg viewBox="0 0 560 140" class="mt-3 w-full" preserveAspectRatio="none" role="img" aria-label="Läufe, Kosten und Erfolgsrate über 30 Tage">
                <line x1="0" y1="130" x2="560" y2="130" stroke="rgba(148,197,236,0.15)"/>
                @foreach(($telemetryCharts['bars'] ?? []) as $b)<rect x="{{ $b['x'] }}" y="{{ $b['y'] }}" width="{{ $b['w'] }}" height="{{ $b['h'] }}" fill="rgba(34,211,238,0.5)"><title>{{ $b['runs'] }} Läufe</title></rect>@endforeach
                @if(!empty($telemetryCharts['sr_points']))<polyline points="{{ $telemetryCharts['sr_points'] }}" fill="none" stroke="rgb(52,211,153)" stroke-width="1.5"/>@endif
                @if(!empty($telemetryCharts['cost_points']))<polyline points="{{ $telemetryCharts['cost_points'] }}" fill="none" stroke="rgb(245,158,11)" stroke-width="1.5"/>@endif
            </svg>
            <div class="mt-1 flex gap-4 text-xs text-slate-500"><span><span class="inline-block h-2 w-2 rounded-sm" style="background:rgba(34,211,238,.5)"></span> Läufe</span><span><span class="inline-block h-2 w-2 rounded-sm" style="background:rgb(52,211,153)"></span> Erfolgsrate</span><span><span class="inline-block h-2 w-2 rounded-sm" style="background:rgb(245,158,11)"></span> Kosten</span></div>
        </section>
        <div class="mt-6 flex gap-3"><a class="luczor-btn" href="{{ route('dashboard.telemetry.export',['format'=>'csv']) }}">CSV Export</a><a class="luczor-btn-secondary" href="{{ route('dashboard.telemetry.export',['format'=>'jsonl']) }}">JSONL Export</a></div>
        <section class="mt-6 luczor-card overflow-x-auto p-5"><h2 class="font-semibold">Modell-Telemetrie <span class="text-xs text-slate-500">(30 T)</span></h2><table class="mt-3 min-w-full text-left text-xs"><thead class="text-slate-500"><tr><th>Modell</th><th>Task</th><th>Runs</th><th>Erfolg</th><th>Tempo</th><th>Kosten</th></tr></thead><tbody>@foreach($modelTelemetry as $item)<tr class="border-t border-slate-800"><td class="py-2 text-cyan-100">{{ $item->model_id }}</td><td>{{ $item->task_type }}</td><td>{{ $item->runs }}</td><td>{{ number_format($item->success_rate*100,1) }}%</td><td>{{ round($item->avg_latency_ms) }} ms</td><td>${{ number_format($item->total_cost,6) }}</td></tr>@endforeach</tbody></table></section>
        <section class="mt-6 luczor-card overflow-x-auto p-5"><h2 class="font-semibold">Modell-Benchmark <span class="text-xs text-slate-500">(gemessenes Ranking)</span></h2><table class="mt-3 min-w-full text-left text-xs"><thead class="text-slate-500"><tr><th>Modell</th><th>Task</th><th>Läufe</th><th>Score</th><th>Erfolg</th><th>Ø Latenz</th><th>Kosten/Erfolg</th></tr></thead><tbody>@forelse($modelRankings as $r)<tr class="border-t border-slate-800"><td class="py-2 text-cyan-100">{{ $r->model_id }}</td><td>{{ $r->task_type }}</td><td>{{ $r->sample_count }}</td><td><div class="flex items-center gap-2"><div class="h-1.5 w-16 rounded bg-slate-800"><div class="h-1.5 rounded" style="width:{{ round(min(1,max(0,$r->score))*100) }}%;background:rgba(34,211,238,.7)"></div></div><span>{{ number_format($r->score,3) }}</span></div></td><td>{{ number_format(($r->success_rate ?? 0)*100,1) }}%</td><td>{{ round($r->avg_latency_ms) }} ms</td><td>${{ number_format($r->cost_per_success ?? 0,6) }}</td></tr>@empty<tr><td colspan="7" class="py-3 text-slate-500">Noch keine Rankings (mind. einige Läufe nötig).</td></tr>@endforelse</tbody></table></section>
    @elseif($page === 'workflows')
        @include('admin.workflows')
    @elseif($page === 'agents')
        <section class="luczor-card overflow-x-auto p-5"><h2 class="font-semibold">Agent-Läufe</h2>
            <table class="mt-3 min-w-full text-left text-xs"><thead class="text-slate-500"><tr><th>Ziel / Task</th><th>Status</th><th>Modell</th><th>Aufgaben</th><th>Gestartet</th></tr></thead>
            <tbody>@forelse($agentRuns as $run)<tr class="border-t border-slate-800"><td class="py-2"><span class="text-cyan-100">{{ \Illuminate\Support\Str::limit($run->goal ?? $run->task_type, 60) }}</span><div class="text-slate-500">{{ $run->task_type }}</div></td><td>{{ $run->status }}</td><td>{{ $run->model_id ?? '—' }}</td><td>{{ $run->tasks_count }}</td><td>{{ optional($run->started_at)->format('d.m. H:i') ?? '—' }}</td></tr>@empty<tr><td colspan="5" class="py-3 text-slate-500">Noch keine Agent-Läufe.</td></tr>@endforelse</tbody></table>
        </section>
        <section class="mt-6 luczor-card overflow-x-auto p-5"><h2 class="font-semibold">Ereignisprotokoll <span class="text-xs text-slate-500">(Audit)</span></h2>
            <table class="mt-3 min-w-full text-left text-xs"><thead class="text-slate-500"><tr><th>Ereignis</th><th>Tool</th><th>Ergebnis</th><th>Risiko</th><th>Zeit</th></tr></thead>
            <tbody>@forelse($agentEvents as $e)<tr class="border-t border-slate-800"><td class="py-2 text-cyan-100">{{ $e->event_type }}</td><td>{{ $e->tool ?? '—' }}</td><td class="{{ $e->outcome==='failed'?'text-rose-300':($e->outcome==='completed'?'text-emerald-300':'') }}">{{ $e->outcome ?? '—' }}</td><td>{{ $e->risk_level ?? '—' }}</td><td>{{ optional($e->created_at)->format('d.m. H:i:s') ?? '—' }}</td></tr>@empty<tr><td colspan="5" class="py-3 text-slate-500">Noch keine Ereignisse.</td></tr>@endforelse</tbody></table>
        </section>
    @elseif($page === 'archives')
        @include('admin.archives')
    @elseif($page === 'optimizer')
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="luczor-card p-5"><h2 class="font-semibold">Prompt-Version veröffentlichen</h2>
                <p class="mt-1 text-xs text-slate-500">Reihenfolge: luczor.system → Use-Case → Rollen-Regeln (nach Priorität) → Verlauf.</p>
                <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.prompt-templates.store') }}">@csrf
                    <input class="luczor-input" name="key" placeholder="Key (z. B. luczor.role.coder)" required>
                    <div class="grid grid-cols-3 gap-2">
                        <input class="luczor-input" name="role" placeholder="Rolle (chat|coder|planner|analyst|all)">
                        <input class="luczor-input" name="task_type" placeholder="Task-Type (optional)">
                        <input class="luczor-input" name="priority" type="number" value="100" placeholder="Priorität">
                    </div>
                    <textarea class="luczor-input font-mono text-xs" name="body" rows="6" placeholder="Prompt-Text / Regel" required></textarea>
                    <button class="luczor-btn">Veröffentlichen (neue Version)</button>
                </form>
            </section>
            <section class="luczor-card overflow-x-auto p-5"><h2 class="font-semibold">Prompt-Vorlagen</h2>
                <table class="mt-3 min-w-full text-left text-xs"><thead class="text-slate-500"><tr><th>Key</th><th>v</th><th>Rolle</th><th>Prio</th><th>Status</th></tr></thead>
                <tbody>@foreach($promptTemplates as $t)<tr class="border-t border-slate-800"><td class="py-1.5 text-cyan-100">{{ $t->key }}</td><td>{{ $t->version }}</td><td>{{ $t->role ?? '—' }}</td><td>{{ $t->priority ?? '—' }}</td><td class="{{ $t->status==='active'?'text-emerald-300':'text-slate-500' }}">{{ $t->status }}</td></tr>@endforeach</tbody></table>
            </section>
        </div>
        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <section class="luczor-card p-5"><h2 class="font-semibold">KI-Persönlichkeit anlegen</h2>
                <p class="mt-1 text-xs text-slate-500">Wird direkt nach dem System-Prompt injiziert (globale Persönlichkeit).</p>
                <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.personas.store') }}">@csrf
                    <input class="luczor-input" name="name" placeholder="Name (z. B. Sachlich-Knapp)" required>
                    <textarea class="luczor-input font-mono text-xs" name="prompt" rows="5" placeholder="Persönlichkeits-Prompt / Tonalität" required></textarea>
                    <button class="luczor-btn">Speichern</button>
                </form>
            </section>
            <section class="luczor-card overflow-x-auto p-5"><div class="flex items-center justify-between"><h2 class="font-semibold">Persönlichkeiten</h2><form method="POST" action="{{ route('dashboard.personas.deactivate') }}">@csrf<button class="luczor-btn-secondary">Keine aktiv</button></form></div>
                <div class="mt-3 space-y-2">@forelse($personas as $p)<div class="flex items-center justify-between rounded border border-slate-800 p-2"><div><b class="text-cyan-100">{{ $p->name }}</b>@if($p->active)<span class="ml-2 rounded border border-emerald-400/30 px-1.5 text-[10px] text-emerald-200">aktiv</span>@endif<div class="font-mono text-[10px] text-slate-600">{{ $p->slug }}</div></div>@unless($p->active)<form method="POST" action="{{ route('dashboard.personas.activate',$p) }}">@csrf<button class="luczor-btn-secondary">Aktivieren</button></form>@endunless</div>@empty<p class="text-xs text-slate-500">Noch keine Persönlichkeiten.</p>@endforelse</div>
            </section>
        </div>
        @include('admin.optimizer-extras')
        <section class="mt-6 luczor-card overflow-x-auto p-5"><h2 class="font-semibold">Netzwerk-Policies</h2>
            <div class="mt-3 grid gap-3 md:grid-cols-3">@foreach($networkPolicies as $policy)<div class="rounded border border-slate-800 p-3"><b>{{ $policy->name }}</b><div class="text-xs text-slate-500">{{ $policy->key }}</div></div>@endforeach</div>
        </section>
    @elseif($page === 'settings')
        <form method="POST" action="{{ route('dashboard.settings.store') }}">@csrf
        @forelse($settings->groupBy('group') as $group => $groupSettings)
            <section class="mt-6 luczor-card p-5"><h2 class="font-semibold capitalize">{{ $group }}</h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach($groupSettings as $s)
                    @php $v = $s->value['v'] ?? null; @endphp
                    <div class="rounded border border-slate-800 p-3">
                        <div class="text-sm text-slate-200">{{ $s->label ?? $s->key }}</div>
                        <div class="font-mono text-[10px] text-slate-600">{{ $s->key }}</div>
                        @if($s->key === 'voice_stt_engine')
                            <select class="luczor-input mt-2" name="settings[{{ $s->key }}]">
                                <option value="whisper_local" @selected($v==='whisper_local')>whisper_local (whisper.cpp, Standard)</option>
                                <option value="whisper_rs" @selected($v==='whisper_rs')>whisper_rs (nativer Build, benötigt CMake)</option>
                            </select>
                        @elseif($s->key === 'hands_free_strategy')
                            <select class="luczor-input mt-2" name="settings[{{ $s->key }}]">
                                <option value="safeword" @selected($v==='safeword')>Safeword (Aktivierungs-/Beendigungsphrase)</option>
                                <option value="continuous" @selected($v==='continuous')>Dauerzuhören (Auto-Abschluss nach Pause)</option>
                            </select>
                        @elseif($s->key === 'voice_interrupt_mode')
                            <select class="luczor-input mt-2" name="settings[{{ $s->key }}]">
                                <option value="on_speech" @selected($v==='on_speech')>Bei Sprache (Barge-in)</option>
                                <option value="on_activation_phrase" @selected($v==='on_activation_phrase')>Nur bei Aktivierungsphrase</option>
                                <option value="off" @selected($v==='off')>Aus</option>
                            </select>
                        @elseif($s->type === 'bool')
                            <label class="mt-2 inline-flex items-center gap-2 text-sm text-slate-400"><input type="checkbox" name="settings[{{ $s->key }}]" value="1" @checked($v)> aktiv</label>
                        @elseif($s->type === 'number')
                            <input class="luczor-input mt-2" type="number" step="any" name="settings[{{ $s->key }}]" value="{{ $v }}">
                        @else
                            <input class="luczor-input mt-2" type="text" name="settings[{{ $s->key }}]" value="{{ $v }}">
                        @endif
                    </div>
                @endforeach
                </div>
            </section>
        @empty
            <section class="luczor-card p-6"><p class="text-slate-400">Noch keine Server-Einstellungen vorhanden.</p></section>
        @endforelse
            <div class="mt-6"><button class="luczor-btn">Alle Einstellungen speichern</button></div>
        </form>
    @else
        <section class="luczor-card p-6"><h2 class="font-semibold">{{ ucfirst(str_replace('-',' ',$page)) }}</h2><p class="mt-2 text-slate-400">Dieser Bereich ist als eigene Verwaltungsseite angelegt. Die zugehoerigen Daten werden serverseitig geladen.</p><div class="mt-5 grid gap-3 md:grid-cols-3">@if($page==='devices')@foreach($devices as $device)<div class="rounded border border-slate-800 p-3"><b>{{ $device->name ?: $device->device_id }}</b><form class="mt-3" method="POST" action="{{ route('dashboard.devices.debug.request',$device) }}">@csrf<button class="luczor-btn-secondary">Debug anfordern</button></form></div>@endforeach@endif @if($page==='archives')@foreach($archiveCounts as $key=>$count)<div class="rounded border border-slate-800 p-3"><small>{{ $key }}</small><div class="text-xl text-cyan-100">{{ $count }}</div></div>@endforeach@endif @if($page==='api-keys')@foreach($apiKeys as $key)<div class="rounded border border-slate-800 p-3"><b>{{ $key->name }}</b><div class="text-xs text-slate-500">{{ $key->device_id }}</div></div>@endforeach@endif @if($page==='settings')@foreach($settings as $setting)<div class="rounded border border-slate-800 p-3"><b>{{ $setting->label }}</b><div class="text-xs text-slate-500">{{ $setting->key }}</div></div>@endforeach@endif @if($page==='optimizer')@foreach($networkPolicies as $policy)<div class="rounded border border-slate-800 p-3"><b>{{ $policy->name }}</b><div class="text-xs text-slate-500">{{ $policy->key }}</div></div>@endforeach@endif @if($page==='experiments')@foreach($llmExperiments as $experiment)<div class="rounded border border-slate-800 p-3"><b>{{ $experiment->name }}</b><div class="text-xs text-slate-500">{{ $experiment->status }}</div></div>@endforeach@endif</div></section>
    @endif
</x-app-layout>
