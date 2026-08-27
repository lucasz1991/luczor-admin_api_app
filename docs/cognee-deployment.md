# Cognee: isolierter und authentisierter Betrieb

Cognee ist standardmäßig nur über die internen Docker-Netze erreichbar und verwendet eine eigene
PostgreSQL-Datenbank, eine eingeschränkte Datenbankrolle sowie eigene LLM- und
Embedding-Zugangsdaten. Laravel kennt weder das Cognee-Datenbankpasswort noch die
Provider-Schlüssel. Cognee kennt umgekehrt weder das Laravel-Datenbankpasswort noch den
Laravel-/OpenRouter-Schlüssel.

Wenn Laravel wie bei Plesk direkt auf dem Host statt im Compose-Netz läuft, veröffentlicht
`docker-compose.plesk-cognee.yml` ausschließlich den Cognee-Port auf `127.0.0.1`. Eine
Bindung an `0.0.0.0`, eine öffentliche Subdomain oder die Laravel-URL selbst sind nicht
zulässig.

Die HTTP-API verlangt zusätzlich Authentisierung. Laravel verwendet dafür einen eigenen,
gehashten Cognee-Service-Key. Lokale Pfade, ausgehende URL-Abrufe und direkte
Cypher-Abfragen bleiben deaktiviert.

## Konfigurationsgrenzen

Nicht geheime Werte stehen in `.env.docker`:

- `COGNEE_POSTGRES_DB` und `COGNEE_POSTGRES_USER` müssen von `POSTGRES_DB` und
  `POSTGRES_USER` verschieden sein.
- `COGNEE_LLM_PROVIDER`, `COGNEE_LLM_MODEL` und optional `COGNEE_LLM_ENDPOINT`
  konfigurieren die Extraktion und Graphbildung.
- `COGNEE_EMBEDDING_PROVIDER`, `COGNEE_EMBEDDING_MODEL`,
  `COGNEE_EMBEDDING_DIMENSIONS` und optional `COGNEE_EMBEDDING_ENDPOINT` konfigurieren
  die Vektorisierung. Die Dimension muss exakt zum Modell passen.
- `COGNEE_IMPROVE_ENABLED=false` bleibt beim gepinnten Cognee 1.4 der sichere Standard.
  `COGNEE_IMPROVE_MIN_INTERVAL_SECONDS` ist zusätzlich mindestens 300 Sekunden und
  standardmäßig eine Stunde.
- `COGNEE_SEMANTIC_QUERY_TIMEOUT=3` begrenzt die gesamte optionale Batch-Suche über alle
  belegten Namespace-Aliase. Der Wert erbt bewusst nicht das längere Add-/Worker-Budget.
- `LUCZOR_MEMORY_NAMESPACE_KEY` ist ein eigener, stabiler Secret-Wert mit mindestens
  32 Byte. Er darf weder aus `APP_KEY` abgeleitet noch zusammen mit diesem rotiert werden.
  Die Datasetnamen enthalten dadurch keine lesbaren Benutzer-, Tenant- oder Projekt-IDs.
  Bei einer bewussten Rotation wird der bisherige Wert zuerst in
  `LUCZOR_MEMORY_PREVIOUS_NAMESPACE_KEYS` übernommen; erst dann wird ein neuer Primärwert
  aktiviert. Recall, Update, CAS, Promotion und Forget serialisieren und durchsuchen die
  vollständige Aliasfamilie. Alte Schlüssel bleiben bis zur kontrollierten Ablösung der
  zugehörigen SQL-/Cognee-Projektionen im Keyring.
- `LUCZOR_MEMORY_LEDGER_KEY` ist ein davon unabhängiger, nicht rotierender Secret-Wert
  mit mindestens 32 Byte. Er schützt dauerhafte Write-ID- und Request-Fingerprint-
  Tombstones per domain-separiertem HMAC vor Offline-Enumeration. Da diese Tombstones
  absichtlich länger als ein Benutzerkonto leben können, muss der Key für ihre gesamte
  Lebensdauer im Secret-Backup erhalten bleiben; er darf weder `APP_KEY` noch dem
  Dataset-Namespace-Key entsprechen.

