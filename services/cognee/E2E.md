# Local Cognee/PostgreSQL acceptance test

`docker-compose.cognee-e2e.yml` is a separate, disposable acceptance stack for
the Cognee version pinned in `Dockerfile`. It exercises the same HTTP contract
as `CogneeClient`: foreground Add, guarded background Cognify, exact pipeline
status, CHUNKS search, and item-level Forget.

Run from PowerShell:

```powershell
& .\admin_api_app\services\cognee\e2e\run.ps1
```

The harness deliberately does not load `.env`, `.env.docker`, Docker secrets,
or a production Compose service. Its credentials are fixed test-only values in
a PostgreSQL database backed by `tmpfs`. Cognee, PostgreSQL, the runner, and the
fake provider share one `internal: true` network with no published ports. Thus
runtime traffic cannot reach an external LLM or embedding provider. Image pulls
may still occur during the build before the isolated runtime starts.

Both Cognee's relational metadata and its graph-table adapter use the same
disposable PostgreSQL service. This avoids Ladybug/Kuzu's on-demand JSON
extension download and ensures the full graph state disappears with `tmpfs`.

The fake provider implements only `POST /v1/chat/completions` and
`POST /v1/embeddings`. It creates deterministic structured JSON and normalized
embeddings, counts all calls, rejects unknown routes, and never logs request
bodies or authorization headers. The acceptance runner captures a baseline
after Add and requires background Cognify to increase both expected request
counters while every failure counter remains zero.
Hugging Face/Transformers offline mode is forced; Cognee's explicit local-only
tokenizer identifier therefore falls back immediately to its bundled TikToken
counter instead of attempting a tokenizer download.

Cognee 1.4 converts an HTTP `UploadFile` into a managed `file://` path before
its ingestion task. Luczor keeps `ACCEPT_LOCAL_FILE_PATH=false`: its wrapper
registers only the exact canonical path returned by that upload operation in
the current async context, then consumes that permission once in the loader.
An existing file merely located below `DATA_ROOT_DIRECTORY` is not trusted.
Invalid filenames, arbitrary absolute paths, external `file://` URIs, cross-
context reuse, and symlink escapes still reach Cognee's original fail-closed
rejection. The harness runs those policy regressions inside Linux before the
HTTP flow so the symlink case is exercised even when launched from Windows.

Cognee 1.4 records the validated upload identity in `Data.name` without the
final `.txt` extension. Luczor maps only its strict
`luczor-memory-<id>-<sha256>.txt` filename to that deterministic stem for the
exact recovery lookup; it does not broaden the lookup to arbitrary names.

The idempotency guard hashes its NUL-delimited logical lock identity locally
before passing the 64-character digest to PostgreSQL `hashtextextended`.
PostgreSQL therefore never receives an embedded NUL, while the tuple remains
unambiguous and stable.

The PowerShell wrapper creates a unique Compose project and image name. Its
`finally` block removes containers, the internal network, anonymous resources,
and the locally built image. If Docker Desktop was stopped before the run, the
wrapper starts it and stops it again after cleanup. A successful final line is
a JSON object with `"status":"passed"` and the five checked flow stages.

Fast contracts without Docker:

```powershell
py -3 -m unittest discover -s .\admin_api_app\services\cognee\e2e -p 'test_*.py' -v
py -3 -m py_compile .\admin_api_app\services\cognee\e2e\fake_openai.py .\admin_api_app\services\cognee\e2e\seed_api_key.py .\admin_api_app\services\cognee\e2e\run_e2e.py
```
