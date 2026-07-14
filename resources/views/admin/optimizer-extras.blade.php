{{-- SOLL §15 P27 — Skill-System, Planning-Engine und Reflexionen auf der Optimizer-Seite. --}}

{{-- Skill-System --}}
<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <section class="luczor-card p-5"><h2 class="font-semibold">Skill anlegen <span class="text-xs text-slate-500">(wiederverwendbares Bündel)</span></h2>
        <p class="mt-1 text-xs text-slate-500">Prompt-Skill = wiederverwendbarer Instruktionsbaustein · Workflow-Skill = benannter, startbarer Ablauf.</p>
        <form class="mt-4 space-y-3" method="POST" action="{{ route('dashboard.skills.store') }}" x-data="{ kind: 'prompt' }">@csrf
            <input class="luczor-input" name="name" placeholder="Name (z. B. Code-Review-Bündel)" required maxlength="120">
            <select class="luczor-input" name="kind" x-model="kind">
                <option value="prompt">Prompt-Skill (Instruktionsbaustein)</option>
                <option value="workflow">Workflow-Skill (startbarer Ablauf)</option>
            </select>
            <input class="luczor-input" name="description" placeholder="Kurzbeschreibung (optional)" maxlength="2000">
            <textarea class="luczor-input font-mono text-xs" name="prompt" rows="4" placeholder="Prompt-/Instruktionstext" x-show="kind==='prompt'"></textarea>
            <select class="luczor-input" name="workflow_definition_id" x-show="kind==='workflow'" x-cloak>
                <option value="">— Workflow wählen —</option>
                @foreach($skillWorkflows as $wf)<option value="{{ $wf->id }}">{{ $wf->name }}</option>@endforeach
            </select>
            <input class="luczor-input" name="tags" placeholder="Tags, kommagetrennt (optional)" maxlength="500">
            <button class="luczor-btn">Skill speichern</button>
        </form>
    </section>
    <section class="luczor-card overflow-x-auto p-5"><h2 class="font-semibold">Skills</h2>
        <div class="mt-3 space-y-2">@forelse($skills as $skill)
            <div class="rounded border border-slate-800 p-3">
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
                        <form method="POST" action="{{ route('dashboard.skills.run', $skill) }}">@csrf<button class="luczor-btn-secondary !px-2 !py-1 text-xs" @unless($skill->active) disabled @endunless>{{ $skill->kind === 'workflow' ? 'Starten' : 'Anwenden' }}</button></form>
                        <details class="relative">
                            <summary class="cursor-pointer list-none rounded border border-slate-700 px-2 py-1 text-xs text-slate-300 hover:bg-slate-800">⋮</summary>
                            <div class="absolute right-0 z-20 mt-1 w-40 rounded border border-slate-700 bg-slate-950 p-1 shadow-xl">
                                <form method="POST" action="{{ route('dashboard.skills.toggle', $skill) }}">@csrf<button class="block w-full rounded px-3 py-2 text-left text-xs text-cyan-100 hover:bg-cyan-400/10">{{ $skill->active ? 'Deaktivieren' : 'Aktivieren' }}</button></form>
                                <form method="POST" action="{{ route('dashboard.skills.destroy', $skill) }}" onsubmit="return confirm('Skill „{{ $skill->name }}“ löschen?');">@csrf @method('DELETE')<button class="block w-full rounded px-3 py-2 text-left text-xs text-rose-300 hover:bg-rose-400/10">Löschen</button></form>
                            </div>
                        </details>
                    </div>
                </div>
            </div>
        @empty<p class="text-xs text-slate-500">Noch keine Skills.</p>@endforelse</div>
    </section>
</div>

{{-- Planning-Engine --}}
<section class="mt-6 luczor-card p-5"><h2 class="font-semibold">Planning-Engine <span class="text-xs text-slate-500">(Ziel → Workflow-Entwurf)</span></h2>
    <p class="mt-1 text-xs text-slate-500">Erzeugt aus einem Ziel einen validierten Workflow-Entwurf (Kontext → LLM → Review, optional mit Geräte-Recherche). Danach im Board verfeinern.</p>
    <form class="mt-4 grid gap-3 md:grid-cols-[1fr_auto_auto]" method="POST" action="{{ route('dashboard.workflows.plan') }}">@csrf
        <input class="luczor-input" name="goal" placeholder="Ziel (z. B. „Wettbewerber-Preise recherchieren und zusammenfassen“)" required maxlength="500">
        <label class="inline-flex items-center gap-2 self-center text-xs text-slate-400"><input type="checkbox" name="include_research" value="1"> Geräte-Recherche</label>
        <button class="luczor-btn">Planen &amp; öffnen</button>
    </form>
