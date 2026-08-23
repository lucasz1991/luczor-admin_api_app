"""Luczor safety layer around the pinned Cognee FastAPI application.

Cognee 1.4 background tasks are process-local while pipeline status rows are
durable. The boot UUID proves when a previously running task can no longer
exist. A PostgreSQL idempotency registry additionally makes a lost HTTP
response replayable without launching a second background run in the same process.
"""

import asyncio
import os
from uuid import UUID, uuid4

import asyncpg
from cognee.api.client import app
from cognee.modules.users.methods.get_authenticated_user import get_authenticated_user
from cognee.modules.users.models import User
from cognee.modules.users.permissions.methods.get_specific_user_permission_datasets import (
    get_specific_user_permission_datasets,
)
from fastapi import Depends, HTTPException, Query
from starlette.responses import Response


INSTANCE_ID = str(uuid4())
MAX_CACHED_RESPONSE_BYTES = 1024 * 1024
_pool = None
_pool_lock = asyncio.Lock()


def _valid_idempotency_key(value):
    return len(value) == 64 and all(character in "0123456789abcdef" for character in value)


def _response(
    status_code,
    body,
    content_type="application/json",
    launch_instance_id=None,
):
    # Preserve Cognee's exact media type. Passing a value which already contains
    # a charset as ``media_type`` would make Starlette append a second charset.
    response = Response(
        content=body,
        status_code=status_code,
        headers={"Content-Type": content_type},
    )
    response.headers["X-Luczor-Cognee-Instance"] = INSTANCE_ID
    if launch_instance_id:
        response.headers["X-Luczor-Cognee-Launch-Instance"] = launch_instance_id
    return response


async def _registry_pool():
    global _pool
    if _pool is not None:
        return _pool

    async with _pool_lock:
        if _pool is not None:
            return _pool
        _pool = await asyncpg.create_pool(
            host=os.environ["DB_HOST"],
            port=int(os.environ.get("DB_PORT", "5432")),
            user=os.environ["DB_USERNAME"],
            password=os.environ["DB_PASSWORD"],
            database=os.environ["DB_NAME"],
            min_size=1,
            max_size=4,
        )
        async with _pool.acquire() as connection:
            await connection.execute(
                """
                CREATE TABLE IF NOT EXISTS luczor_cognify_idempotency (
                    idempotency_key CHAR(64) PRIMARY KEY,
                    instance_id UUID NOT NULL,
                    state VARCHAR(16) NOT NULL,
                    response_status INTEGER NULL,
                    response_body BYTEA NULL,
                    response_content_type VARCHAR(255) NULL,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
                """
            )
            # A process-local task from an older boot cannot still be alive.
            # Completed here means "launch response durably cached", not that
            # the graph pipeline completed, so keep those responses forever:
            # they contain the exact run ID needed to reconcile after restart.
            await connection.execute(
                """
                DELETE FROM luczor_cognify_idempotency
                WHERE instance_id <> $1::uuid AND state = 'inflight'
                """,
                INSTANCE_ID,
            )
    return _pool


async def _claim(key):
    pool = await _registry_pool()
    async with pool.acquire() as connection, connection.transaction():
        inserted = await connection.fetchval(
            """
            INSERT INTO luczor_cognify_idempotency (idempotency_key, instance_id, state)
            VALUES ($1, $2::uuid, 'inflight')
            ON CONFLICT (idempotency_key) DO NOTHING
            RETURNING TRUE
            """,
            key,
            INSTANCE_ID,
        )
        if inserted:
            return "claimed", None

        row = await connection.fetchrow(
            """
            SELECT instance_id::text, state, response_status, response_body, response_content_type
            FROM luczor_cognify_idempotency
            WHERE idempotency_key = $1
            FOR UPDATE
            """,
            key,
        )
        if row["state"] == "completed":
            return "completed", row
        if row["instance_id"] != INSTANCE_ID:
            await connection.execute(
                """
                UPDATE luczor_cognify_idempotency
                SET instance_id = $2::uuid, state = 'inflight', response_status = NULL,
                    response_body = NULL, response_content_type = NULL, updated_at = NOW()
                WHERE idempotency_key = $1
                """,
                key,
                INSTANCE_ID,
            )
            return "claimed", None
        return "inflight", None


