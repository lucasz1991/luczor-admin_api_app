# Current state

## Confirmed

- `MemoryOrchestrator` is the shared policy boundary for HTTP, Context, Workflows, and Skills; Laravel SQL is canonical and Cognee is a rebuildable semantic projection.
- Automatic or inferred writes remain candidates until promotion. Active durable writes carry provenance, confidence, validity, supersession, stable write identities, and a durable projection outbox.
- Cognee Add/Cognify/Improve/Forget recovery is idempotent and restart-aware. Exact Add recovery uses the immutable `(provider_memory_link_id, content_hash)` pair; account erasure and migrations stop fail-closed when that pair is missing or contradictory.
- Account deletion serializes against writes, removes user-owned memories, detaches only proven shared memories, re-HMACs ledger tombstones, and keeps exact provider deletion durable until acknowledged.
- Recall unions authorized SQL recency, complete chunked lexical discovery, and SQL-revalidated Cognee hits. All occupied authorized aliases share one three-second semantic request; any provider failure immediately falls back to SQL.
- The Cognee singleton lease wraps the complete upstream lifespan. Exact Add lookup has writer priority, and an erased ambiguous Improve is never relaunched after a wrapper restart.
- Desktop memory is encrypted and bound to a server-verified account principal. Repository indexing is local-only through Tree-sitter plus SQLite/FTS5; server graph indexing remains an explicit legacy profile.
- The production `write_fingerprint` failure was traced to an already-deployed migration being edited after release. The published `000001` shape is restored, a separately logged repair runs before `000002`, and `000002` safely resumes a MySQL partial-DDL table only after validating its complete column/index/foreign-key contract.
- The production `000002` retry exposed a contract bug rather than schema damage: Luczor's global string default created `dataset` as `VARCHAR(191)`. Creation and validation now share that published length, so the retained MySQL partial table can resume without destructive DDL.
- Plesk-Laravel kann Cognee jetzt ueber einen ausschließlich auf `127.0.0.1:8010` gebundenen Docker-Endpunkt erreichen. Der Service-Key wird bevorzugt aus einer absoluten geschuetzten Datei gelesen; `luczor:cognee-check` prueft Authentifizierung und Wrapper-Boot-ID ohne Memory-Write.
- Plesk betreibt das exakt per Digest gepinnte Redis 7.4.11 restartfest hinter einem internen Nginx-TCP-Gateway. Ausschliesslich das Gateway bindet `127.0.0.1:6379`; anonyme Zugriffe enden mit `NOAUTH`, Laravel liest das Passwort aus der externen Secret-Datei, AOF ist aktiv und `vm.overcommit_memory=1` gesetzt.
- OpenAI ist keine Memory-Voraussetzung. Das kanonische SQL-/Desktop-Memory und der lokale Repository-Graph bleiben providerfrei; nur die optionale Cognee-Projektion benoetigt ein LLM und Embeddings, die fuer Plesk lokal ueber Ollama/FastEmbed vorgesehen sind.
- Die Redis-Haertung ist live: read-only Redis-Container mit reduziertem Capability-Satz, schreibbarem tmpfs nur fuer die generierte Konfiguration, persistentem Host-Verzeichnis, dateibasiertem Passwort ohne Secret im Prozessargument und Laravel-`REDIS_PASSWORD_FILE`-Laufzeitinjektion ohne Secret im Config-Cache. Konfigurations- und Datenrechte bleiben auch nach einem echten Containerneustart verwendbar.
- Der Production-Gate prueft Redis-Authentisierung und privaten Endpunkt statisch sowie `vm.overcommit_memory=1` im echten Runtime-Lauf.
- Der eigenstaendige Plesk-Memory-Stack ist repositoryseitig auf lokale Inferenz umgestellt: Cognee nutzt Ollama `llama3.2:3b` sowie ein vorab geladenes, 384-dimensionales mehrsprachiges FastEmbed-Modell. Beide Runtime-Dienste bleiben in internen Docker-Netzen; nur ein explizites Bootstrap-Profil darf das Ollama-Modell einmalig laden.
- Das Admin-Dashboard trennt jetzt das operative Lagebild von der direkten Konfiguration: Systemstatus, vier Kernmetriken, 14-Tage-Verlauf, Hinweise, letzte Provider-Versuche und sechs Modulzugaenge stehen vor fuenf gezielt aufklappbaren Werkzeuggruppen. Bestehende Admin-Aktionen, Routen, Rollen- und Kundengrenzen bleiben erhalten.
- Das Admin-Memory-Archiv trennt jetzt das kanonische `memory_links`-Netzwerk sichtbar vom separaten Sync-/Audit-Archiv. Ein dependency-freies 3D-Metadatenmodell zeigt stabile Projekt-, Typ- und Scope-Hubs sowie echte `supersedes_id`-Versionskanten; semantische Cognee-Beziehungen werden nicht vorgetaeuscht.
- Docker-Secret-Dateien liegen produktiv ausschließlich unter `/var/lib/luczor/secrets` als `root:root` mit Verzeichnis-Modus `0700` und Datei-Modus `0600`. Compose, Initialisierer und Cognee-Provisionierung teilen den konfigurierbaren `LUCZOR_DOCKER_SECRETS_DIR`-Vertrag; der Git-Checkout enthält kein Secret-Verzeichnis mehr.
- Plesk-Git-Deployments verwenden wieder ausschließlich den Subscription-Systembenutzer. Die einmalige Reparatur war am Bare-Repository-Manifest gebunden, schloss `.env`, Runtime-Storage, `vendor`, Docker-Daten und externe Secrets aus und legte vor Änderungen ein ACL-Rollback ab.
- Der lokale Plesk-Memory-Stack laeuft live mit Cognee 1.4.2, externen Secret-Mounts, lokalem Ollama/FastEmbed und ohne OpenAI-Zugangsdaten. `COGNEE_IMPROVE_ENABLED=true` gilt nur fuer explizite Improve-Aufrufe; normale Writes starten Improve nicht automatisch. Remember, Cognify, Improve, semantischer Recall, SQL-Fallback, Forget und Provider-Cleanup sind ueber den echten Produktionspfad bestanden.
- Ein echter laufender Cognify-Lauf wurde durch einen gezielten Cognee-Neustart unterbrochen und danach mit `recovery_generation=1` erfolgreich wiederhergestellt. Der synthetische Benutzer, SQL-Link und Provider-Datensatz wurden anschliessend vollstaendig bereinigt.
- Nur `luczor:scheduler-heartbeat` darf im Wartungsmodus laufen, damit das Deployment-Gate beobachtbar bleibt. Workflow-, Memory- und Cleanup-Schedules bleiben im Wartungsmodus weiterhin angehalten.

