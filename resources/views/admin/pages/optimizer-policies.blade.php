<x-ui.panel title="Netzwerk-Policies">
    <div class="mt-3 grid gap-3 md:grid-cols-3">@foreach($networkPolicies as $policy)<div class="ui-record"><b>{{ $policy->name }}</b>
<div class="text-xs text-slate-500">{{ $policy->key }}</div></div>@endforeach</div>
</x-ui.panel>