Die HTTP-Healthcheck-Route prüft keine LLM- oder Embedding-Antwort. Deshalb bleibt ein
echter `add`-/Hintergrund-`cognify`-Smoke-Test nach jeder Erstbereitstellung oder Provideränderung
verpflichtend.

Cognee-Provider-, Datenbank- und HTTP-Geheimnisse liegen ausschließlich als Dateien unter
`admin_api_app/docker/secrets/`. Der ausschließlich von Laravel verwendete
Memory-Namespace-Key liegt dagegen in dessen geschützter Deployment-Umgebung:

| Secret-Datei | Besitzer/Zweck |
|---|---|
| `cognee_postgres_password` | Nur Cognee und der idempotente DB-Initialisierer |
| `cognee_llm_api_key` | Nur Cognee; eigener Schlüssel für den LLM-Provider |
| `cognee_embedding_api_key` | Nur Cognee; eigener Schlüssel für den Embedding-Provider |
| `cognee_api_key` | Laravel API/Horizon für authentisierte Cognee-Aufrufe |
| `cognee_jwt_secret`, `cognee_default_password`, `cognee_verification_secret`, `cognee_reset_secret` | Cognee-Authentisierung |

LLM- und Embedding-Schlüssel werden absichtlich nicht aus `openrouter_key` übernommen. So
lassen sich Anbieterrechte, Kostenlimits und Rotation für die Memory-Pipeline getrennt
steuern. Auch wenn derselbe Anbieter verwendet wird, sollten getrennte, minimal berechtigte
Provider-Schlüssel eingesetzt werden.

## Plesk: Laravel auf dem Host, Cognee in Docker

Diese Variante startet nur PostgreSQL, den Cognee-DB-Initialisierer und Cognee. Laravel,
Redis, Horizon, Scheduler und Reverb bleiben in der vorhandenen Plesk-Installation.

1. Vom Workspace-Root die Loopback-Konfiguration prüfen und die drei Dienste starten:

   ```bash
   docker compose --env-file .env.docker \
     -f docker-compose.yml -f docker-compose.plesk-cognee.yml config --quiet
   docker compose --env-file .env.docker \
     -f docker-compose.yml -f docker-compose.plesk-cognee.yml \
     up -d postgres cognee-db-init cognee
   curl --fail --silent --show-error http://127.0.0.1:8010/openapi.json >/dev/null
   ```

   `COGNEE_HOST_PORT` darf den Loopback-Port ändern. Die Compose-Datei bindet unabhängig
   davon immer nur an `127.0.0.1`.

2. Nach dem Healthcheck den Service-Key mit dem vorhandenen Provisioning-Skript erzeugen.
   Das Skript gibt den Key nicht aus und schreibt ihn nur in die lokale Secret-Datei:

   ```bash
   sh ./admin_api_app/docker/provision-cognee.sh
   ```

3. Den Key ohne Ausgabe in Laravels geschützten Storage kopieren. Besitzer und Gruppe
   werden vom bestehenden Storage-Verzeichnis übernommen:

   ```bash
   app_root=/var/www/vhosts/follow-flow.de/luczor.follow-flow.de
   key_owner="$(stat -c '%U' "$app_root/storage/app/keys")"
   key_group="$(stat -c '%G' "$app_root/storage/app/keys")"
   install -m 600 -o "$key_owner" -g "$key_group" \
     admin_api_app/docker/secrets/cognee_api_key \
     "$app_root/storage/app/keys/cognee_api_key"
   ```

4. In der produktiven Laravel-`.env` konfigurieren; der direkte Key bleibt leer:

   ```dotenv
   COGNEE_BASE_URL=http://127.0.0.1:8010
   COGNEE_API_KEY=
   COGNEE_API_KEY_FILE=/var/www/vhosts/follow-flow.de/luczor.follow-flow.de/storage/app/keys/cognee_api_key
   COGNEE_TIMEOUT=45
   COGNEE_CONTROL_TIMEOUT=8
   COGNEE_ACK_TIMEOUT=3
   COGNEE_SEMANTIC_QUERY_TIMEOUT=3
   COGNEE_IMPROVE_ENABLED=false
   ```