## Verification

- `php artisan test` — 383 passed, 2647 assertions.
- `vendor\bin\phpstan analyse --no-progress` — no errors.
- `vendor\bin\pint --test` — passed.
- Cognee Python discovery — 4 tests passed; `py_compile` passed.
- Desktop — formatting, lint, typecheck, build, 203 Vitest tests, 37 Rust tests, `cargo fmt`, and `cargo clippy -D warnings` passed.
- `docker compose --env-file .env.docker.example config --quiet` — passed.
- Final focused concurrency/security review — no remaining P0-P2 findings.
- Memory write-event upgrade regression — published legacy schema, pre-existing fingerprint, Laravel discovery order, partial event table, repeatable backfill, malformed columns, nullability, unique index and both foreign keys covered; 8 focused tests plus a fresh-migration/orchestrator smoke passed.
- Plesk-Cognee adapter — 344 tests / 2,309 assertions, PHPStan, Pint, Laravel config cache and merged loopback Compose configuration passed.
- Standalone Plesk-Memory-Stack — 345 tests / 2,322 assertions, PHPStan, Pint, `docker compose config --quiet` und maschinelle Loopback-Portpruefung bestanden.
- Plesk Redis — `luczor-memory-redis-1` und `luczor-memory-redis-loopback-1` sind gesund, ohne Restart oder OOM; Bindung nur `127.0.0.1:6379`; authentisiertes PING erfolgreich und anonymer Zugriff blockiert. DB0-/DB1-Sentinels ueberstanden Cutover und Neustart, Laravel, Horizon, Scheduler und Login HTTP 200 sind gruen.
- Redis-Haertung — 28 Python-Wrapper-Tests (ein Windows-Symlink-Skip), Laravel-Regressionssuite, Compose-Aufloesung sowie echte Linux-Container-Smokes fuer Secret, AOF, Restart, NOAUTH, Loopback und `vm.overcommit_memory=1` bestanden.
- Lokale Cognee-Provider — 46 fokussierte Tests / 293 Assertions, Pint, normale und Bootstrap-Compose-Aufloesung, interne Runtime-Netze ohne Ollama-Host-Port, POSIX-Shellsyntax und `git diff --check` bestanden.
- Admin-Dashboard — 365 Laravel-Tests / 2.464 Assertions, PHPStan, Pint, Blade-Cache und Vite-Produktionbuild mit Node 22.22.0 bestanden. Browser-QA mit leeren und befuellten Daten bei 1280/1024/1023/390/320 px bestaetigte keine horizontale Ueberlaeufe, keine unbenannten Controls, gezieltes Fehler-Disclosure und keine offenen P1/P2-Befunde.
- Memory-Archiv-3D — 6 fokussierte Feature-Tests / 25 Assertions, PHPStan, Pint, Blade-Cache, Vite-Produktionbuild mit Node 22.22.0 und `git diff --check` bestanden. Isolierte Browser-QA bei 1280/390/320 px bestaetigte Drag-/Zoom-Modell, Suche, Scope-Filter, Inspector, echte Versionskante, Live-Ansage, 44px-Steuerungen, fehlenden Seitenueberlauf und eine leere frische Browserkonsole; Review-Findings wurden geschlossen.
- Externe Docker-Secrets/Plesk-Deploy — 377 Laravel-Tests / 2.583 Assertions, PHPStan, Pint, POSIX-/PowerShell-Syntax, beide Compose-Vertraege mit 7 beziehungsweise 17 extern aufgeloesten Secret-Dateien und unabhaengiger Review ohne P1/P2 bestanden.
- Live Plesk — Commit `d771098` erfolgreich ueber die Plesk-Git-Integration ausgerollt. Redis, Redis-Gateway, PostgreSQL, Ollama, Cognee 1.4.2 und Cognee-Gateway sind gesund, ohne Restart oder OOM; alter `luczor-redis-auth`-Container ist gestoppt und nur als Rollback erhalten.
- Live Anwendung — Migrationen aktuell; Configuration-only- und vollstaendiger Production-Gate vollstaendig gruen; `luczor:cognee-check`, `luczor:memory-production-smoke --force --improve --timeout=1800`, ein Improve-Neustart-Smoke und ein echter Cognify-Boot-Recovery-Canary bestanden. `smoke_users=0` und `synthetic_links=0`; Login liefert HTTP 200.

