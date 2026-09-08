<x-ui.panel title="Fallback-Ketten">
            <form class="mt-4 grid gap-3 md:grid-cols-4" method="POST" action="{{ route('dashboard.model-use-case-entries.store') }}">
                @csrf
                <x-ui.select aria-label="Anwendungsfall" name="model_use_case_id">
                    @foreach($modelUseCases as $case)<option value="{{ $case->id }}">{{ $case->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.select aria-label="Modellprofil" name="model_profile_id">
                    @foreach($modelProfiles as $profile)<option value="{{ $profile->id }}">{{ $profile->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.input class="" name="sort_order" type="number" value="1" aria-label="Position in der Kette" />
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