5. Laravel-Konfiguration und langlebige Prozesse neu laden und dann ausschließlich lesend
   den authentisierten Wrapper prüfen:

   ```bash
   php artisan optimize:clear
   php artisan config:cache
   php artisan horizon:terminate
   php artisan luczor:cognee-check
   ```

   Danach folgt weiterhin der unten beschriebene echte Produktpfad-Smoke-Test. Ein grüner
   `openapi.json`-Aufruf beweist weder Authentifizierung noch LLM-/Embedding-Funktion.

## Erstbereitstellung

Alle Befehle werden vom Workspace-Root ausgeführt.

1. Beispielkonfiguration kopieren und Provider/Modelle prüfen:

   ```powershell
   Copy-Item .env.docker.example .env.docker
   ```

   Vor `migrate`, API-Start oder einem lokalen Memory-Aufruf muss
   `LUCZOR_MEMORY_NAMESPACE_KEY` und `LUCZOR_MEMORY_LEDGER_KEY` in
   `admin_api_app/.env` beziehungsweise `.env.docker` durch zwei unabhängige, einmalig
   kryptografisch erzeugte Werte mit mindestens 32 Byte ersetzt und im
   Deployment-Secret-Backup gesichert sein. Die Readiness weist fehlende, zu kurze,
   gleiche oder unveränderte Beispielwerte ab. Eine bestehende Installation darf ohne
   den bisherigen Wert beziehungsweise den vollständig gepflegten Previous-Keyring nicht
   migriert werden, weil ältere Dataset-Aliase sonst nicht mehr adressierbar wären.

   Die Ledger-Migration ist ein technisch erzwungener Cutover: Sie markiert bestehende
   SHA-Zeilen als Version 1, blockiert anschließend weitere Version-1-Inserts auf
   PostgreSQL per `NOT VALID`-Check beziehungsweise auf MySQL/MariaDB per Trigger und
   rekeyt nur diese Zeilen. Neue API-/Horizon-Prozesse schreiben ausdrücklich Version 2.
   Alte Writer werden danach von der Datenbank abgewiesen. Deshalb zuerst den gesamten
   Web-/API-Traffic einschließlich Accountänderungen sowie Horizon und Scheduler in
   Wartung nehmen, dann alle Memory-Migrationen ausführen und erst danach ausschließlich
   das neue Image starten. Der Cutover ist ausdrücklich kein Zero-Downtime-Backfill.
   Noch bevor MySQL/MariaDB durch `000004` ein Auto-Commit-DDL ausführt, prüft die
   Migration sämtliche ownerlosen Live-Upserts. Für jeden ambigen Add muss das exakte
   Wiederherstellungspaar `(provider_memory_link_id, content_hash)` vollständig und
   widerspruchsfrei vorliegen. Bei einem Blocker das Paar ausschließlich aus einem
   vertrauenswürdigen Backup oder Audit rekonstruieren oder den nachweislich zugehörigen
   Cognee-Bestand kontrolliert bereinigen und den Eingriff dokumentieren; niemals eine ID
   oder einen Hash schätzen. Danach Backup und Preflight erneut ausführen.
   Die Produktions-Readiness bleibt rot, solange
   ein Link nicht Version 2 oder ein Write-Event nicht Version 2 beziehungsweise die
   nach Kontolöschung unlinkbare Erasure-Version 3 trägt.

2. Fehlende Secret-Dateien idempotent anlegen:

   ```powershell
   ./admin_api_app/docker/init-secrets.ps1
   ```

   Das Skript überschreibt keine vorhandene Datei. Es erzeugt das separate
   Cognee-Datenbankpasswort, lässt `cognee_llm_api_key` und
   `cognee_embedding_api_key` aber bewusst leer. Beide Provider-Schlüssel müssen über
   einen sicheren Editor oder Secret-Manager befüllt werden, bevor Cognee startet. Leere
   Schlüssel lassen den Cognee-Entrypoint fail-closed abbrechen.

