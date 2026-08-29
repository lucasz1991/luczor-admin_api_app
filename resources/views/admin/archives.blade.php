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

<div class="memory-archive-page">
    <section class="memory-archive-hero" aria-labelledby="memory-archive-title">
        <div class="memory-archive-hero__copy">
            <div class="memory-eyebrow"><span aria-hidden="true"></span> Luczor Memory Atlas</div>
            <h2 id="memory-archive-title">Das operative Gedächtnis als räumliches Netzwerk.</h2>
            <p>
                Erkunden Sie die wichtigsten kanonischen Memories in einer interaktiven 3D-Perspektive.
                Projekt-, Typ- und Scope-Verbindungen sind Metadaten-Kanten; durchgezogene Versionskanten
                stammen direkt aus <code>supersedes_id</code>.
            </p>
        </div>
        <dl class="memory-archive-hero__metrics" aria-label="Memory-Netzwerk Kennzahlen">
            <div><dt>Kanonische Memories</dt><dd>{{ number_format($memoryGraph['total'] ?? 0, 0, ',', '.') }}</dd></div>
            <div><dt>Im Modell</dt><dd>{{ number_format($memoryGraph['visible'] ?? 0, 0, ',', '.') }}</dd></div>
            <div><dt>Projekt-Hubs</dt><dd>{{ number_format($memoryGraph['projects'] ?? 0, 0, ',', '.') }}</dd></div>
            <div><dt>Echte Versionskanten</dt><dd>{{ number_format($memoryGraph['version_edges'] ?? 0, 0, ',', '.') }}</dd></div>
        </dl>
    </section>

    @include('admin.memory-graph')

    <section class="memory-archive-section" aria-labelledby="memory-distribution-title">
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
    </section>

    <section class="memory-archive-section memory-sync-archive" aria-labelledby="memory-sync-title">
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
    </section>
</div>