## Deployment blockers

- Keine offenen Blocker für den aktuellen Plesk-Git-, Laravel-, Redis- oder lokalen Cognee-Memory-Betrieb.
- Keine offenen Blocker fuer Redis, Cognee Improve oder den gemeinsamen App-Test. Improve bleibt bewusst explizit und wird nicht an normale Memory-Writes gekoppelt.
- Der alte eigenstaendige `luczor-redis-auth`-Container ist gestoppt und darf erst entfernt werden, wenn die vereinbarte Rollback-Aufbewahrung abgelaufen ist.
- Der Repository-Graph-Indexer bleibt absichtlich Desktop-lokal und wird auf Plesk weder gestartet noch mit Server-Secrets versorgt.

## 2026-09-05 | Isolierter lokaler Modell-Testbootstrap

- `luczor:local-model-test:bootstrap --token-file=<absoluter Pfad>` erzeugt beziehungsweise erneuert ausschliesslich unter `APP_ENV=local|testing` und einer tatsaechlichen SQLite-Standardverbindung eine dedizierte aktive Minimalidentitaet.
- Der kurzlebige, geraetegebundene API-Key besitzt exakt `settings.read`; gleichnamige Alt-Keys werden deaktiviert. Es werden weder Seeder noch Device-, Settings- oder Modelldaten angelegt.
- Der Klartext-Token wird ausschliesslich in die explizit angegebene absolute Datei ausserhalb des Checkouts geschrieben. Relative, UNC-, Checkout-interne, symbolisch/hart verknuepfte sowie fremde Bestandsdateien scheitern vor der Datenmutation.
- Verifiziert: komplette Laravel-Suite 435 Tests / 3.054 Assertions; fokussierte Bootstrap-, API-Key- und Local-Model-Manifest-Suite 29 Tests / 242 Assertions; gezieltes PHPStan ohne Fehler; Pint und `git diff --check` bestanden.