async def _complete(key, status_code, body, content_type):
    pool = await _registry_pool()
    async with pool.acquire() as connection:
        if 200 <= status_code < 300 and len(body) <= MAX_CACHED_RESPONSE_BYTES:
            await connection.execute(
                """
                UPDATE luczor_cognify_idempotency
                SET state = 'completed', response_status = $2, response_body = $3,
                    response_content_type = $4, updated_at = NOW()
                WHERE idempotency_key = $1 AND instance_id = $5::uuid
                """,
                key,
                status_code,
                body,
                content_type,
                INSTANCE_ID,
            )
        elif status_code in {400, 401, 403, 404, 405, 413, 415, 422}:
            # Only deterministic client rejections prove that no background
            # run was accepted. Ambiguous timeouts, conflicts, throttling and
            # server failures stay in-flight (fail closed) until a process
            # restart proves that the process-local task cannot still run.
            await connection.execute(
                "DELETE FROM luczor_cognify_idempotency WHERE idempotency_key = $1 AND instance_id = $2::uuid",
                key,
                INSTANCE_ID,
            )


@app.get("/api/v1/luczor/pipeline-runs/{pipeline_run_id}")
async def get_exact_pipeline_run(
    pipeline_run_id: UUID,
    dataset_id: UUID = Query(...),
    user: User = Depends(get_authenticated_user),
):
    """Return the newest transition for one exact run without the 50-row feed cap."""
    permitted = await get_specific_user_permission_datasets(user.id, "read", [dataset_id])
    if not permitted:
        raise HTTPException(status_code=404, detail="Pipeline run not found.")

    from sqlalchemy import select

    from cognee.infrastructure.databases.relational import get_relational_engine
    from cognee.modules.pipelines.models import PipelineRun

    engine = get_relational_engine()
    async with engine.get_async_session() as session:
        statement = (
            select(PipelineRun)
            .where(
                PipelineRun.dataset_id == dataset_id,
                PipelineRun.pipeline_run_id == pipeline_run_id,
            )
            .order_by(PipelineRun.created_at.desc())
            .limit(1)
        )
        run = (await session.execute(statement)).scalars().first()

    if run is None:
        raise HTTPException(status_code=404, detail="Pipeline run not found.")

    return {
        "id": str(run.id),
        "pipeline_name": run.pipeline_name,
        "status": run.status.value if run.status else None,
        "dataset_id": str(run.dataset_id) if run.dataset_id else None,
        "created_at": run.created_at.isoformat() if run.created_at else None,
        "pipeline_run_id": str(run.pipeline_run_id) if run.pipeline_run_id else None,
    }


@app.middleware("http")
async def luczor_runtime_guard(request, call_next):
    key = request.headers.get("X-Luczor-Idempotency-Key", "").lower()
    guarded_operation = request.method == "POST" and request.url.path in {
        "/api/v1/cognify",
        "/api/v1/improve",
    }
    if not guarded_operation or not _valid_idempotency_key(key):
        response = await call_next(request)
        response.headers["X-Luczor-Cognee-Instance"] = INSTANCE_ID
        return response

    try:
        state, cached = await _claim(key)
    except Exception:
        return _response(503, b'{"detail":"Cognee idempotency registry unavailable."}')

    if state == "completed":
        return _response(
            cached["response_status"],
            bytes(cached["response_body"]),
            cached["response_content_type"] or "application/json",
            cached["instance_id"],
        )
    if state == "inflight":
        response = _response(409, b'{"detail":"The guarded pipeline launch is still in flight."}')
        response.headers["Retry-After"] = "5"
        return response

    try:
        response = await call_next(request)
        body = b"".join([chunk async for chunk in response.body_iterator])
        content_type = response.headers.get("content-type", "application/json")
        await _complete(key, response.status_code, body, content_type)
        return _response(response.status_code, body, content_type, INSTANCE_ID)
    except Exception:
        # The request may already have started a process-local background task.
        # Keep the durable claim fail-closed: a retry gets 409 instead of
        # launching a duplicate. A process restart changes INSTANCE_ID and
        # safely makes the operation replayable because the old task is gone.
        raise
