<x-ui.panel title="Angelegte Experimente" description="Der Status zeigt, wie weit die bestehenden Modell-Experimente fortgeschritten sind.">
    <x-ui.table>
        <thead>
<tr>
<th>Experiment</th>
<th>Status</th>
</tr>
</thead>
        <tbody>
            @forelse($llmExperiments as $experiment)
                <tr>
<td class="font-semibold">{{ $experiment->name }}</td>
<td>
<x-ui.badge>{{ $experiment->status }}</x-ui.badge>
</td>
</tr>
            @empty
                <tr>
<td colspan="2">
<x-ui.empty title="Noch keine Modell-Experimente">Angelegte Experimente erscheinen hier mit ihrem aktuellen Status.</x-ui.empty>
</td>
</tr>
            @endforelse
        </tbody>
    </x-ui.table>
</x-ui.panel>
