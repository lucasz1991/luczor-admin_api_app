# Lokale Steuerung und externe Agentenspezialisten

Der Desktop behält Orchestrierung, native Tools und die Ausführungsfreigaben. Externe Agenten erhalten reine Textaufträge über den vorhandenen Server-Proxy. Der Server wählt Modelle anhand eigener Rollenketten, Preise und Netzwerkrichtlinien; der Client darf keine Modell-ID vorgeben.

## Admin-Einrichtung

Unter **Agenten & Ereignisse** gibt es die Modellrecherche und Team-Einstellungen:

1. **OpenRouter-Katalog neu prüfen** aktualisiert ausschließlich öffentliche Metadaten der recherchierten Kandidaten. Es werden keine Provider-Keys übertragen und keine Modellanfragen gestellt.
2. **Teams ergänzen** verknüpft fehlende Modelle mit einem bereits vorhandenen aktiven OpenRouter-Zugang. Bestehende Modelle, deaktivierte Profile, bearbeitete Rollenketten und Netzwerkregeln werden erhalten. Das Verfahren erzeugt keine Credentials und benötigt keine neue Datenbankmigration.
3. **Team-Einstellungen speichern** wählt das Standardteam und begrenzt parallele externe Aufgaben auf 1–3. Die Desktop-Freigaben und die signierte lokale Modellrichtlinie gelten zusätzlich.

Der **Einrichtungsstatus je Rolle** zeigt fehlende Rollenketten, deaktivierte Teams/Rollen, inkompatible Zugänge, abgelaufene Preise und ungültige Netzwerk- oder Budgetregeln einzeln. Die Prüfung führt keine Provideranfrage aus. „Konfiguriert“ beschreibt die serverseitige Routingfähigkeit für eine kleine Textanfrage; tatsächliche Verfügbarkeit und auftragsabhängige Limits werden beim Auftrag geprüft. Das Free-Team hängt nicht von der optionalen externen Planungsrolle ab.

Ein unvollständiger öffentlicher Katalog erzeugt nur Rollen mit verfügbaren Kandidaten. Ein späteres „Teams ergänzen“ legt dann die zuvor fehlenden Rollen an. Für ältere bereits leere aktive Rollenketten gibt es die explizite Option **Leere aktive Rollenketten erneut befüllen**. Sie erhält deaktivierte Rollen, vorhandene Einträge und sonstige Einstellungen; ohne diese Option bleiben auch absichtlich leere Ketten erhalten.

Neue Installationen erhalten `free`: lokale Planung, getrennte kostenlose Modelle für Recherche, Codeentwurf und Prüfung. `budget` ergänzt optional externe Planung. Das lokale Kontrollmodell wird durch die bestehende signierte Desktop-Richtlinie bestimmt.

| Rolle | Startmodell | Fallback | Standardbudget |
| --- | --- | --- | --- |
| `agent.coding` | `cohere/north-mini-code:free` | `poolside/laguna-xs-2.1:free` | 0 USD, maximal 2 Versuche |
| `agent.research` | `nvidia/nemotron-3.5-lightning:free` | `nvidia/nemotron-3-super-120b-a12b:free` | 0 USD, maximal 2 Versuche |
| `agent.review` | `nvidia/nemotron-3-super-120b-a12b:free` | `nvidia/nemotron-3.5-lightning:free` | 0 USD, maximal 2 Versuche |
| `agent.planning` | `deepseek/deepseek-v4-flash-0731` | keiner | 0,05 USD, maximal 1 Versuch |

Alle Rollen starten mit 4.096 maximalen Ausgabetokens und 32.000 maximalen Eingabetokens nach der konservativen serverseitigen Schätzung. Die Kostenreserve berücksichtigt alle möglichen Versuche. OpenRouter erhält zusätzlich `provider.max_price` mit den freigegebenen Preisen je Million Eingabe-/Ausgabetokens und `request: 0`; dadurch dürfen kostenlose Rollen keine kostenpflichtigen Endpoints wählen. Diese Filter ersetzen das Gesamtbudget nicht.

Der Recherchestand vom 07.09.2026 ist ein begründeter Startpunkt, kein Luczor-Benchmark. DeepSeek wurde mit 0,14 / 0,28 USD je Million Eingabe-/Ausgabetokens geprüft; kurzfristige Rabattmodelle sind keine Standardplaner. NVIDIA- und Poolside-Free-Angebote können Ein- und Ausgaben speichern und für Training verwenden. Die App zeigt diese Hinweise vor einer Datenfreigabe. Die initialen Preis-Snapshots laufen nach 14 Tagen ab; dann muss der Katalog erneut geprüft und ergänzt werden. Manuell gepflegte Admin-Preise bleiben unverändert.