3. PostgreSQL starten und die isolierte Cognee-Datenbank herstellen:

   ```powershell
   docker compose --env-file .env.docker up -d postgres
   docker compose --env-file .env.docker run --rm cognee-db-init
   ```

   `cognee-db-init` ist wiederholbar. Er erzeugt die Rolle und Datenbank bei Bedarf,
   synchronisiert bei einer Passwortrotation die Rolle und setzt die Eigentümerschaft.
   Clusterweit entzieht er `PUBLIC` die Standardrechte `CONNECT` und `TEMPORARY`, entfernt
   direkte Datenbankrechte der Cognee-Rolle von jeder vorhandenen Nicht-Zieldatenbank und
   gewährt Laravel und Cognee nur ihre jeweils benötigten Rechte zurück. Eine vor allen
   allgemeinen Regeln eingebundene `pg_hba.conf`-Guard-Datei erlaubt der Cognee-Rolle nur
   die konfigurierte Cognee-Datenbank und lehnt dieselbe Rolle für alle anderen – auch
   später neu erstellten – Datenbanken ab. Erst danach stellt der Initialisierer die
   `vector`-Extension in der separaten Cognee-Datenbank bereit.

   Der Dienst läuft auch gegen bereits vorhandene `postgres_data`-Volumes; er löscht weder
   Datenbanken noch Tabellen. Die konfigurierte `POSTGRES_USER`-Rolle muss Eigentümer der
   durch `POSTGRES_DB` bezeichneten Laravel-Datenbank sein. Eine abweichende
   Eigentümerschaft, vorhandene privilegierte Cognee-Rollen, PostgreSQL-Systemdatenbanken
   und Cognee-Datenbanken mit einem unerwarteten Besitzer werden fail-closed abgelehnt.
   Damit kann eine auf einem älteren Volume noch über `PUBLIC` geerbte Freigabe nicht
   unbemerkt bestehen bleiben.

   Der Initialisierer bricht ab, wenn die Laufzeit-Invarianten nicht erfüllt sind:

   - Cognee besitzt in keiner verbindbaren Nicht-Zieldatenbank `CONNECT` oder `TEMPORARY`;
   - Cognee besitzt in der Zieldatenbank `CONNECT` und `TEMPORARY`;
   - `has_database_privilege(POSTGRES_USER, POSTGRES_DB, 'CONNECT') = true`

4. Cognee starten und den Healthcheck abwarten:

   ```powershell
   docker compose --env-file .env.docker up -d cognee
   docker compose --env-file .env.docker ps --all postgres cognee-db-init cognee
   ```

   `up` führt den DB-Initialisierer als Abhängigkeit ebenfalls aus. Der HTTP-Healthcheck
   beweist nur, dass die interne API antwortet; LLM und Embeddings gelten erst nach dem
   vollständigen Projektions-Smoke-Test als betriebsbereit.

   Der Runtime-Guard ist für genau **eine Cognee-Replik** ausgelegt. Die Compose-Konfiguration
   startet daher einen einzelnen Cognee-Prozess. Nicht horizontal skalieren, bevor
   Prozess-Leases und die Boot-ID-Erkennung explizit auf mehrere Replikate erweitert wurden;
   sonst wäre ein fremder Prozess-Neustart kein Beweis dafür, dass eine Task beendet ist.
   Die PostgreSQL-Advisory-Lease umschließt den vollständigen FastAPI-/Cognee-Lifespan:
   Lease erwerben, erst danach den Upstream-Startup ausführen, Requests bedienen, den
   Upstream-Shutdown abschließen und erst zuletzt die Lease freigeben. Das ist zwingend,
   weil bereits Cognee-Startup eine Recovery ausführt; eine zweite Instanz darf diese nie
   vor ihrer Lease-Ablehnung erreichen.

5. Einmalig den internen Benutzer und Service-Key erzeugen:

   ```powershell
   ./admin_api_app/docker/provision-cognee.ps1
   ```

   Das Skript zeigt den Key nicht an. Es validiert ihn gegen Cognee und schreibt ihn nach
   `admin_api_app/docker/secrets/cognee_api_key`. Bei einem Fehler bleibt ein vorhandenes
   Secret unverändert.

6. Laravel API, Horizon und Scheduler starten beziehungsweise neu erzeugen und die
   Readiness prüfen:

   ```powershell
   docker compose --env-file .env.docker up -d --force-recreate api horizon scheduler
   docker compose --env-file .env.docker exec api php artisan luczor:deployment-check --production
   ```

