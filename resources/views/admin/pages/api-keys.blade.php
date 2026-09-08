<x-ui.panel title="Geräteschlüssel" description="Übersicht der eingerichteten Schlüssel. Geheime Schlüsselwerte werden hier nicht angezeigt.">
    <x-ui.table>
        <thead>
<tr>
<th>Bezeichnung</th>
<th>Geräte-ID</th>
</tr>
</thead>
        <tbody>
            @forelse($apiKeys as $key)
                <tr>
<td><span class="font-semibold">{{ $key->name }}</span></td>
<td class="break-all font-mono text-xs">{{ $key->device_id ?: '—' }}</td>
</tr>
            @empty
                <tr>
<td colspan="2">
<x-ui.empty title="Noch keine Geräteschlüssel">Neue Verbindungen werden über die Geräteeinrichtung registriert.</x-ui.empty>
</td>
</tr>
            @endforelse
        </tbody>
    </x-ui.table>
</x-ui.panel>
