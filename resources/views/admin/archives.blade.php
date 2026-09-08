@php
    $archiveLabels = [
        'projects' => 'Projekte',
        'messages' => 'Nachrichten',
        'memories' => 'Memory-Snapshots',
        'summaries' => 'Zusammenfassungen',
        'agent_events' => 'Agent-Ereignisse',
    ];
    $syncArchiveTotal = array_sum($archiveCounts ?? []);
@endphp

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-ui.stat label="Kanonische Memories" :value="number_format($memoryGraph['total'] ?? 0, 0, ',', '.')" />
    <x-ui.stat label="Im Modell" :value="number_format($memoryGraph['visible'] ?? 0, 0, ',', '.')" />
    <x-ui.stat label="Projekt-Hubs" :value="number_format($memoryGraph['projects'] ?? 0, 0, ',', '.')" />
    <x-ui.stat label="Echte Versionskanten" :value="number_format($memoryGraph['version_edges'] ?? 0, 0, ',', '.')" />
</div>
<x-ui.tabs id="admin-memory" :tabs="['network' => 'Memory-Netzwerk', 'distribution' => 'Verteilung', 'archive' => 'Sync-Archiv']" active="network">
    <x-ui.tab-panel name="network">@include('admin.memory-graph')</x-ui.tab-panel>
    <x-ui.tab-panel name="distribution">    <x-ui.panel class="memory-archive-section" aria-labelledby="memory-distribution-title">
        <div class="memory-section-heading">
            <div>
                <div class="memory-eyebrow"><span aria-hidden="true"></span> Verteilung</div>
                <h2 id="memory-distribution-title">Kanonisches Memory im Überblick</h2>
            </div>
            <p>Die Balken zeigen den Anteil an allen {{ number_format($memoryOverview['total'] ?? 0, 0, ',', '.') }} Einträgen.</p>
        </div>

        <div class="memory-distribution-grid">
            @foreach([
                ['title' => 'Scopes', 'rows' => $memoryOverview['by_scope'] ?? [], 'tone' => 'cyan'],
                ['title' => 'Typen', 'rows' => $memoryOverview['by_type'] ?? [], 'tone' => 'green'],
                ['title' => 'Projekte', 'rows' => $memoryOverview['by_project'] ?? [], 'tone' => 'amber'],
            ] as $distribution)
                <article class="memory-distribution-card" data-tone="{{ $distribution['tone'] }}">
                    <h3>{{ $distribution['title'] }}</h3>
                    <div class="memory-distribution-card__rows">
                        @forelse($distribution['rows'] as $row)
                            <div class="memory-distribution-row">
                                <div><span>{{ $row['label'] ?: 'Unbekannt' }}</span><strong>{{ $row['value'] }}</strong></div>
                                <div class="memory-distribution-track" aria-label="{{ $row['pct'] }} Prozent">
                                    <span style="width: {{ $row['pct'] }}%"></span>
                                </div>
                            </div>
                        @empty
                            <p class="memory-empty-copy">Noch keine Daten vorhanden.</p>
                        @endforelse
                    </div>
                </article>
            @endforeach
        </div>
    </x-ui.panel>

</x-ui.tab-panel>
    <x-ui.tab-panel name="archive">    <x-ui.panel class="memory-archive-section memory-sync-archive" aria-labelledby="memory-sync-title">
        <div class="memory-section-heading">
            <div>
                <div class="memory-eyebrow"><span aria-hidden="true"></span> Separater Datenbestand</div>
                <h2 id="memory-sync-title">Sync-Archiv &amp; Audit</h2>
            </div>
            <p>
                {{ number_format($syncArchiveTotal, 0, ',', '.') }} archivierte Sync-Datensätze. Diese Zähler stammen aus
                <code>luczor_*_archives</code> und sind nicht mit dem kanonischen Netzwerk gleichzusetzen.
            </p>
        </div>

        <dl class="memory-sync-grid">
            @foreach($archiveCounts as $key => $count)
                <div>
                    <dt>{{ $archiveLabels[$key] ?? str_replace('_', ' ', $key) }}</dt>
                    <dd>{{ number_format($count, 0, ',', '.') }}</dd>
                </div>
            @endforeach
        </dl>
    </x-ui.panel>
</x-ui.tab-panel>
</x-ui.tabs>