7. Einen echten Projektions-Smoke-Test über den Produktpfad durchführen:

   - In Luczor eine eindeutig benannte, unkritische Test-Erinnerung explizit bestätigen.
   - Die Projektion anstoßen:

     ```powershell
     docker compose --env-file .env.docker exec api php artisan luczor:dispatch-memory-projections
     ```

   - Prüfen, dass der Outbox-Eintrag nacheinander die persistierten Phasen `ingested`,
     `cognify_launching` und `polling` durchläuft, anschließend `done` erreicht und die
     zugehörige Erinnerung `projection_status=ready` trägt. Die Outbox muss die vom
     Cognify-POST gelieferte `pipeline_run_id` speichern und ausschließlich deren exakte
     terminale Activity-Transition auswerten. Zusätzlich dürfen die Cognee-Logs weder Providerfehler noch
     Dimensionsfehler enthalten:

     ```powershell
     docker compose --env-file .env.docker logs --since 5m cognee horizon
     ```

   - Die Test-Erinnerung anschließend über den Memory-Controller vergessen. Ein reiner
     `openapi.json`- oder Login-Aufruf ist kein Cognify-Test.

Der Scheduler führt den Outbox-Reconciler regelmäßig aus. Horizon benötigt deshalb
denselben Cognee-Service-Key wie die API. Fehlgeschlagene Projektionen bleiben mit Backoff
in `memory_projection_outbox` erhalten. Der Produktpfad verwendet bewusst nicht den
blockierenden `/remember`-Aufruf: Nach dem begrenzten `/add` startet er `/cognify` mit
`run_in_background=true`, stabiler Idempotency-ID und persistierter Boot-ID. Der Wrapper
kodiert für jeden Add das monotone Wiederherstellungspaar
`(provider_memory_link_id, content_hash)` in den Dateinamen. Geht die Add-Antwort verloren,
liefert die exklusive Exact-Data-Abfrage sämtliche UUIDs genau dieses Paars; der Worker
übernimmt eine kanonische UUID und erzeugt für jede weitere eine dauerhafte Kompensation.
Ein wartender Exact-Lookup blockiert neue Adds bereits vor dem Warten auf laufende Adds,
damit Recovery und ein nachfolgendes Forget auch unter Dauerlast Fortschritt machen. Der Wrapper
spiegelt die Startantwort zunächst dauerhaft in seiner Cognee-Datenbank und stellt eine
authentisierte Exact-Run-Abfrage bereit, weil Cognees Activity-Feed nur die neuesten 50
Zeilen liefert. Ein Timeout setzt deshalb nie blind einen zweiten Run in Gang. Das
HTTP-Gesamtbudget muss kleiner als der Job-Timeout und dieser kleiner als Redis `retry_after`
bleiben. Erst nach dem dauerhaften Speichern von Run-ID und Polling-Phase sendet Laravel
einen authentisierten Launch-Ack. Ein nicht zugestellter Ack bleibt im
Projektions-Outbox-Payload stehen und wird vor jedem
weiteren Poll sowie vor dem endgültigen Abschluss erneut versucht. Vor einer neuen
Startgeneration nach einem Runtime-Wechsel muss der alte Ack erfolgreich zugestellt sein;
sein Schlüssel wird niemals durch den neuen Start überschrieben.
Bestätigte Registry-Zeilen werden stattdessen auf die minimale, bereits bereinigte
Startantwort reduziert und als dauerhafte Tombstones behalten. So können weder ein
verspäteter Queue-Replay noch ein wiederhergestelltes Backup denselben Lauf erneut starten.
Es gibt absichtlich keinen scheinbaren TTL- oder Cleanup-Schalter: Die Tabelle
`luczor_cognify_idempotency` wird betrieblich überwacht und gesichert. Eine spätere
Archivierung ist nur zulässig, wenn Operation, Principal, Request-Fingerprint und die
minimale Run-Antwort in einem ebenso dauerhaften Idempotenz-Ledger erhalten bleiben.

Für den längsten synchronen Worker-Pfad gilt ein hartes Gesamtbudget: begrenzter Daten-/
Startaufruf (45 Sekunden), bis zu drei Control-Aufrufe (je 8), bis zu drei Ack-Versuche
(je 3) und fünf Sekunden SQL-/Queue-Reserve, also höchstens 83 Sekunden. Das bleibt unter
dem 85-Sekunden-Job-Limit; dieses liegt wiederum unter Redis `retry_after=90`. Die Produktions-
Readiness lehnt Konfigurationen ab, die diese Reihenfolge verletzen. Zusätzlich muss der
120-Sekunden-Inhalts-Lock länger als der Job laufen. Die Werte lassen sich mit
`COGNEE_TIMEOUT`, `COGNEE_CONTROL_TIMEOUT`, `COGNEE_ACK_TIMEOUT` und
`COGNEE_CONTENT_LOCK_SECONDS` nur innerhalb dieses Gesamtbudgets anpassen.

