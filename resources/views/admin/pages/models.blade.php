<div class="grid gap-6 xl:grid-cols-[.8fr_1.2fr]"><x-ui.panel title="Neues Modellprofil"><p class="mt-1 text-sm text-slate-400">Nur Admins bestimmen Modelle und Fallbacks.</p><form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.model-profiles.store') }}">@csrf<x-ui.input class="" name="name" placeholder="Anzeigename" required aria-label="name" /><x-ui.input class="" name="provider" value="openrouter" required aria-label="provider" /><x-ui.select class="" name="provider_credential_id" required><option value="">Explizites Provider-Credential</option>@foreach($providers->where('active', true) as $credential)<option value="{{ $credential->id }}">{{ $credential->provider }} · {{ $credential->label }}</option>@endforeach</x-ui.select><x-ui.input class="" name="model_id" placeholder="nvidia/...:free" required aria-label="model id" /><div class="grid gap-3 sm:grid-cols-3"><x-ui.input class="" name="temperature" value="0.2" type="number" step="0.05" min="0" max="2" aria-label="temperature" /><x-ui.input class="" name="max_tokens" value="2200" type="number" min="1" aria-label="max tokens" /><x-ui.input class="" name="purpose" value="chat" aria-label="purpose" /></div><x-ui.textarea class=" font-mono text-xs" name="capabilities" rows="2" placeholder='["chat","tools"]'>["chat"]</x-ui.textarea><x-ui.input class="" name="context_window" type="number" min="1" max="2000000" placeholder="Kontextfenster in Tokens" aria-label="context window" /><x-ui.button class="" type="submit" variant="primary">Profil anlegen</x-ui.button></form></x-ui.panel>
        <x-ui.panel title="Profile"><x-ui.table><thead class="text-xs uppercase text-slate-500"><tr><th>Name / Modell</th><th>Zweck</th><th>Status</th><th></th></tr></thead><tbody class="divide-y divide-slate-800">@foreach($modelProfiles as $profile)<tr><td class="py-3"><b class="text-cyan-100">{{ $profile->name }}</b><div class="font-mono text-xs text-slate-500">{{ $profile->model_id }}</div></td><td>{{ $profile->purpose }}</td><td>{{ $profile->active?'aktiv':'aus' }}</td><td><details><summary class="cursor-pointer text-cyan-200">Bearbeiten</summary><form class="mt-3 grid gap-2" method="POST" action="{{ route('dashboard.model-profiles.update',$profile) }}">@csrf @method('PUT')<x-ui.input class="" name="name" value="{{ $profile->name }}" aria-label="name" /><x-ui.input class="" name="provider" value="{{ $profile->provider }}" aria-label="provider" /><x-ui.select class="" name="provider_credential_id" required>@foreach($providers->where('active', true) as $credential)<option value="{{ $credential->id }}" @selected($profile->provider_credential_id === $credential->id)>{{ $credential->provider }} · {{ $credential->label }}</option>@endforeach</x-ui.select><x-ui.input class="" name="model_id" value="{{ $profile->model_id }}" aria-label="model id" /><div class="grid gap-3 sm:grid-cols-3"><x-ui.input class="" name="temperature" value="{{ $profile->temperature }}" aria-label="temperature" /><x-ui.input class="" name="max_tokens" value="{{ $profile->max_tokens }}" aria-label="max tokens" /><x-ui.input class="" name="purpose" value="{{ $profile->purpose }}" aria-label="purpose" /></div><x-ui.textarea class=" font-mono text-xs" name="capabilities" rows="2">{{ json_encode($profile->capabilities ?? []) }}</x-ui.textarea><x-ui.input class="" name="context_window" type="number" min="1" max="2000000" value="{{ $profile->context_window }}" placeholder="Kontextfenster in Tokens" aria-label="context window" /><x-ui.button class="" type="submit" variant="primary">Speichern</x-ui.button></form><form class="mt-2 inline" method="POST" action="{{ route('dashboard.model-profiles.toggle',$profile) }}">@csrf<x-ui.button class="" type="submit" variant="secondary">{{ $profile->active?'Deaktivieren':'Aktivieren' }}</x-ui.button></form><form class="ml-2 inline" method="POST" action="{{ route('dashboard.model-profiles.destroy',$profile) }}">@csrf @method('DELETE')<button class="text-rose-300">Loeschen</button></form></details></td></tr>@endforeach</tbody></x-ui.table></x-ui.panel></div>
        <x-ui.panel title="Fallback-Ketten">
            <form class="mt-4 grid gap-3 md:grid-cols-4" method="POST" action="{{ route('dashboard.model-use-case-entries.store') }}">
                @csrf
                <x-ui.select class="" name="model_use_case_id">
                    @foreach($modelUseCases as $case)<option value="{{ $case->id }}">{{ $case->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.select class="" name="model_profile_id">
                    @foreach($modelProfiles as $profile)<option value="{{ $profile->id }}">{{ $profile->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.input class="" name="sort_order" type="number" value="1" aria-label="sort order" />
                <x-ui.button class="" type="submit" variant="primary">Zur Kette</x-ui.button>
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
        </x-ui.panel>
