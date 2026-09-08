{{-- Advisory-Review-Konfiguration je Anwendungsfall --}}
<x-ui.panel title="Advisory-Review je Anwendungsfall">
    <p class="mt-1 text-xs text-slate-500">Ist Review aktiv, signalisiert der Proxy per Header <code>X-Luczor-Review-Enabled</code> einen zweiten Prüf-Durchlauf über den gewählten Review-Anwendungsfall.</p>
    <x-ui.table>
<thead class="text-slate-500">
<tr>
<th class="pb-1">Anwendungsfall</th>
<th class="pb-1">Review aktiv</th>
<th class="pb-1">Review-Anwendungsfall</th>
<th class="pb-1"></th>
</tr>
</thead>
    <tbody>@foreach($modelUseCases as $uc)<tr>
            <td class="py-1.5 text-cyan-100">{{ $uc->name }}<div class="font-mono text-[10px] text-slate-600">{{ $uc->slug }}</div></td>
            <td>
<label class="inline-flex items-center gap-2"><input type="checkbox" form="review-policy-{{ $uc->id }}" name="review_enabled" value="1" @checked($uc->review_enabled)> aktiv</label></td>
            <td>
<x-ui.select class="!mt-0" form="review-policy-{{ $uc->id }}" name="review_use_case_id" aria-label="Review-Anwendungsfall für {{ $uc->name }}"><option value="">— keiner —</option>@foreach($modelUseCases as $target)@if($target->id !== $uc->id)<option value="{{ $target->id }}" @selected($uc->review_use_case_id === $target->id)>{{ $target->name }}</option>@endif@endforeach</x-ui.select>
</td>
            <td>
<form id="review-policy-{{ $uc->id }}" method="POST" action="{{ route('dashboard.model-use-cases.review', $uc) }}">@csrf<x-ui.button class="!px-3 !py-1 text-xs" type="submit" variant="secondary">Speichern</x-ui.button>
</form>
</td>
    </tr>@endforeach</tbody>
</x-ui.table>
</x-ui.panel>

{{-- Reflexionen / Advisory-Reviews --}}
<x-ui.panel title="Reflexionen &amp; Reviews">
    <x-ui.table>
<thead class="text-slate-500">
<tr>
<th class="pb-1">Evaluator</th>
<th class="pb-1">Status</th>
<th class="pb-1">Qualität</th>
<th class="pb-1">Tests</th>
<th class="pb-1">Sicherheit</th>
<th class="pb-1">Notiz</th>
<th class="pb-1">Zeit</th>
</tr>
</thead>
    <tbody>@forelse($reflections as $r)<tr>
        <td class="py-1.5 text-cyan-100">{{ $r->evaluator_id ?? '—' }}</td>
        <td class="{{ $r->status === 'passed' ? 'text-emerald-300' : ($r->status === 'failed' ? 'text-rose-300' : '') }}">{{ $r->status ?? '—' }}</td>
        <td>@if($r->quality_score !== null)<div class="flex items-center gap-1">
<div class="h-1.5 w-12 rounded bg-slate-800">
<div class="h-1.5 rounded" style="width:{{ round(min(1,max(0,$r->quality_score))*100) }}%;background:rgba(34,211,238,.7)"></div></div><span>{{ number_format($r->quality_score,2) }}</span></div>@else—@endif</td>
        <td>{{ $r->test_pass_rate !== null ? number_format($r->test_pass_rate*100,0).'%' : '—' }}</td>
        <td>{{ $r->security_score !== null ? number_format($r->security_score,2) : '—' }}</td>
        <td class="max-w-[280px] truncate text-slate-400" title="{{ $r->notes }}">{{ \Illuminate\Support\Str::limit($r->notes, 60) ?: '—' }}</td>
        <td>{{ optional($r->created_at)->format('d.m. H:i') ?? '—' }}</td>
    </tr>@empty<tr>
<td colspan="7" class="py-3 text-slate-500">Noch keine Reflexionen/Reviews. Aktiviere Advisory-Review an einem Anwendungsfall (review_enabled).</td>
</tr>@endforelse</tbody>
</x-ui.table>
</x-ui.panel>
