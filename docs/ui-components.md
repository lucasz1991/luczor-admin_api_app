# Gemeinsame Luczor-Weboberfläche

Die Laravel-Webseiten verwenden Blade-Komponenten unter `resources/views/components/ui`. Farben, Abstände, Oberflächen, Formulare und Zustände werden zentral in `resources/css/ui-system.css` gepflegt. Alpine-Verhalten liegt in `resources/js/ui-components.js` und wird vor Livewire registriert.

## Herkunft und Gestaltung

Die Seitenrahmen, Oberflächen, Kennzahlen und Tab-Struktur wurden aus den tatsächlich gelesenen RailTime-Komponenten adaptiert: `C:/xampp/htdocs/RailTime/App/resources/views/components/ui/page.blade.php`, `ui/surface/card.blade.php`, `ui/dashboard/stat-card.blade.php` und `ui/accordion/{tabs,tab-panel}.blade.php`. Benutzerliste und Profile übernehmen die zuvor integrierten RailTime-Livewire-Muster. RailTime-spezifische Dienste, Nutzdaten und Abhängigkeiten werden nicht mitkopiert.

Luczor nutzt dunkle Flächen, helle Cyan-Akzente, einheitliche Rundungen und lokale Systemschriften. Kleine Bewegungseffekte respektieren die Einstellung für reduzierte Bewegung. Die Shell besteht aus getrennten Komponenten für Kopfzeile und Navigation. Bestehende spezielle Workflow-/Graph-Oberflächen erhalten dieselben Gestaltungswerte über die Kompatibilitätsklassen `luczor-card`, `luczor-input` und `luczor-btn`.

## Bausteine

| Komponente | Aufgabe |
| --- | --- |
| `x-ui.page` | Seitentitel, Beschreibung, optionaler Eyebrow und Aktionsslot |
| `x-ui.panel` | Inhalt mit optionalem Titel, Beschreibung und Aktionsslot |
| `x-ui.tabs` / `x-ui.tab-panel` | Thematische Gliederung, Tastatursteuerung und sichtbare Validierungsfehler |
| `x-ui.button` | Primär-, Sekundär-, Gefahren- und dezente Aktionen; optional als Link |
| `x-ui.input`, `x-ui.select`, `x-ui.textarea` | Einheitliche native Felder mit weitergereichten Formular-/Livewire-Attributen |
| `x-ui.stat`, `x-ui.badge` | Kennzahlen und verständliche Zustände |
| `x-ui.table` | Beschrifteter, horizontal scrollbarerer Tabellenbereich |
| `x-ui.empty`, `x-ui.alert` | Leerzustände und Rückmeldungen |
| `x-user-ui.*` | Benutzeridentität und auswählbare Geräte-/Chat-Einträge |

```blade
<x-ui.page title="Geräte" description="Verwalte deine verbundenen Geräte.">
    <x-ui.tabs id="my-devices" :tabs="['list' => 'Übersicht', 'connect' => 'Verbinden']" active="list">
        <x-ui.tab-panel name="list">
            <x-ui.panel title="Meine Geräte">
                {{-- Fachliche Inhalte und vorhandene Berechtigungsprüfungen bleiben hier. --}}
            </x-ui.panel>
        </x-ui.tab-panel>
        <x-ui.tab-panel name="connect">
            <x-ui.empty title="Gerät verbinden">Öffne die Anmeldung in der Desktop-App.</x-ui.empty>
        </x-ui.tab-panel>
    </x-ui.tabs>
</x-ui.page>
```

## Verhaltensregeln

- Tab-IDs sind innerhalb einer Seite eindeutig und stabil. Die Panels verwenden dieselben Schlüssel wie `tabs`. Verschachtelte Tabs erhalten eigene IDs.
- Pfeiltasten, Pos1 und Ende wechseln den Tab und den Fokus. `aria-selected`, `aria-controls` und `aria-labelledby` bilden den Zusammenhang ab; ausgeblendete Panels sind `inert`. Grundlage: [WAI-ARIA Tab-Muster](https://www.w3.org/WAI/ARIA/apg/patterns/tabs/).
- Die Auswahl bleibt optional in `sessionStorage` pro Pfad und Tab-Gruppe erhalten. Gültige Hash-Ziele können Panels öffnen. Mit `force-active` erhalten serverseitige Validierungsfehler Vorrang vor gespeicherter Auswahl und Start-Hash.
- Ein natives ungültiges Pflichtfeld öffnet sein Panel synchron. Das erste ungültige Feld einer Browser-Validierungsrunde bestimmt die Auswahl; ein Timer hält diese Auswahl auch zwischen Microtask-Checkpoints stabil.
- Livewire-Aktionen außerhalb des Gesprächs können mit `ui-tab-select` und `{ id: 'workspace-sections', name: 'conversation' }` das Gespräch öffnen. Servergesteuerte administrative Profiltabs behalten ihren vorhandenen `selectTab`-Vertrag.
- `x-ui.button` hat absichtlich `type="button"` als Standard. Speicheraktionen benötigen explizit `type="submit"`. Formularrouten, CSRF, Methoden, versteckte IDs und `wire:*`-Attribute bleiben fachlich erhalten.
- Ein Katalogformular mit fünf Modellstufen bleibt ein gemeinsames Formular. Die Tab-Darstellung darf keine Teilkonfiguration veröffentlichen.
- Kopfaktionen dürfen umbrechen; Tabellen scrollen innerhalb ihres Bereichs. Keine feste Mindestbreite für mobile Seiten einführen.

## Prüfungen

`php artisan test --compact` prüft die Laravel-Funktionen. `tests/Feature/UiCompositionTest.php` prüft Seitenabdeckung sowie IDs und Attributweitergabe. Die native Tab-Logik wird mit `node --test tests/Frontend/ui-components.test.mjs` geprüft. Für Node und den Vite-Build den Projektwrapper `../app/scripts/with-pinned-node.ps1` verwenden. Browserprüfungen ergänzen diese Tests für mobile Umbrüche, Fokus und echte native Formularvalidierung.
