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
- Plesk betreibt Redis 7.4.11 als privaten, automatisch neu startenden Container auf Host-Port `127.0.0.1:6379`, mit 256-MB-Limit und persistentem Host-Verzeichnis. Laravel-Cachezugriff und der echte Login-POST funktionieren wieder.
- OpenAI ist keine Memory-Voraussetzung. Das kanonische SQL-/Desktop-Memory und der lokale Repository-Graph bleiben providerfrei; nur die optionale Cognee-Projektion benoetigt ein LLM und Embeddings, die fuer Plesk lokal ueber Ollama/FastEmbed vorgesehen sind.
- Die repositoryseitige Redis-Haertung ist vorbereitet: gepinntes Redis 7.4.11, Loopback-Port, persistentes Host-Verzeichnis, AOF, read-only Container, dateibasiertes Passwort ohne Secret im Prozessargument und Laravel-`REDIS_PASSWORD_FILE`-Laufzeitinjektion ohne Secret im Config-Cache.
- Der Production-Gate prueft Redis-Authentisierung und privaten Endpunkt statisch sowie `vm.overcommit_memory=1` im echten Runtime-Lauf.
- Der eigenstaendige Plesk-Memory-Stack ist repositoryseitig auf lokale Inferenz umgestellt: Cognee nutzt Ollama `llama3.2:3b` sowie ein vorab geladenes, 384-dimensionales mehrsprachiges FastEmbed-Modell. Beide Runtime-Dienste bleiben in internen Docker-Netzen; nur ein explizites Bootstrap-Profil darf das Ollama-Modell einmalig laden.
- Das Admin-Dashboard trennt jetzt das operative Lagebild von der direkten Konfiguration: Systemstatus, vier Kernmetriken, 14-Tage-Verlauf, Hinweise, letzte Provider-Versuche und sechs Modulzugaenge stehen vor fuenf gezielt aufklappbaren Werkzeuggruppen. Bestehende Admin-Aktionen, Routen, Rollen- und Kundengrenzen bleiben erhalten.
- Docker-Secret-Dateien liegen produktiv ausschließlich unter `/var/lib/luczor/secrets` als `root:root` mit Verzeichnis-Modus `0700` und Datei-Modus `0600`. Compose, Initialisierer und Cognee-Provisionierung teilen den konfigurierbaren `LUCZOR_DOCKER_SECRETS_DIR`-Vertrag; der Git-Checkout enthält kein Secret-Verzeichnis mehr.
- Plesk-Git-Deployments verwenden wieder ausschließlich den Subscription-Systembenutzer. Die einmalige Reparatur war am Bare-Repository-Manifest gebunden, schloss `.env`, Runtime-Storage, `vendor`, Docker-Daten und externe Secrets aus und legte vor Änderungen ein ACL-Rollback ab.
- Der lokale Plesk-Memory-Stack läuft live mit externen Secret-Mounts, lokalem Ollama/FastEmbed und ohne OpenAI-Zugangsdaten. Remember, Cognify, semantischer Recall, SQL-Fallback, Forget und Provider-Cleanup sind über den echten Produktionspfad bestanden.

## Verification

- `php artisan test` — 337 passed, 2292 assertions.
- `vendor\bin\phpstan analyse --no-progress` — no errors.
- `vendor\bin\pint --test` — passed.
- Cognee Python discovery — 4 tests passed; `py_compile` passed.
- Desktop — formatting, lint, typecheck, build, 203 Vitest tests, 37 Rust tests, `cargo fmt`, and `cargo clippy -D warnings` passed.
- `docker compose --env-file .env.docker.example config --quiet` — passed.
- Final focused concurrency/security review — no remaining P0-P2 findings.
- Memory write-event upgrade regression — published legacy schema, pre-existing fingerprint, Laravel discovery order, partial event table, repeatable backfill, malformed columns, nullability, unique index and both foreign keys covered; 8 focused tests plus a fresh-migration/orchestrator smoke passed.
- Plesk-Cognee adapter — 344 tests / 2,309 assertions, PHPStan, Pint, Laravel config cache and merged loopback Compose configuration passed.
- Standalone Plesk-Memory-Stack — 345 tests / 2,322 assertions, PHPStan, Pint, `docker compose config --quiet` und maschinelle Loopback-Portpruefung bestanden.
- Plesk Redis — Container `luczor-redis` ist `Running`; `php artisan cache:clear` erfolgreich; echter `POST /login` liefert die erwartete Credential-Fehlermeldung statt HTTP 500; Configuration-only Deployment-Gate vollstaendig gruen.
- Redis-Haertung — vollstaendig 357 Tests / 2.367 Assertions, Compose-JSON/Loopback/Bind-Mount, POSIX-Shellsyntax, Config-Cache ohne Secretwert, PHPStan und Pint bestanden; der echte Container-Runtime-Smoke bleibt mangels lokalem Docker-Daemon offen.
- Lokale Cognee-Provider — 46 fokussierte Tests / 293 Assertions, Pint, normale und Bootstrap-Compose-Aufloesung, interne Runtime-Netze ohne Ollama-Host-Port, POSIX-Shellsyntax und `git diff --check` bestanden.
- Admin-Dashboard — 365 Laravel-Tests / 2.464 Assertions, PHPStan, Pint, Blade-Cache und Vite-Produktionbuild mit Node 22.22.0 bestanden. Browser-QA mit leeren und befuellten Daten bei 1280/1024/1023/390/320 px bestaetigte keine horizontale Ueberlaeufe, keine unbenannten Controls, gezieltes Fehler-Disclosure und keine offenen P1/P2-Befunde.
- Externe Docker-Secrets/Plesk-Deploy — 377 Laravel-Tests / 2.583 Assertions, PHPStan, Pint, POSIX-/PowerShell-Syntax, beide Compose-Vertraege mit 7 beziehungsweise 17 extern aufgeloesten Secret-Dateien und unabhaengiger Review ohne P1/P2 bestanden.
- Live Plesk — Commit `5fcc521` erfolgreich über die Plesk-Git-Integration ausgerollt; alle sechs aktiven Postgres-/Cognee-Mounts zeigen auf `/var/lib/luczor/secrets`, vier Memory-Container sind gesund, ohne Restart oder OOM; Datenbank-Rollback wurde vor dem Recreate erzeugt.
- Live Anwendung — Migrationen aktuell; Configuration-only- und vollständiger Production-Gate vollständig grün; `luczor:cognee-check` sowie `luczor:memory-production-smoke --force --timeout=1800` bestanden und synthetische Provider-Daten bereinigt.

## Deployment blockers

- Keine offenen Blocker für den aktuellen Plesk-Git-, Laravel-, Redis- oder lokalen Cognee-Memory-Betrieb.
- Cognee 1.4 Improve bleibt bewusst deaktiviert, bis sein separater Hang-/Timeout-/Restart-/Forget-Test freigegeben und bestanden ist; das blockiert den normalen Memory-Pfad nicht.
- Der eigenständige `luczor-redis-auth`-Stack ist weiterhin der laufende passwortgeschützte Loopback-Redis. Sein verifier-basierter Start enthält kein Klartextpasswort; ein späterer kontrollierter Wechsel auf das repositoryseitige `redis-cutover`-Profil bleibt optionale Vereinheitlichung, nicht Deployment-Voraussetzung.
- Der Repository-Graph-Indexer bleibt absichtlich Desktop-lokal und wird auf Plesk weder gestartet noch mit Server-Secrets versorgt.
