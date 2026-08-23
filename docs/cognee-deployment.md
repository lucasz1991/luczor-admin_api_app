# Cognee: isolierter und authentisierter Betrieb

Cognee ist nur über die internen Docker-Netze erreichbar und verwendet eine eigene
PostgreSQL-Datenbank, eine eingeschränkte Datenbankrolle sowie eigene LLM- und
Embedding-Zugangsdaten. Laravel kennt weder das Cognee-Datenbankpasswort noch die
Provider-Schlüssel. Cognee kennt umgekehrt weder das Laravel-Datenbankpasswort noch den
Laravel-/OpenRouter-Schlüssel.

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

Die HTTP-Healthcheck-Route prüft keine LLM- oder Embedding-Antwort. Deshalb bleibt ein
echter `add`-/Hintergrund-`cognify`-Smoke-Test nach jeder Erstbereitstellung oder Provideränderung
verpflichtend.

Geheime Werte liegen ausschließlich als Dateien unter `admin_api_app/docker/secrets/`:

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

## Erstbereitstellung

Alle Befehle werden vom Workspace-Root ausgeführt.

1. Beispielkonfiguration kopieren und Provider/Modelle prüfen:

   ```powershell
   Copy-Item .env.docker.example .env.docker
   ```

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

   - Prüfen, dass der Outbox-Eintrag nacheinander die persistierten Phasen `ingested` und
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
spiegelt die Startantwort dauerhaft in seiner Cognee-Datenbank und stellt eine
authentisierte Exact-Run-Abfrage bereit, weil Cognees Activity-Feed nur die neuesten 50
Zeilen liefert. Ein Timeout setzt deshalb nie blind einen zweiten Run in Gang. Der
HTTP-Timeout muss kleiner als der Job-Timeout und dieser kleiner als Redis `retry_after`
bleiben.

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
