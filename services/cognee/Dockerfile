FROM cognee/cognee:main

# The upstream image intentionally ships only local defaults. Its runtime venv
# has no pip binary, so install the pinned PostgreSQL extras into that venv
# without replacing the bundled Cognee version from PyPI.
RUN /usr/local/bin/python -m pip install --no-cache-dir --target /app/.venv/lib/python3.12/site-packages \
    "asyncpg>=0.30.0,<1.0.0" \
    "pgvector>=0.3.5,<0.4" \
    "psycopg2-binary>=2.9.10,<3.0.0"

# Cognee's Alembic environment logs the full asyncpg URL by default, including
# the password. Keep connection diagnostics without ever emitting a secret.
RUN sed -i 's/logging.getLogger("alembic.env").info("Using database: %s", db_uri)/logging.getLogger("alembic.env").info("Using configured database")/' /app/cognee/alembic/env.py

COPY --chmod=755 entrypoint.sh /usr/local/bin/luczor-cognee-entrypoint
ENTRYPOINT ["/usr/local/bin/luczor-cognee-entrypoint"]