Recall bündelt alle autorisierten und in SQL tatsächlich belegten Dataset-Aliase in genau
einer Cognee-Search-Anfrage. `COGNEE_SEMANTIC_QUERY_TIMEOUT` begrenzt diesen gesamten
optionalen Aufruf standardmäßig auf drei Sekunden. Timeout, Netzwerkfehler oder eine
fehlerhafte Providerantwort verwerfen sämtliche semantischen Ränge dieses Recalls; die
bereits autorisierte SQL-Recency-/Lexical-Kandidatenmenge wird ohne weiteren Alias-Aufruf
zurückgegeben.

Ein explizit angefordertes und freigeschaltetes `improve` läuft ebenfalls über die Outbox. Es verwendet die
Phasen `improve_launching` und `improve_polling`, eine stabile Idempotency-ID sowie die
exakte `improve_pipeline`-Run-ID. Upsert, Forget und Improve werden pro Dataset gemeinsam
serialisiert; Improve persistiert den geschützten Turn atomar vor der Runtime-Probe und
Forget prüft ihn nach dem Erwerb des Inhalts-Locks erneut. Ein belegter Inhalts-Lock wird
nicht blockierend abgewartet: Die Outbox geht ohne Fehlversuch fünf Sekunden auf `pending`
und nimmt ihre dauerhafte Phase später wieder auf. Ein alter Run wird nur nach nachgewiesenem Prozesswechsel neu gestartet;
eine reine Laufzeitüberschreitung reicht dafür nicht aus. Direkte Hintergrundaufrufe von
`/cognify` und `/improve` ohne gültigen `X-Luczor-Idempotency-Key` weist der Wrapper
fail-closed zurück. Improve setzt mindestens eine aktive, sichere `ready`-Projektion voraus,
wird je Dataset zusammengeführt und ist zusätzlich pro Actor begrenzt. Vor jedem neuen
Cognify- oder Improve-Start prüft Laravel außerdem die authentisierte Wrapper-Route
`/api/v1/luczor/runtime`; fehlende oder zwischen Probe und Start wechselnde Boot-IDs brechen
den Launch fail-closed ab. Trägt ein ambiger Improve bereits einen Account-Erasure-Marker,
wird er bei gleicher Boot-ID ausschließlich idempotent fortgesetzt; nach einer geänderten
Boot-ID gilt die alte prozesslokale Task als beendet und der Turn wird ohne privaten Relaunch
für Forget freigegeben.