</section>

{{-- Advisory-Review-Konfiguration je Anwendungsfall --}}
<section class="mt-6 luczor-card overflow-x-auto p-5"><h2 class="font-semibold">Advisory-Review <span class="text-xs text-slate-500">(Self-Review-Policy je Anwendungsfall, §10)</span></h2>
    <p class="mt-1 text-xs text-slate-500">Ist Review aktiv, signalisiert der Proxy per Header <code>X-Luczor-Review-Enabled</code> einen zweiten Prüf-Durchlauf über den gewählten Review-Anwendungsfall.</p>
    <table class="mt-3 min-w-full text-left text-xs"><thead class="text-slate-500"><tr><th class="pb-1">Anwendungsfall</th><th class="pb-1">Review aktiv</th><th class="pb-1">Review-Anwendungsfall</th><th class="pb-1"></th></tr></thead>
    <tbody>@foreach($modelUseCases as $uc)<tr class="border-t border-slate-800">
        <form method="POST" action="{{ route('dashboard.model-use-cases.review', $uc) }}">@csrf
            <td class="py-1.5 text-cyan-100">{{ $uc->name }}<div class="font-mono text-[10px] text-slate-600">{{ $uc->slug }}</div></td>
            <td><label class="inline-flex items-center gap-2"><input type="checkbox" name="review_enabled" value="1" @checked($uc->review_enabled)> aktiv</label></td>
            <td><select class="luczor-input !mt-0" name="review_use_case_id"><option value="">— keiner —</option>@foreach($modelUseCases as $target)@if($target->id !== $uc->id)<option value="{{ $target->id }}" @selected($uc->review_use_case_id === $target->id)>{{ $target->name }}</option>@endif@endforeach</select></td>
            <td><button class="luczor-btn-secondary !px-3 !py-1 text-xs">Speichern</button></td>
        </form>
    </tr>@endforeach</tbody></table>
</section>

{{-- Reflexionen / Advisory-Reviews --}}
<section class="mt-6 luczor-card overflow-x-auto p-5"><h2 class="font-semibold">Reflexionen &amp; Reviews <span class="text-xs text-slate-500">(Self-Review nach Antwort, §10)</span></h2>
    <table class="mt-3 min-w-full text-left text-xs"><thead class="text-slate-500"><tr><th class="pb-1">Evaluator</th><th class="pb-1">Status</th><th class="pb-1">Qualität</th><th class="pb-1">Tests</th><th class="pb-1">Sicherheit</th><th class="pb-1">Notiz</th><th class="pb-1">Zeit</th></tr></thead>
    <tbody>@forelse($reflections as $r)<tr class="border-t border-slate-800">
        <td class="py-1.5 text-cyan-100">{{ $r->evaluator_id ?? '—' }}</td>
        <td class="{{ $r->status === 'passed' ? 'text-emerald-300' : ($r->status === 'failed' ? 'text-rose-300' : '') }}">{{ $r->status ?? '—' }}</td>
        <td>@if($r->quality_score !== null)<div class="flex items-center gap-1"><div class="h-1.5 w-12 rounded bg-slate-800"><div class="h-1.5 rounded" style="width:{{ round(min(1,max(0,$r->quality_score))*100) }}%;background:rgba(34,211,238,.7)"></div></div><span>{{ number_format($r->quality_score,2) }}</span></div>@else—@endif</td>
        <td>{{ $r->test_pass_rate !== null ? number_format($r->test_pass_rate*100,0).'%' : '—' }}</td>
        <td>{{ $r->security_score !== null ? number_format($r->security_score,2) : '—' }}</td>
        <td class="max-w-[280px] truncate text-slate-400" title="{{ $r->notes }}">{{ \Illuminate\Support\Str::limit($r->notes, 60) ?: '—' }}</td>
        <td>{{ optional($r->created_at)->format('d.m. H:i') ?? '—' }}</td>
    </tr>@empty<tr><td colspan="7" class="py-3 text-slate-500">Noch keine Reflexionen/Reviews. Aktiviere Advisory-Review an einem Anwendungsfall (review_enabled).</td></tr>@endforelse</tbody></table>
</section>
