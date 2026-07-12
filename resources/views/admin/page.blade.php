<x-app-layout>
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div><div class="font-mono text-xs uppercase tracking-[.2em] text-cyan-300/70">Admin / {{ $page }}</div><h1 class="mt-2 text-2xl font-semibold text-white">{{ match($page) {'overview'=>'System Overview','providers'=>'Provider & Preise','models'=>'Modelle & Routing','telemetry'=>'Telemetry & Kosten','optimizer'=>'Prompt, Kontext & Policy','experiments'=>'Modell-Experimente','devices'=>'Geraete-Debug','api-keys'=>'Geraete & Keys','archives'=>'Archive & Audit','settings'=>'Server Settings'} }}</h1></div>
        @if(session('status'))<div class="rounded border border-emerald-400/30 bg-emerald-400/10 px-4 py-2 text-sm text-emerald-100">{{ session('status') }}</div>@endif
    </div>
    @if($errors->any())<div class="mb-6 rounded border border-rose-400/30 bg-rose-400/10 p-4 text-sm text-rose-100">{{ $errors->first() }}</div>@endif

    @if($page === 'overview')
        <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">@foreach($operations as $label=>$value)<div class="luczor-card p-4"><div class="text-xs uppercase tracking-wider text-slate-500">{{ str_replace('_',' ',$label) }}</div><div class="mt-2 text-2xl font-semibold text-cyan-100">{{ $value }}</div></div>@endforeach</div>
        <div class="mt-8 grid gap-4 md:grid-cols-3"><a href="{{ route('admin.page','models') }}" class="luczor-card p-5 hover:border-cyan-400/40"><b>Modelle verwalten</b><p class="mt-2 text-sm text-slate-400">Free-Model-Ketten, Fallbacks und Messwerte.</p></a><a href="{{ route('admin.page','telemetry') }}" class="luczor-card p-5 hover:border-cyan-400/40"><b>Telemetry auswerten</b><p class="mt-2 text-sm text-slate-400">Kosten, Geschwindigkeit und Erfolgsrate.</p></a><a href="{{ route('admin.page','devices') }}" class="luczor-card p-5 hover:border-cyan-400/40"><b>Geraete debuggen</b><p class="mt-2 text-sm text-slate-400">Stille Debug-Anforderung und Download.</p></a></div>
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
                            <span class="text-xs text-slate-500">Einträge ziehen zum Sortieren</span>
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

  list.querySelectorAll('li[draggable]').forEach(function(li){
    li.addEventListener('dragstart', function(e){
      if (e.target.closest('[data-entry-actions]')) {
        e.preventDefault();
        return;
      }

      dragEl = li;
      li.style.opacity = '.4';
    });

    li.addEventListener('dragend', function(){
      if (!dragEl) return;

      li.style.opacity = '';
      submitChain(list);
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

function submitChain(list){
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

  form.submit();
}
</script>
        </section>
    @elseif($page === 'providers')
        <div class="grid gap-6 lg:grid-cols-2"><section class="luczor-card p-5"><h2 class="font-semibold">Provider Credential</h2><form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.provider-credentials.store') }}">@csrf<input class="luczor-input" name="provider" value="openrouter"><input class="luczor-input" name="label" placeholder="Label" required><input class="luczor-input" name="api_key" placeholder="API-Key (verschluesselt)" required><input class="luczor-input" name="base_url" value="https://openrouter.ai/api/v1"><button class="luczor-btn">Speichern</button></form></section><section class="luczor-card p-5"><h2 class="font-semibold">Aktive Credentials</h2><div class="mt-4 space-y-2">@foreach($providers as $provider)<div class="flex justify-between rounded border border-slate-800 p-3"><span>{{ $provider->label }} <small class="text-slate-500">{{ $provider->maskedKey() }}</small></span><form method="POST" action="{{ route('dashboard.provider-credentials.toggle',$provider) }}">@csrf<button class="text-cyan-200">{{ $provider->active?'Deaktivieren':'Aktivieren' }}</button></form></div>@endforeach</div></section></div>
    @elseif($page === 'telemetry')
        <div class="grid gap-4 md:grid-cols-4">@foreach($telemetry as $label=>$value)<div class="luczor-card p-4"><small class="text-slate-500">{{ str_replace('_',' ',$label) }}</small><div class="mt-2 text-xl text-cyan-100">{{ $value }}</div></div>@endforeach</div><div class="mt-6 flex gap-3"><a class="luczor-btn" href="{{ route('dashboard.telemetry.export',['format'=>'csv']) }}">CSV Export</a><a class="luczor-btn-secondary" href="{{ route('dashboard.telemetry.export',['format'=>'jsonl']) }}">JSONL Export</a></div><section class="mt-6 luczor-card overflow-x-auto p-5"><table class="min-w-full text-left text-xs"><thead class="text-slate-500"><tr><th>Modell</th><th>Task</th><th>Runs</th><th>Erfolg</th><th>Tempo</th><th>Kosten</th></tr></thead><tbody>@foreach($modelTelemetry as $item)<tr class="border-t border-slate-800"><td class="py-2 text-cyan-100">{{ $item->model_id }}</td><td>{{ $item->task_type }}</td><td>{{ $item->runs }}</td><td>{{ number_format($item->success_rate*100,1) }}%</td><td>{{ round($item->avg_latency_ms) }} ms</td><td>${{ number_format($item->total_cost,6) }}</td></tr>@endforeach</tbody></table></section>
    @else
        <section class="luczor-card p-6"><h2 class="font-semibold">{{ ucfirst(str_replace('-',' ',$page)) }}</h2><p class="mt-2 text-slate-400">Dieser Bereich ist als eigene Verwaltungsseite angelegt. Die zugehoerigen Daten werden serverseitig geladen.</p><div class="mt-5 grid gap-3 md:grid-cols-3">@if($page==='devices')@foreach($devices as $device)<div class="rounded border border-slate-800 p-3"><b>{{ $device->name ?: $device->device_id }}</b><form class="mt-3" method="POST" action="{{ route('dashboard.devices.debug.request',$device) }}">@csrf<button class="luczor-btn-secondary">Debug anfordern</button></form></div>@endforeach@endif @if($page==='archives')@foreach($archiveCounts as $key=>$count)<div class="rounded border border-slate-800 p-3"><small>{{ $key }}</small><div class="text-xl text-cyan-100">{{ $count }}</div></div>@endforeach@endif @if($page==='api-keys')@foreach($apiKeys as $key)<div class="rounded border border-slate-800 p-3"><b>{{ $key->name }}</b><div class="text-xs text-slate-500">{{ $key->device_id }}</div></div>@endforeach@endif @if($page==='settings')@foreach($settings as $setting)<div class="rounded border border-slate-800 p-3"><b>{{ $setting->label }}</b><div class="text-xs text-slate-500">{{ $setting->key }}</div></div>@endforeach@endif @if($page==='optimizer')@foreach($networkPolicies as $policy)<div class="rounded border border-slate-800 p-3"><b>{{ $policy->name }}</b><div class="text-xs text-slate-500">{{ $policy->key }}</div></div>@endforeach@endif @if($page==='experiments')@foreach($llmExperiments as $experiment)<div class="rounded border border-slate-800 p-3"><b>{{ $experiment->name }}</b><div class="text-xs text-slate-500">{{ $experiment->status }}</div></div>@endforeach@endif</div></section>
    @endif
</x-app-layout>
