{{-- SOLL §15 P27 — Skill-System, Planning-Engine und Reflexionen auf der Optimizer-Seite. --}}

{{-- Skill-System --}}
<div class="grid gap-6 lg:grid-cols-2">
    <x-ui.panel title="Skill anlegen (wiederverwendbares Bündel)">
        <p class="mt-1 text-xs text-slate-500">Aktive Prompt-Skills werden automatisch als Anweisungen verwendet. Workflow-Skills sind benannte, ausdrücklich startbare Abläufe.</p>
        <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.skills.store') }}" x-data="{ kind: 'prompt' }">@csrf
            <label class="ui-field">Name
<x-ui.input name="name" placeholder="Name (z. B. Code-Review-Bündel)" required maxlength="120" />
</label>
            <label class="ui-field">Skill-Typ
<x-ui.select name="kind" x-model="kind">
                <option value="prompt">Prompt-Skill (Instruktionsbaustein)</option>
                <option value="workflow">Workflow-Skill (startbarer Ablauf)</option>
            </x-ui.select>
</label>
            <label class="ui-field">Kurzbeschreibung (optional)
<x-ui.input name="description" placeholder="Kurzbeschreibung (optional)" maxlength="2000" />
</label>
            <label class="ui-field" x-show="kind==='prompt'" x-cloak>Anweisungen
<x-ui.textarea class="font-mono text-xs" name="prompt" rows="4" placeholder="Prompt-/Instruktionstext" x-show="kind==='prompt'"></x-ui.textarea>
</label>
            <label class="ui-field" x-show="kind==='workflow'" x-cloak>Workflow
<x-ui.select name="workflow_definition_id" x-show="kind==='workflow'" x-cloak>
                <option value="">— Workflow wählen —</option>
                @foreach($skillWorkflows as $wf)<option value="{{ $wf->id }}">{{ $wf->name }}</option>@endforeach
            </x-ui.select>
</label>
            <label class="ui-field">Tags, kommagetrennt
<x-ui.input name="tags" placeholder="Tags, kommagetrennt (optional)" maxlength="500" />
</label>
            <x-ui.button type="submit" variant="primary">Skill speichern</x-ui.button>
        </form>
    </x-ui.panel>
    <x-ui.panel title="Skills">
        <div class="mt-3 space-y-2">@forelse($skills as $skill)
            <div class="ui-record">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <b class="text-cyan-100">{{ $skill->name }}</b>
                            <span class="rounded-full px-1.5 text-[10px] font-semibold ring-1 {{ $skill->kind === 'workflow' ? 'bg-fuchsia-400/10 text-fuchsia-200 ring-fuchsia-400/30' : 'bg-cyan-400/10 text-cyan-200 ring-cyan-400/30' }}">{{ $skill->kind }}</span>
                            @if($skill->active)<span class="rounded-full bg-emerald-400/10 px-1.5 text-[10px] text-emerald-200 ring-1 ring-emerald-400/30">aktiv</span>@else<span class="rounded-full bg-slate-500/10 px-1.5 text-[10px] text-slate-400 ring-1 ring-slate-500/30">inaktiv</span>@endif
                        </div>
                        @if($skill->description)<p class="mt-1 text-xs text-slate-400">{{ $skill->description }}</p>@endif
                        <div class="mt-1 font-mono text-[10px] text-slate-600">{{ $skill->slug }} · {{ $skill->use_count }}× genutzt{{ $skill->kind === 'workflow' && $skill->workflowDefinition ? ' · '.$skill->workflowDefinition->name : '' }}</div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        @if($skill->kind === 'workflow')<form method="POST" action="{{ route('dashboard.skills.run', $skill) }}">@csrf<x-ui.button type="submit" variant="secondary" :disabled="! $skill->active">Starten</x-ui.button>
</form>@endif
                        <details class="relative">
                            <summary aria-label="Aktionen für {{ $skill->name }}" class="cursor-pointer list-none rounded border border-slate-700 px-2 py-1 text-xs text-slate-300 hover:bg-slate-800">⋮</summary>
                            <div class="absolute right-0 z-20 mt-1 w-40 rounded border border-slate-700 bg-slate-950 p-1 ">
                                <form method="POST" action="{{ route('dashboard.skills.toggle', $skill) }}">@csrf<button class="block w-full rounded px-3 py-2 text-left text-xs text-cyan-100 hover:bg-cyan-400/10">{{ $skill->active ? 'Deaktivieren' : 'Aktivieren' }}</button></form>
                                <form method="POST" action="{{ route('dashboard.skills.destroy', $skill) }}" onsubmit="return confirm('Skill „{{ $skill->name }}“ löschen?');">@csrf @method('DELETE')<button class="block w-full rounded px-3 py-2 text-left text-xs text-rose-300 hover:bg-rose-400/10">Löschen</button></form>
                            </div>
                        </details>
                    </div>
                </div>
                <p class="mt-2 text-xs text-slate-500">{{ $skill->user_id === null ? 'Global für alle Nutzer' : 'Nutzergebundener Skill' }}{{ $skill->kind === 'prompt' && $skill->active ? ' · wird automatisch eingebunden' : '' }}</p>
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm text-cyan-200">Inhalt ansehen und bearbeiten</summary>
                    <form class="mt-3 space-y-3" method="POST" action="{{ route('dashboard.skills.update', $skill) }}">@csrf @method('PATCH')
                        <label class="block text-xs text-slate-400">Name<x-ui.input class="mt-1" name="name" value="{{ $skill->name }}" required maxlength="120" /></label>
                        <label class="block text-xs text-slate-400">Kurzbeschreibung<x-ui.textarea class="mt-1 text-sm" name="description" rows="2" maxlength="2000">{{ $skill->description }}</x-ui.textarea>
</label>
                        @if($skill->kind === 'prompt')
                            <label class="block text-xs text-slate-400">Anweisungen<x-ui.textarea class="mt-1 text-sm" name="prompt" rows="8" required maxlength="20000">{{ $skill->prompt }}</x-ui.textarea>
</label>
                        @else
                            <label class="block text-xs text-slate-400">Workflow<x-ui.select class="mt-1" name="workflow_definition_id" required>
                                @foreach($skillWorkflows as $wf)<option value="{{ $wf->id }}" @selected($skill->workflow_definition_id === $wf->id)>{{ $wf->name }}</option>@endforeach
                            </x-ui.select>
</label>
                        @endif
                        <label class="block text-xs text-slate-400">Tags, kommagetrennt<x-ui.input class="mt-1" name="tags" value="{{ implode(', ', $skill->tags ?? []) }}" maxlength="500" /></label>
                        <x-ui.button type="submit" variant="primary">Änderungen speichern</x-ui.button>
                    </form>
                </details>
            </div>
        @empty<p class="text-xs text-slate-500">Noch keine Skills.</p>@endforelse</div>
    </x-ui.panel>
</div>