Cognee 1.4 kann einen hängenden Improve-Lauf ohne wirksamen Upstream-Timeout halten, während
`/health` weiterhin erfolgreich antwortet ([Issue #4309](https://github.com/topoteretes/cognee/issues/4309)).
Ein synchron terminaler Improve-Fehler wird in dieser Version als HTTP 420 mit Run-Information
geliefert; Luczor persistiert diesen Zustand als sicher nicht mehr laufend, bestätigt den
terminalen Wrapper-Cache und lässt dadurch ein späteres Forget sofort vor.
Darum Improve nicht allein aufgrund eines erfolgreichen Normal-Smoke-Tests aktivieren. In
einer isolierten Abnahme einen hängenden LLM-/Pipeline-Aufruf provozieren, den kontrollierten
Container-Restart sowie Luczors exakte Run-Recovery prüfen und erst danach
`COGNEE_IMPROVE_ENABLED=true` setzen. Ohne diese Abnahme bleibt die Funktion aus; normales
Remember/Add/Cognify, Recall und Forget arbeiten unabhängig davon weiter.

## Bestehende Volumes und Umstellung

Die Umstellung kopiert keine Cognee-internen Tabellen aus der bisher gemeinsam genutzten
Laravel-Datenbank. Diese Tabellen und bestehende Top-Level-Dateien in `cognee_data` bleiben
unangetastet. Das Volume erhält für den neuen Stand die getrennten Verzeichnisse
`/data/system` und `/data/data`, sodass alte Dateiindizes nicht stillschweigend mit der
neuen relationalen Datenbank vermischt werden.

Vor der Umstellung sind PostgreSQL- und `cognee_data`-Backups anzulegen. Da Laravel SQL das
System of Record ist, wird der neue abgeleitete Cognee-Stand anschließend ausschließlich
aus bestätigten, aktiven Erinnerungen über die Outbox neu aufgebaut. Keine alten
Cognee-Tabellen manuell in die neue Datenbank kopieren. Ein Rückbau bleibt möglich, solange
die alte Datenbank und die alten Volume-Dateien nicht gelöscht wurden.

Das Ändern von `COGNEE_POSTGRES_DB`, `COGNEE_POSTGRES_USER`, Embedding-Modell oder
Embedding-Dimension ist eine Migration und keine normale Konfigurationsrotation. Dafür
immer Backup, neuen leeren Zielstand, Reprojektion, Smoke-Test und erst danach Umschaltung
einplanen.

## Memory-Vertrag

- Laravel SQL ist das System of Record; Cognee darf nur Rankinghinweise liefern.
- Jeder Cognee-Treffer wird gegen den aktiven, nicht abgelaufenen und für den Benutzer
  zulässigen SQL-Datensatz geprüft.
- Selbstverbesserung bleibt bei normalen Writes deaktiviert. Eine Verbesserung wird nur
  über Memory-Orchestrator und Outbox ausgelöst.
- Nur bestätigte, aktive und dauerhafte Erinnerungen werden projiziert.
- Rohchats, Kandidaten, Secrets, lokale Pfade, Repository-Code, Symbole und Graphdaten werden
  nicht nach Cognee geschrieben.
- Datasets sind nach Tenant, Benutzer und Scope benannt. Workspace-Datasets sind bewusst
  tenantweit; globale Datasets dürfen nur kuratiert beschrieben werden.
- `ENABLE_BACKEND_ACCESS_CONTROL=true` bleibt aktiviert. Der interne Service-Principal muss
  für die verwendeten Datasets die erforderlichen Read-/Write-/Delete-Rechte besitzen.

Ein Cognee-Ausfall darf Remember und Recall nicht blockieren. SQL bleibt lesbar; die Outbox
zeigt den Fehler an und ermöglicht einen kontrollierten Wiederanlauf.

## Rotation

### Datenbankpasswort

1. Nur `cognee_postgres_password` atomar durch einen neuen Wert ersetzen.
2. `docker compose --env-file .env.docker run --rm cognee-db-init` ausführen.
3. Cognee mit `docker compose --env-file .env.docker up -d --force-recreate cognee`
   neu erzeugen.
4. Projektions-Smoke-Test ausführen.

Der Initialisierer aktualisiert das Rollenpasswort, ohne es auszugeben. Laravel bleibt von
dieser Rotation unberührt.

### LLM-/Embedding-Schlüssel

Die jeweilige Secret-Datei atomar ersetzen, Cognee neu erzeugen und den Smoke-Test
wiederholen. Bei Embedding-Modell oder -Dimension nicht rotieren, sondern den oben
beschriebenen Migrationspfad verwenden.

### Cognee-Service-Key

`provision-cognee.ps1 -Force` erzeugt bewusst einen zusätzlichen Key und ersetzt erst nach
erfolgreicher Prüfung die lokale Secret-Datei. Danach `api` und `horizon` neu erzeugen und
den vorherigen Key in Cognee widerrufen. Das Löschen des alten Keys geschieht absichtlich
nicht automatisch, damit ein fehlgeschlagener Rollout rückrollbar bleibt.

Die Konfiguration folgt der für dieses Projekt gepinnten Cognee-1.4.0-Konfiguration und den
offiziellen Hinweisen zu
[Security & Privacy](https://docs.cognee.ai/setup-configuration/security),
[Self-hosted API-Authentisierung](https://docs.cognee.ai/api-reference/introduction),
[Remember](https://docs.cognee.ai/core-concepts/main-operations/remember) sowie zum
[Dataset-/ACL-Modell](https://docs.cognee.ai/core-concepts/multi-user-mode/permissions-system/overview).
