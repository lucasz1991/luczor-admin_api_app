# Decisions

Record durable decisions with date, context, decision, and consequences.

## 2026-08-23 | Canonical memory orchestration

- Laravel SQL and encrypted desktop memory are systems of record. Cognee is a rebuildable ranker, never the authorization or deletion authority.
- All callers use `MemoryOrchestrator`; automatic knowledge is a candidate and becomes durable only through explicit policy/promotion.
- Every durable write carries provenance, confidence, observed/valid/recorded time, retention, supersession, and stable HMAC-protected replay identities.

## 2026-08-23 | Account memory ownership and erasure

- `MemoryLink.user_id` is ownership for `device/private/project/skill/agent/session` only.
- `workspace` ownership belongs to `tenant_id`; `global` belongs to the curated global namespace. Account deletion preserves those rows and removes only the actor reference.
- Unknown scopes, contradictory recovery identities, and workspace rows without a matching tenant block deletion instead of guessing.
- Canonical content rows are deleted, while content-free idempotency events become unlinkable version-3 tombstones. Provider deletion stays asynchronous and durable beyond the User row.
- Ambiguous Add recovery is valid only with the exact original `(provider_memory_link_id, content_hash)` pair.

## 2026-08-23 | Bounded semantic recall

- Every occupied authorized alias is sent in one Cognee search request with `COGNEE_SEMANTIC_QUERY_TIMEOUT`; the ingestion timeout is not reused.
- A failed semantic batch contributes no partial ranks. SQL authorization, full chunked lexical retrieval, recency, and final DLP filtering remain authoritative.
- Cognee hits are always rehydrated through active authorized SQL rows before ranking or prompt use.

## 2026-08-23 | Local repository knowledge

- Repository paths, code, symbols, relations, and graph evidence stay on the desktop and are partitioned by account principal plus project.
- Tree-sitter supplies structure and SQLite FTS5/WAL supplies local retrieval. No repository scan is promoted to Cognee or server memory.
- Sending code to an external model requires the separate one-request release gate.

## 2026-08-23 | Cognee lifecycle and self-improvement

- A PostgreSQL advisory lease fences the complete Cognee/FastAPI lifespan, including upstream startup and shutdown.
- Add recovery lookups gain priority as soon as they wait; later Adds cannot starve recovery.
- Improve remains opt-in. A live same-boot generation is replayed/polled idempotently; a changed boot after account erasure proves the old process-local task dead and yields to Forget without relaunch.

## 2026-08-26 | Veröffentlichte Migrationen bleiben unverändert und Wiederaufnahme ist fail closed

- `2026_08_23_000001_add_memory_orchestration_fields` entspricht wieder exakt dem zuerst veröffentlichten Schema. Neue Felder erhalten eine eigene, in der Reihenfolge separat erkennbare Migration.
- Die Repair-Migration löscht in `down()` keine möglicherweise bereits vorhandene Fingerprint-Spalte, weil deren Eigentümerschaft nach dem historischen Drift nicht sicher beweisbar ist.
- Eine nach MySQL-DDL-Abbruch vorhandene `memory_write_events`-Tabelle wird weder gelöscht noch blind übernommen. `000002` setzt nur fort, wenn Spalten, portable Typen/Längen, Nullability, Default, Primary-/Unique-/Sekundärindizes und beide Foreign Keys vollständig passen.
- Produktionsmigrationen bleiben an Wartungsmodus, Backup, read-only Schema-Evidenz und stabile Namespace-/Ledger-Schlüssel gebunden.

## 2026-08-26 | Memory-Event-Datasets behalten die veröffentlichte MySQL-Länge

- Luczor setzt global `Schema::defaultStringLength(191)`; die beim ersten MySQL-Lauf von `000002` angelegte `memory_write_events.dataset`-Spalte ist deshalb vertragsgemäß `VARCHAR(191)`.
- `000002` verwendet für Erstellung und Existing-Table-Prüfung dieselbe explizite Konstante. Eine partielle Tabelle wird weiterverwendet und weder gelöscht noch unnötig verändert.
- Ein echter MySQL-/MariaDB-Migrationslauf bleibt Teil der Produktionsabnahme, da SQLite deklarierte Zeichenlängen nicht zuverlässig validiert.

## 2026-08-27 | Plesk erreicht Cognee nur ueber Host-Loopback

- Laravel unter Plesk verwendet `http://127.0.0.1:8010`; Cognee wird weder an eine externe Hostadresse noch an die oeffentliche Laravel-Domain gebunden.
- `COGNEE_API_KEY_FILE` hat Vorrang vor dem direkten ENV-Wert und scheitert bei relativen, fehlenden, leeren oder unlesbaren Dateien fail closed.
- Der read-only `luczor:cognee-check` ersetzt nicht den Add-/Cognify-/Search-/Forget-Produktpfad-Smoke.
