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

## Deployment blockers

- No production migration or deployment was performed.
- The local `.env.docker` is not deployable yet: Cognee LLM/embedding provider settings and Cognee secret files are absent.
- Provision two independent stable secrets for `LUCZOR_MEMORY_NAMESPACE_KEY` and `LUCZOR_MEMORY_LEDGER_KEY`; do not invent or rotate them during rollout.
- Run migrations `2026_08_23_000001` through `000005` only in full maintenance mode with backup and the documented ownerless-Add preflight/recovery procedure.
- Before retrying the failed production `000002`, deploy the repair, inspect `migrate:status`, `memory_links.write_fingerprint`, `SHOW CREATE TABLE memory_write_events` and its row count read-only; do not drop or mark the partial table manually.
- The latest Plesk configuration-only gate reports only `debug_disabled`; Redis cache/queue, Reverb and both stable Memory keys are now loaded. Set `APP_DEBUG=false`, clear caches and re-run the gate before switching traffic.
- Docker Desktop was not running locally, so the real Cognee container, provider quality and Add/Cognify/Search/Forget product-path smoke remain production acceptance work.
- Keep Cognee 1.4 Improve disabled until the documented live hang, timeout, restart, and Forget smoke test passes.
- The desktop build warned that installed Node 22.11 is below the package requirement of 22.12 or newer.
