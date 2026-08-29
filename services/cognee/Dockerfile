# Pin both the audited release and its multi-architecture manifest. `main`
# changes without notice and makes production rebuilds non-reproducible.
FROM cognee/cognee:1.4.2@sha256:680cd87732430cf5e52b1ff58ae7ec2ed6ed71a9a32a9e77b8a0627f425cc4c3 AS luczor-cognee-runtime

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

# Cognee's Kuzu/Ladybug graph adapter needs the JSON extension during every
# Cognify and Forget. Install it while the image build has controlled egress,
# validate the immutable binary, and ship the cache in the runtime image so an
# internal-only production container never downloads executable code.
ARG TARGETARCH
RUN /app/.venv/bin/python -c "import ladybug; database = ladybug.Database('/tmp/luczor-ladybug-extension-probe.lbug'); connection = ladybug.Connection(database); connection.execute('INSTALL JSON;'); connection.execute('LOAD EXTENSION JSON;')" \
    && extension_file="$(find /root/.lbdb/extension -type f -path '*/json/libjson.lbug_extension' -print -quit)" \
    && test -n "$extension_file" \
    && case "$TARGETARCH" in \
        amd64) expected_hash=4261127eeb778b5d3e42511a16865b15be118619c528c493f91c91daa2eadd43 ;; \
        arm64) expected_hash=f5fd5a421fd6fc52a731d1a6305d00ceb3a37a441c60d73d0a08cf89533cfbe7 ;; \
        *) echo "Unsupported Ladybug extension architecture: $TARGETARCH" >&2; exit 1 ;; \
    esac \
    && printf '%s  %s\n' "$expected_hash" "$extension_file" | sha256sum -c - \
    && rm -rf /tmp/luczor-ladybug-extension-probe.lbug*
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
COPY test_luczor_*.py /opt/luczor-cognee-tests/

# Keep the default/final target production-identical for existing builds.
FROM luczor-cognee-runtime AS luczor-cognee-production
