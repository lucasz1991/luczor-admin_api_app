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

## Deployment blockers

- No production migration or deployment was performed.
- The local `.env.docker` is not deployable yet: die vollstaendig lokale Cognee/Ollama-Konfiguration und ihr Runtime-Smoke sind noch offen.
- Provision two independent stable secrets for `LUCZOR_MEMORY_NAMESPACE_KEY` and `LUCZOR_MEMORY_LEDGER_KEY`; do not invent or rotate them during rollout.
- Run migrations `2026_08_23_000001` through `000005` only in full maintenance mode with backup and the documented ownerless-Add preflight/recovery procedure.
- Before retrying the failed production `000002`, deploy the repair, inspect `migrate:status`, `memory_links.write_fingerprint`, `SHOW CREATE TABLE memory_write_events` and its row count read-only; do not drop or mark the partial table manually.
- Plesk `.env` verwendet `APP_ENV=production` und `APP_DEBUG=false`; `optimize:clear` und der Configuration-only-Gate laufen mit Redis vollstaendig erfolgreich.
- Die kostenlose Plesk-Docker-Erweiterung 2.1.10-14898 ist installiert. Der vorhandene `railtime-media`/LiveKit-Stack wurde dabei kurz neu gestartet und ist wieder `Running`; er wurde nicht veraendert. Der SSH-Webterminal bleibt ueber unverschluesseltes Port 8880 unerreichbar.
- Redis ist live; der eigenstaendige Cognee-Teil des Plesk-Memory-Stacks bleibt lokal vorbereitet. Cognee-PostgreSQL und lokale Inferenz muessen intern bleiben, der Repository-Graph-Indexer wird nicht serverseitig gestartet. Es wird kein OpenAI- oder anderer Cloud-Provider-Key verwendet.
- Der laufende direkte Plesk-Redis ist noch nicht passwortgeschuetzt und der Host-Sysctl wurde noch nicht live bestaetigt. Vor erneut gruenem Gate muessen der kontrollierte Compose-Cutover, `REDIS_PASSWORD_FILE`, anonymer `NOAUTH`-/authentisierter `PONG`-Smoke und `vm.overcommit_memory=1` nach `docs/redis-plesk.md` erfolgen.
- Docker Desktop was not running locally, so the real Cognee container, provider quality and Add/Cognify/Search/Forget product-path smoke remain production acceptance work.
- Keep Cognee 1.4 Improve disabled until the documented live hang, timeout, restart, and Forget smoke test passes.
- Das gepinnte Cognee-Image mit FastEmbed wurde lokal nicht gebaut und Ollama nicht gestartet. Vor Produktivfreigabe bleiben Image-Build, einmaliger Model-Bootstrap sowie Add/Cognify/Search/Forget inklusive Ressourcen- und Egress-Beobachtung erforderlich.
- The desktop build warned that installed Node 22.11 is below the package requirement of 22.12 or newer.