Quellen: [öffentlicher Modellkatalog](https://openrouter.ai/api/v1/models), [North Mini Code](https://cohere.com/blog/north-mini-code), [DeepSeek V4 Flash 0731](https://huggingface.co/deepseek-ai/DeepSeek-V4-Flash-0731), [OpenRouter Preisfilter](https://openrouter.ai/docs/guides/routing/provider-selection#max-price). Der ausführliche lokale Forschungsbericht steht in der Workspace-Datei `.lmzdev/artifacts/reports/2026-09-07-agent-model-research.md`.

## API und unveränderte Freigabe

`GET /api/v1/agent-team-policy` benötigt `settings.read` und liefert unmittelbar `{version, revision, enabled, default_preset, presets, models_by_role, evaluation, researched_at}`. `revision` ist ein SHA-256-Hash der relevanten öffentlichen und serverinternen Routingkonfiguration, ohne Credentials auszugeben. Je Rolle werden Kandidaten, Datenhinweise, Preise, Bereitschaft, Kostenlimit, Ausgabelimit und maximale Versuche geliefert.

Zusätzlich liefern Rollen `reason_code` und `reason` (jeweils null bei erfolgreicher Konfigurationsprüfung). Presets melden `ready` und `unavailable_roles`, wobei nur ihre externen Rollen berücksichtigt werden. Auch diese Werte sind Bestandteil der Revision. Discovery bleibt lesend; fehlende Konfiguration wird nicht durch einen App-Abruf aktiviert.

Für `POST /api/v1/proxy/chat` mit `task_type=agent.planning|agent.research|agent.coding|agent.review` ist `agent_team_policy_revision` erforderlich. Die App bindet diese Revision an den freigegebenen Auftrag. Der Server prüft sie vor der Verarbeitung und erneut vor jedem Provider-Versuch. Eine zwischenzeitlich geänderte Modell-, Preis-, Provider- oder Budgetkonfiguration liefert HTTP 409 mit `code=agent_team_policy_changed`; dafür ist eine neue Freigabe nötig. Eine fehlende Revision oder ein ausführbarer Tool-Vertrag wird mit HTTP 422 abgewiesen. Unbekannte `agent.*`-Rollen fallen niemals auf die allgemeine Chat-Route zurück. Deaktivierte Agententeams werden serverseitig gesperrt.

Die Spezialisten akzeptieren keine Tools und keine Tool-Historie. Auch leere kompatible Felder `tools: []` und `tool_choice: none` werden vor der Provideranfrage entfernt, weil manche Free-Endpoints sie nicht unterstützen.

## Ergebnisbewertung

Der Proxy protokolliert tatsächliche Modellversuche, Fehler, Laufzeiten, Tokens und Kosten unter der jeweiligen `agent.*`-Rolle. Nutzerrückmeldungen verwenden den bestehenden, besitzgeprüften Endpunkt `POST /api/v1/llm/runs/request/{requestId}/evaluate` mit `brain.write`. Ohne explizite Qualitätseinschätzung oder tatsächliches Testergebnis wird eine Agentenbewertung zurückgewiesen.

Die adaptive Auswahl benötigt mindestens fünf **unterschiedliche bewertete Aufgaben** je Modell und Rolle. Mehrere Bewertungen derselben Aufgabe erhöhen diese Zahl nicht. Reine HTTP-Erfolge reichen nicht aus. Bis ausreichend Evidenz vorliegt, gilt die Admin-Reihenfolge. Modellbewertungen und Nutzerurteile sind Schätzungen; `test_passed` bleibt unbekannt, solange kein tatsächlicher Test vorliegt. Ein explizit fehlgeschlagener Test wird nicht als hohe Qualität aus dem HTTP-Erfolg abgeleitet.

## Liefergrenze

Quellcode, Tests und lokale Adminvorschau belegen keine Live-Freischaltung und keinen echten Providerlauf. Eine Produktionsauslieferung benötigt den freigegebenen Deployment-Schritt, den Vite-Build und die anschließende Einrichtung im Admin. Weder `DatabaseSeeder` noch allgemeine Datenmigrationen sind zur Ergänzung der Teams erforderlich.
