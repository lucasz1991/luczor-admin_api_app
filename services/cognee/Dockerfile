# Pin both the audited release and its multi-architecture manifest. `main`
# changes without notice and makes production rebuilds non-reproducible.
FROM cognee/cognee:1.4.0@sha256:d8931a59034a6c69f3cf4a81e1ba0b5ddc660691a600a7711f6278aad26bb678 AS luczor-cognee-runtime

# The upstream image intentionally ships only local defaults. Its runtime venv
# has no pip binary, so install the pinned PostgreSQL extras into that venv
# without replacing the bundled Cognee version from PyPI.
RUN /usr/local/bin/python -m pip install --no-cache-dir --target /app/.venv/lib/python3.12/site-packages \
    "asyncpg==0.31.0" \
    "fastembed==0.8.0" \
    "onnxruntime==1.23.2" \
    "pgvector==0.3.6" \
    "psycopg2-binary==2.9.12"

# Cache the audited local embedding model while the image is built. Production
# runs with the Hugging Face clients in offline mode and therefore never need
# registry access for embeddings.
ENV FASTEMBED_CACHE_PATH=/opt/luczor-fastembed-cache
RUN /app/.venv/bin/python -c "from fastembed import TextEmbedding; model = TextEmbedding(model_name='sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2', cache_dir='/opt/luczor-fastembed-cache'); next(model.embed(['Luczor Erinnerung']))"
ENV HF_HUB_OFFLINE=1 \
    TRANSFORMERS_OFFLINE=1 \
    HF_DATASETS_OFFLINE=1 \
    HF_HUB_DISABLE_TELEMETRY=1

# Cognee's Alembic environment logs the full asyncpg URL by default, including
# the password. Keep connection diagnostics without ever emitting a secret.
# ConfigParser also treats percent escapes in URL-encoded passwords as
# interpolation syntax, so escape them only while storing the option.
RUN sed -i 's/logging.getLogger("alembic.env").info("Using database: %s", db_uri)/logging.getLogger("alembic.env").info("Using configured database")/' /app/cognee/alembic/env.py \
    && sed -i 's/^    db_uri,$/    db_uri.replace("%", "%%"),/' /app/cognee/alembic/env.py

# Wrap the pinned application with a boot identity header. This is used only
# to recover provably dead process-local background runs after a restart.
COPY luczor_cognee_app.py /app/luczor_cognee_app.py
COPY luczor_identities.py /app/luczor_identities.py
COPY luczor_lifespan.py /app/luczor_lifespan.py
COPY luczor_upload_policy.py /app/luczor_upload_policy.py
RUN sed -i 's/cognee\.api\.client:app/luczor_cognee_app:app/g' /app/entrypoint.sh

COPY --chmod=755 entrypoint.sh /usr/local/bin/luczor-cognee-entrypoint
ENTRYPOINT ["/usr/local/bin/luczor-cognee-entrypoint"]

# The acceptance target is opt-in and is never selected by the production
# compose file. It contains only the deterministic local provider and runner;
# no provider credentials or production data are copied into the image.
FROM luczor-cognee-runtime AS luczor-cognee-e2e
COPY e2e /opt/luczor-cognee-e2e
COPY test_luczor_identities.py test_luczor_upload_policy.py /opt/luczor-cognee-tests/

# Keep the default/final target production-identical for existing builds.
FROM luczor-cognee-runtime AS luczor-cognee-production
