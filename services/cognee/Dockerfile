# Pin both the audited release and its multi-architecture manifest. `main`
# changes without notice and makes production rebuilds non-reproducible.
FROM cognee/cognee:1.4.0@sha256:d8931a59034a6c69f3cf4a81e1ba0b5ddc660691a600a7711f6278aad26bb678

# The upstream image intentionally ships only local defaults. Its runtime venv
# has no pip binary, so install the pinned PostgreSQL extras into that venv
# without replacing the bundled Cognee version from PyPI.
RUN /usr/local/bin/python -m pip install --no-cache-dir --target /app/.venv/lib/python3.12/site-packages \
    "asyncpg==0.31.0" \
    "pgvector==0.3.6" \
    "psycopg2-binary==2.9.12"

# Cognee's Alembic environment logs the full asyncpg URL by default, including
# the password. Keep connection diagnostics without ever emitting a secret.
RUN sed -i 's/logging.getLogger("alembic.env").info("Using database: %s", db_uri)/logging.getLogger("alembic.env").info("Using configured database")/' /app/cognee/alembic/env.py

# Wrap the pinned application with a boot identity header. This is used only
# to recover provably dead process-local background runs after a restart.
COPY luczor_cognee_app.py /app/luczor_cognee_app.py
RUN sed -i 's/cognee\.api\.client:app/luczor_cognee_app:app/g' /app/entrypoint.sh

COPY --chmod=755 entrypoint.sh /usr/local/bin/luczor-cognee-entrypoint
ENTRYPOINT ["/usr/local/bin/luczor-cognee-entrypoint"]