## 2026-09-06 | Editable assistant profile and local baseline

- Added actor-scoped `GET /api/v1/assistant-profile` with `settings.read`; the same profile is returned as `bootstrap.assistant_profile`. Contract: selected active persona or null; active global plus actor-owned prompt skills; deterministic revision hash; optional descriptions and tags normalized to empty string/array. Workflow skills never start automatically.
- The server proxy inserts the authoritative persona and active prompt skills before request history. A missing actor receives only global skills. Desktop can use this profile for local inference without sending duplicate profile instructions to the proxy.
- Admin persona and skill editors update by stable ID, preserving slug, ownership and activation. The approved German discussion draft comprises one clear/friendly Luczor persona and three conditional skills: Laravel backend; Livewire/Alpine/Tailwind; local support and diagnosis.
- `luczor:assistant-defaults` permits only local/testing plus SQLite. Explicit `--env=local` seeded the existing local model-test control-plane SQLite: first run 1 persona + 3 skills, repeat 0 + 0. The production `.env`/MySQL database and remote server were not touched.
- Verified: 24 focused tests / 105 assertions; 52 proxy/routing tests / 370 assertions; targeted PHPStan, Pint, Vite production build. GET-only loopback visual preview passed editor pointer/keyboard operation and 390/320px document overflow checks. Only console issue was the fixture-only favicon 404. Screenshots: `artifacts/screenshots/assistant-admin-desktop.png`, `artifacts/screenshots/assistant-admin-mobile.png`.
- External work advanced backend HEAD to `f84a06d` during implementation and captured source changes; this agent did not commit or undo it. No production deployment performed.

## 2026-09-06T12:59:13Z | Assistant production deployment

- Explicit user authorization followed by successful Plesk Git deployment of d49abf0. All536 tracked files except the deliberately retained server package-lock match the commit. New Vite assets live; Blade rebuilt; Horizon gracefully restarted.
- Production baseline created1 active persona/3 active global skills; second run0/0. Private source backup /var/backups/luczor-assistant-20260906T125505Z and verified DB pre-write snapshot outside webroot retained.
- HTTPS profile/bootstrap/manifest200, unauth profile401, health/ready200; production gate23/23. Profile contents/revision, RSA signature/pin and catalog2026090602/context32768 verified. Temporary key removed; environment/signing/catalog and existing rows/selection preserved.
- Full report in workspace root .lmzdev/artifacts/reports/2026-09-06-assistant-server-deployment.md. No desktop restart or installer publication.
