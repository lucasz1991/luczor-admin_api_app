@php
    $networkMemories = collect($memoryGraph['memories'] ?? []);
    $networkScopes = $networkMemories->pluck('scope')->filter()->unique()->sort()->values();
@endphp

<section class="memory-network" data-memory-network-3d aria-labelledby="memory-network-title">
    <script type="application/json" data-memory-network-payload>@json($memoryGraph['memories'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>

    <div class="memory-network__heading">
        <div>
            <div class="memory-eyebrow"><span aria-hidden="true"></span> Kanonisches Memory-Netzwerk</div>
            <h2 id="memory-network-title">3D-Beziehungsgraph</h2>
            <p>Metadaten-Modell aus Projekten, Typen und Scopes – ergänzt um reale Memory-Versionen.</p>
        </div>
        @if($networkMemories->isNotEmpty())
            <div class="memory-network__visible" role="status" aria-live="polite">
                <span aria-hidden="true"></span>
                <strong data-memory-network-visible>{{ $networkMemories->count() }}</strong>
                von {{ $memoryGraph['total'] ?? $networkMemories->count() }} sichtbar
            </div>
        @endif
    </div>

    @if($networkMemories->isEmpty())
        <div class="memory-network-empty">
            <div class="memory-network-empty__orb" aria-hidden="true"><span></span><i></i></div>
            <div>
                <h3>Noch keine Erinnerungen</h3>
                <p>Das 3D-Netzwerk erscheint, sobald kanonische <code>memory_links</code> vorhanden sind.</p>
            </div>
        </div>
    @else
        <div class="memory-network__toolbar" aria-label="Netzwerk filtern und steuern">
            <label class="memory-network-search">
                <span class="sr-only">Memories durchsuchen</span>
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m21 21-4.35-4.35m2.35-5.4a7.75 7.75 0 1 1-15.5 0 7.75 7.75 0 0 1 15.5 0Z"/></svg>
                <input type="search" data-memory-network-search placeholder="Memory, Projekt oder Feature suchen …" autocomplete="off">
                <kbd>/</kbd>
            </label>

            <div class="memory-network-filters" aria-label="Nach Scope filtern">
                <button type="button" data-memory-network-scope="all" aria-pressed="true">Alle</button>
                @foreach($networkScopes as $scope)
                    <button type="button" data-memory-network-scope="{{ $scope }}" aria-pressed="false">
                        <span style="--scope-color: var(--memory-scope-{{ $scope }}, var(--memory-scope-default))" aria-hidden="true"></span>
                        {{ $scope }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="memory-network__workspace">
            <div class="memory-network-stage">
                <canvas data-memory-network-canvas aria-hidden="true"></canvas>
                <div class="memory-network-stage__chrome" aria-hidden="true">
                    <span>Spatial view</span><span>SQL metadata</span>
                </div>
                <div class="memory-network-stage__hint">
                    <span class="memory-network-stage__mouse" aria-hidden="true"></span>
                    Ziehen zum Drehen · Mausrad zum Zoomen · Knoten wählen für Details
                </div>
                <div class="memory-network-controls" aria-label="3D-Ansicht steuern">
                    <button type="button" data-memory-network-action="rotate-left" aria-label="Ansicht nach links drehen" title="Nach links drehen">←</button>
                    <button type="button" data-memory-network-action="rotate-right" aria-label="Ansicht nach rechts drehen" title="Nach rechts drehen">→</button>
                    <button type="button" data-memory-network-action="zoom-out" aria-label="Ansicht verkleinern" title="Verkleinern">−</button>
                    <button type="button" data-memory-network-action="zoom-in" aria-label="Ansicht vergrößern" title="Vergrößern">+</button>
                    <button type="button" class="memory-network-controls__reset" data-memory-network-action="reset">Ansicht zurücksetzen</button>
                </div>
            </div>

            <aside class="memory-network-inspector" data-memory-network-inspector aria-labelledby="memory-inspector-title">
                <p class="sr-only" data-memory-network-announcer role="status" aria-live="polite" aria-atomic="true"></p>
                <div class="memory-network-inspector__empty" data-memory-network-inspector-empty>
                    <div class="memory-network-inspector__glyph" aria-hidden="true"><span></span><i></i><b></b></div>
                    <div>
                        <div class="memory-eyebrow"><span aria-hidden="true"></span> Inspector</div>
                        <h3 id="memory-inspector-title">Verbindung auswählen</h3>
                        <p>Wählen Sie einen Memory-Knoten im Modell oder einen Eintrag in der Liste. Direkte Kanten werden hervorgehoben.</p>
                    </div>
                </div>

                <div id="memory-network-inspector-detail" class="memory-network-inspector__detail" data-memory-network-inspector-detail hidden>
                    <div class="memory-network-inspector__topline">
                        <span data-memory-detail-scope></span>
                        <span data-memory-detail-status></span>
                    </div>
                    <h3 data-memory-detail-title></h3>
                    <p data-memory-detail-summary></p>
                    <dl>
                        <div><dt>Projekt</dt><dd data-memory-detail-project></dd></div>
                        <div><dt>Typ</dt><dd data-memory-detail-type></dd></div>
                        <div><dt>Feature</dt><dd data-memory-detail-feature></dd></div>
                        <div><dt>Wichtigkeit</dt><dd data-memory-detail-importance></dd></div>
                        <div><dt>Confidence</dt><dd data-memory-detail-confidence></dd></div>
                        <div><dt>Aktualität</dt><dd data-memory-detail-staleness></dd></div>
                        <div><dt>Cognee-Projektion</dt><dd data-memory-detail-projection></dd></div>
                        <div><dt>Quelle</dt><dd data-memory-detail-source></dd></div>
                        <div><dt>Aktualisiert</dt><dd data-memory-detail-updated></dd></div>
                        <div class="memory-network-inspector__wide"><dt>Dataset</dt><dd data-memory-detail-dataset></dd></div>
                    </dl>
                    <div class="memory-network-inspector__version" data-memory-detail-version hidden></div>
                </div>
            </aside>
        </div>

        <div class="memory-network-legend" aria-label="Legende">
            <span><i data-kind="memory"></i> Memory · Farbe = Scope</span>
            <span><i data-kind="project"></i> Projekt-Hub</span>
            <span><i data-kind="type"></i> Typ-Hub</span>
            <span><i data-kind="scope"></i> Scope-Hub</span>
            <span><i data-kind="version"></i> echte Versionskante</span>
        </div>

        <details class="memory-network-list" open>
            <summary>
                <span>Durchsuchbare Memory-Liste</span>
                <small>Barrierefreie Alternative zur 3D-Ansicht</small>
            </summary>
            <div class="memory-network-list__items" data-memory-network-list>
                @foreach($networkMemories as $memory)
                    <button
                        type="button"
                        data-memory-network-list-item="{{ $memory['id'] }}"
                        data-memory-network-item-scope="{{ $memory['scope'] }}"
                        data-memory-network-item-search="{{ Illuminate\Support\Str::lower(implode(' ', array_filter([$memory['summary'], $memory['project'], $memory['type'], $memory['feature_key'] ?? null]))) }}"
                        aria-controls="memory-network-inspector-detail"
                    >
                        <span class="memory-network-list__scope" style="--scope-color: var(--memory-scope-{{ $memory['scope'] }}, var(--memory-scope-default))" aria-hidden="true"></span>
                        <span class="memory-network-list__copy">
                            <strong>{{ $memory['summary'] ?: 'Memory #'.$memory['id'] }}</strong>
                            <small>{{ $memory['scope'] }} · {{ $memory['project'] }} · {{ $memory['type'] }}@if($memory['feature_key']) · {{ $memory['feature_key'] }}@endif</small>
                        </span>
                        <span class="memory-network-list__meta">
                            <small>{{ $memory['status'] }}</small>
                            <b>{{ number_format($memory['importance'], 2, ',', '.') }}</b>
                        </span>
                    </button>
                @endforeach
                <p class="memory-network-list__none" data-memory-network-no-results hidden>Keine Memories entsprechen diesem Filter.</p>
            </div>
        </details>
    @endif
</section>
