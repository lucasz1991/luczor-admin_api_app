"""Luczor safety layer around the pinned Cognee FastAPI application.

Cognee 1.4 background tasks are process-local while pipeline status rows are
durable. The boot UUID proves when a previously running task can no longer
exist. A PostgreSQL idempotency registry additionally makes a lost HTTP
response replayable without launching a second background run in the same process.
"""

import asyncio
import json
import os
from contextlib import asynccontextmanager
from hashlib import sha256
from uuid import UUID, uuid4

import asyncpg
from cognee.api.client import app
from cognee.infrastructure.databases.relational import get_relational_engine
from cognee.modules.users.api_key.hash_api_key import prepare_api_key
from cognee.modules.users.methods.get_authenticated_user import get_authenticated_user
from cognee.modules.data.methods.get_authorized_existing_datasets import (
    get_authorized_existing_datasets,
)
from cognee.modules.users.models import User
from cognee.modules.users.models.UserApiKey import UserApiKey
from cognee.modules.users.permissions.methods.get_specific_user_permission_datasets import (
    get_specific_user_permission_datasets,
)
from cognee.shared.logging_utils import get_logger
from fastapi import Depends, HTTPException, Query, Request
from luczor_identities import lock_identity
from luczor_lifespan import install_luczor_lifespan
from luczor_upload_policy import cognee_stored_memory_name, install_managed_upload_policy
from sqlalchemy import select
from starlette.responses import Response


install_managed_upload_policy()


INSTANCE_ID = str(uuid4())
MAX_CACHED_RESPONSE_BYTES = 1024 * 1024
LEASE_CHECK_INTERVAL_SECONDS = 1.0
LEASE_PROBE_TIMEOUT_SECONDS = 2.0
FOREIGN_INFLIGHT_FENCE_SECONDS = 5
_pool = None
_lease_connection = None
_lease_watchdog_task = None
_pool_lock = asyncio.Lock()
_lease_check_lock = asyncio.Lock()
_add_barrier_condition = asyncio.Condition()
_active_add_operations = 0
_exclusive_add_lookup_active = False
_pending_exclusive_add_lookups = 0
logger = get_logger()
GUARDED_OPERATIONS = {
    "/api/v1/cognify": "cognify",
    "/api/v1/improve": "improve",
}
SINGLETON_LOCK_PARTS = (1280661842, 1129270853)


def _valid_idempotency_key(value):
    return len(value) == 64 and all(character in "0123456789abcdef" for character in value)


def _registry_key(operation, principal_id, idempotency_key):
    """One logical launch identity; request changes are explicit conflicts."""
    identity = f"luczor-cognee-launch\0{operation}\0{principal_id}\0{idempotency_key}"
    return sha256(identity.encode("utf-8")).hexdigest()


def _request_fingerprint(request_body):
    return sha256(request_body).hexdigest()


def _uuid_string(value):
    try:
        return str(UUID(str(value)))
    except (TypeError, ValueError):
        return None


async def _enter_add_operation():
    """Register a foreground Add while allowing unrelated Adds in parallel."""
    global _active_add_operations
    async with _add_barrier_condition:
        while _exclusive_add_lookup_active or _pending_exclusive_add_lookups > 0:
            await _add_barrier_condition.wait()
        _active_add_operations += 1


async def _leave_add_operation():
    global _active_add_operations
    async with _add_barrier_condition:
        _active_add_operations = max(0, _active_add_operations - 1)
        if _active_add_operations == 0:
            _add_barrier_condition.notify_all()


@asynccontextmanager
async def _exclusive_add_lookup():
    """Wait for every Add response, then exclude new Adds during recovery."""
    global _exclusive_add_lookup_active, _pending_exclusive_add_lookups
    acquired = False
    async with _add_barrier_condition:
        _pending_exclusive_add_lookups += 1
        try:
            while _exclusive_add_lookup_active or _active_add_operations > 0:
                await _add_barrier_condition.wait()
            _exclusive_add_lookup_active = True
            acquired = True
        finally:
            _pending_exclusive_add_lookups -= 1
            if not acquired:
                # Cancellation or another wait failure must release Adds which
                # were blocked only by this pending recovery lookup.
                _add_barrier_condition.notify_all()
    try:
        yield
    finally:
        async with _add_barrier_condition:
            _exclusive_add_lookup_active = False
            _add_barrier_condition.notify_all()


def _guarded_cache_body(operation, status_code, body):
    """Return a minimal replay body, or None for an ambiguous 2xx response."""
    if status_code == 420:
        try:
            payload = json.loads(body)
        except (TypeError, ValueError, json.JSONDecodeError):
            payload = {}
        return json.dumps(
            {"status": str(payload.get("status") or "PipelineRunErrored")},
            separators=(",", ":"),
        ).encode("utf-8")
    if not 200 <= status_code < 300:
        return body

    try:
        payload = json.loads(body)
    except (TypeError, ValueError, json.JSONDecodeError):
        return None
    if not isinstance(payload, dict):
        return None

    if operation == "improve":
        run_id = _uuid_string(payload.get("pipeline_run_id"))
        dataset_id = _uuid_string(payload.get("dataset_id"))
        if not run_id or not dataset_id:
            return None
        safe = {
            "pipeline_run_id": run_id,
            "dataset_id": dataset_id,
            "status": str(payload.get("status") or "PipelineRunStarted"),
        }
        return json.dumps(safe, separators=(",", ":")).encode("utf-8")

    safe = {}
    for dataset_key, run in payload.items():
        if not isinstance(run, dict):
            continue
        run_id = _uuid_string(run.get("pipeline_run_id"))
        dataset_id = _uuid_string(run.get("dataset_id") or dataset_key)
        if not run_id or not dataset_id:
            continue
        safe[dataset_id] = {
            "pipeline_run_id": run_id,
            "dataset_id": dataset_id,
            "status": str(run.get("status") or "PipelineRunStarted"),
        }
    return json.dumps(safe, separators=(",", ":")).encode("utf-8") if safe else None


async def _authenticate_guarded_principal(request):
    """Authenticate the same X-Api-Key principal before any cache lookup."""
    token = request.headers.get("X-Api-Key", "").strip()
    if not token:
        return None

    try:
        prepared_api_key = prepare_api_key(token)
        engine = get_relational_engine()
        async with engine.get_async_session() as session:
            user_id = (
                await session.execute(
                    select(UserApiKey.user_id).where(UserApiKey.api_key == prepared_api_key)
                )
            ).scalar_one_or_none()
            if user_id is None:
                return None
            user = (
                await session.execute(select(User).where(User.id == user_id))
            ).scalar_one_or_none()
            if user is None or not user.is_active:
                return None
            return str(user.id)
    except Exception:
        # Authentication infrastructure errors fail closed and never fall
        # through to the response registry or background operation.
        return None


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
    global _pool, _lease_connection
    if _pool is not None:
        return _pool

    async with _pool_lock:
        if _pool is not None:
            return _pool
        candidate_pool = await asyncpg.create_pool(
            host=os.environ["DB_HOST"],
            port=int(os.environ.get("DB_PORT", "5432")),
            user=os.environ["DB_USERNAME"],
            password=os.environ["DB_PASSWORD"],
            database=os.environ["DB_NAME"],
            min_size=1,
            max_size=4,
            command_timeout=5,
        )
        lease_connection = await candidate_pool.acquire()
        acquired = await lease_connection.fetchval(
            "SELECT pg_try_advisory_lock($1::integer, $2::integer)",
            *SINGLETON_LOCK_PARTS,
        )
        if not acquired:
            await candidate_pool.release(lease_connection)
            await candidate_pool.close()
            raise RuntimeError("Another Luczor Cognee wrapper instance owns the runtime lease.")

        _pool = candidate_pool
        _lease_connection = lease_connection
        async with _pool.acquire() as connection:
            await connection.execute(
                """
                CREATE TABLE IF NOT EXISTS luczor_cognify_idempotency (
                    idempotency_key CHAR(64) PRIMARY KEY,
                    instance_id UUID NOT NULL,
                    operation VARCHAR(16) NULL,
                    principal_id VARCHAR(64) NULL,
                    client_key_hash CHAR(64) NULL,
                    request_fingerprint CHAR(64) NULL,
                    state VARCHAR(16) NOT NULL,
                    response_status INTEGER NULL,
                    response_body BYTEA NULL,
                    response_content_type VARCHAR(255) NULL,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
                """
            )
            await connection.execute(
                """
                ALTER TABLE luczor_cognify_idempotency
                    ADD COLUMN IF NOT EXISTS operation VARCHAR(16) NULL,
                    ADD COLUMN IF NOT EXISTS principal_id VARCHAR(64) NULL,
                    ADD COLUMN IF NOT EXISTS client_key_hash CHAR(64) NULL,
                    ADD COLUMN IF NOT EXISTS request_fingerprint CHAR(64) NULL
                """
            )
            await connection.execute(
                """
                CREATE INDEX IF NOT EXISTS luczor_cognify_idempotency_state_instance_idx
                ON luczor_cognify_idempotency (state, instance_id)
                """
            )
            await connection.execute(
                """
                CREATE INDEX IF NOT EXISTS luczor_cognify_idempotency_state_updated_idx
                ON luczor_cognify_idempotency (state, updated_at)
                """
            )
            # A replacement waits longer than the old process watchdog before
            # reclaiming a foreign inflight row. This closes the interval where
            # a DB failover released the lease but the old process-local task
            # has not yet been killed.
            await connection.execute(
                """
                DELETE FROM luczor_cognify_idempotency
                WHERE instance_id <> $1::uuid
                  AND state = 'inflight'
                  AND updated_at < NOW() - ($2 * INTERVAL '1 second')
                """,
                INSTANCE_ID,
                FOREIGN_INFLIGHT_FENCE_SECONDS,
            )
    return _pool


async def _initialize_registry():
    """Fail application startup unless this process owns the singleton lease."""
    global _lease_watchdog_task
    await _registry_pool()
    await _assert_runtime_lease()
    _lease_watchdog_task = asyncio.create_task(_runtime_lease_watchdog())


async def _shutdown_registry():
    global _pool, _lease_connection, _lease_watchdog_task
    if _lease_watchdog_task is not None:
        _lease_watchdog_task.cancel()
        try:
            await _lease_watchdog_task
        except asyncio.CancelledError:
            pass
        _lease_watchdog_task = None
    if _pool is None:
        return
    if _lease_connection is not None:
        try:
            await _lease_connection.execute(
                "SELECT pg_advisory_unlock($1::integer, $2::integer)",
                *SINGLETON_LOCK_PARTS,
            )
        finally:
            await _pool.release(_lease_connection)
            _lease_connection = None
    await _pool.close()
    _pool = None


install_luczor_lifespan(app, _initialize_registry, _shutdown_registry)


async def _assert_runtime_lease():
    """Prove the dedicated PostgreSQL session still owns the advisory lease."""
    async with _lease_check_lock:
        if _lease_connection is None or _lease_connection.is_closed():
            raise RuntimeError("Luczor Cognee runtime lease connection is unavailable.")
        held = await asyncio.wait_for(
            _lease_connection.fetchval(
                """
                SELECT EXISTS (
                    SELECT 1
                    FROM pg_locks
                    WHERE locktype = 'advisory'
                      AND pid = pg_backend_pid()
                      AND classid = $1::oid
                      AND objid = $2::oid
                      AND granted
                )
                """,
                *SINGLETON_LOCK_PARTS,
            ),
            timeout=LEASE_PROBE_TIMEOUT_SECONDS,
        )
        if not held:
            raise RuntimeError("Luczor Cognee runtime lease was lost.")


async def _runtime_lease_watchdog():
    """Kill this process promptly if its fencing session disappears."""
    while True:
        await asyncio.sleep(LEASE_CHECK_INTERVAL_SECONDS)
        try:
            await _assert_runtime_lease()
        except Exception:
            # Cognee 1.4 background tasks are process-local. Exiting is the
            # only reliable way to prove they cannot overlap a new lease owner.
            os._exit(70)


async def _claim(key, operation, principal_id, client_key_hash, request_fingerprint):
    pool = await _registry_pool()
    async with pool.acquire() as connection, connection.transaction():
        logical_identity = lock_identity(operation, principal_id, client_key_hash)
        await connection.execute(
            "SELECT pg_advisory_xact_lock(hashtextextended($1, 0))",
            logical_identity,
        )
        rows = await connection.fetch(
            """
            SELECT idempotency_key, instance_id::text, state, response_status,
                   response_body, response_content_type, updated_at,
                   operation, principal_id, client_key_hash, request_fingerprint
            FROM luczor_cognify_idempotency
            WHERE operation = $1 AND principal_id = $2 AND client_key_hash = $3
            FOR UPDATE
            """,
            operation,
            principal_id,
            client_key_hash,
        )
        if len(rows) > 1:
            return "mismatch", None, key
        if rows:
            row = rows[0]
            if row["request_fingerprint"] != request_fingerprint:
                return "mismatch", None, row["idempotency_key"]
            actual_key = row["idempotency_key"]
            if row["state"] in {"completed", "acknowledged"}:
                return "completed", row, actual_key
            if row["instance_id"] != INSTANCE_ID:
                reclaimed = await connection.fetchval(
                    """
                    UPDATE luczor_cognify_idempotency
                    SET instance_id = $2::uuid, state = 'inflight', response_status = NULL,
                        response_body = NULL, response_content_type = NULL, updated_at = NOW()
                    WHERE idempotency_key = $1
                      AND updated_at < NOW() - ($3 * INTERVAL '1 second')
                    RETURNING TRUE
                    """,
                    actual_key,
                    INSTANCE_ID,
                    FOREIGN_INFLIGHT_FENCE_SECONDS,
                )
                return (("claimed", None, actual_key) if reclaimed else ("inflight", None, actual_key))
            return "inflight", None, actual_key

        inserted = await connection.fetchval(
            """
            INSERT INTO luczor_cognify_idempotency (
                idempotency_key, instance_id, operation, principal_id,
                client_key_hash, request_fingerprint, state
            )
            VALUES ($1, $2::uuid, $3, $4, $5, $6, 'inflight')
            ON CONFLICT (idempotency_key) DO NOTHING
            RETURNING TRUE
            """,
            key,
            INSTANCE_ID,
            operation,
            principal_id,
            client_key_hash,
            request_fingerprint,
        )
        if inserted:
            return "claimed", None, key
        return "inflight", None, key


async def _complete(key, status_code, body, content_type):
    pool = await _registry_pool()
    async with pool.acquire() as connection:
        if (200 <= status_code < 300 or status_code == 420) and len(body) <= MAX_CACHED_RESPONSE_BYTES:
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
        elif status_code in {400, 401, 403, 404, 405, 413, 415, 420, 422}:
            # Only deterministic client rejections prove that no background
            # run was accepted. Cognee 1.4's 420 response is also terminal and
            # is deleted here only if it exceeded the cache bound. Ambiguous
            # timeouts, conflicts, throttling and server failures stay in-flight
            # (fail closed) until a process restart proves the task cannot run.
            await connection.execute(
                "DELETE FROM luczor_cognify_idempotency WHERE idempotency_key = $1 AND instance_id = $2::uuid",
                key,
                INSTANCE_ID,
            )


async def _acknowledge(key, principal_id):
    pool = await _registry_pool()
    client_key_hash = sha256(key.encode("ascii")).hexdigest()
    async with pool.acquire() as connection, connection.transaction():
        rows = await connection.fetch(
            """
            SELECT idempotency_key, state
            FROM luczor_cognify_idempotency
            WHERE principal_id = $1 AND client_key_hash = $2
            FOR UPDATE
            """,
            principal_id,
            client_key_hash,
        )
        if len(rows) != 1:
            return False
        if any(row["state"] == "inflight" for row in rows):
            return False

        await connection.execute(
            """
            UPDATE luczor_cognify_idempotency
            SET state = 'acknowledged', updated_at = NOW()
            WHERE idempotency_key = $1 AND state IN ('completed', 'acknowledged')
            """,
            rows[0]["idempotency_key"],
        )
    return True


@app.get("/api/v1/luczor/pipeline-runs/{pipeline_run_id}")
async def get_exact_pipeline_run(
    pipeline_run_id: UUID,
    dataset_id: UUID = Query(...),
    user: User = Depends(get_authenticated_user),
):
    """Return the newest transition for one exact run without the 50-row feed cap."""
    await _assert_runtime_lease()
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


@app.get("/api/v1/luczor/data")
async def find_exact_luczor_data(
    dataset_name: str = Query(..., min_length=1, max_length=255),
    name: str = Query(..., pattern=r"^luczor-memory-[0-9]+-[a-f0-9]{64}\.txt$"),
    user: User = Depends(get_authenticated_user),
):
    """Recover an ambiguous Add by its non-sensitive deterministic filename."""
    await _assert_runtime_lease()
    stored_name = cognee_stored_memory_name(name)
    async with _exclusive_add_lookup():
        # The barrier is the proof boundary: every foreground /add which could
        # have created this row has returned (or its wrapper process died)
        # before the relational lookup executes.
        datasets = await get_authorized_existing_datasets([dataset_name], "read", user)
        matches = [dataset for dataset in datasets if dataset.name == dataset_name]
        if not matches:
            return {"dataset_id": None, "data_ids": []}
        if len(matches) != 1:
            raise HTTPException(status_code=409, detail="Dataset identity is ambiguous.")
        dataset = matches[0]

        from cognee.modules.data.models.Data import Data
        from cognee.modules.data.models.DatasetData import DatasetData

        engine = get_relational_engine()
        async with engine.get_async_session() as session:
            statement = (
                select(Data.id)
                .join(DatasetData, DatasetData.data_id == Data.id)
                .where(
                    DatasetData.dataset_id == dataset.id,
                    Data.owner_id == user.id,
                    # Cognee 1.4 records the validated upload filename without
                    # its final extension in Data.name.
                    Data.name == stored_name,
                )
                .order_by(Data.created_at.desc(), Data.id.desc())
            )
            data_ids = (await session.execute(statement)).scalars().all()

        return {
            "dataset_id": str(dataset.id),
            "data_ids": [str(data_id) for data_id in data_ids],
        }


@app.get("/api/v1/luczor/runtime")
async def get_luczor_runtime(user: User = Depends(get_authenticated_user)):
    """Authenticated proof that requests reach Luczor's guarded singleton."""
    await _assert_runtime_lease()
    return {
        "instance_id": INSTANCE_ID,
        "guarded_operations": ["cognify", "improve"],
    }


@app.post("/api/v1/luczor/launches/ack")
async def acknowledge_luczor_launch(
    request: Request,
    user: User = Depends(get_authenticated_user),
):
    """Minimize an accepted launch into a durable, non-expiring tombstone."""
    key = request.headers.get("X-Luczor-Idempotency-Key", "").lower()
    if not _valid_idempotency_key(key):
        raise HTTPException(status_code=422, detail="A valid idempotency key is required.")

    await _assert_runtime_lease()
    return {"acknowledged": await _acknowledge(key, str(user.id))}


@app.middleware("http")
async def luczor_runtime_guard(request, call_next):
    if request.method == "POST" and request.url.path == "/api/v1/add":
        try:
            await _assert_runtime_lease()
        except Exception:
            return _response(503, b'{"detail":"Cognee runtime lease unavailable."}')
        await _enter_add_operation()
        try:
            response = await call_next(request)
        finally:
            await _leave_add_operation()
        response.headers["X-Luczor-Cognee-Instance"] = INSTANCE_ID
        return response

    key = request.headers.get("X-Luczor-Idempotency-Key", "").lower()
    operation = GUARDED_OPERATIONS.get(request.url.path) if request.method == "POST" else None
    if operation is None:
        response = await call_next(request)
        response.headers["X-Luczor-Cognee-Instance"] = INSTANCE_ID
        return response
    if not _valid_idempotency_key(key):
        return _response(
            422,
            b'{"detail":"A valid X-Luczor-Idempotency-Key is required for this background operation."}',
        )

    try:
        await _assert_runtime_lease()
    except Exception:
        return _response(503, b'{"detail":"Cognee runtime lease unavailable."}')

    principal_id = await _authenticate_guarded_principal(request)
    if principal_id is None:
        return _response(401, b'{"detail":"Authenticated Cognee API key required."}')
    request_fingerprint = _request_fingerprint(await request.body())
    client_key_hash = sha256(key.encode("ascii")).hexdigest()
    registry_key = _registry_key(operation, principal_id, key)

    try:
        state, cached, registry_key = await _claim(
            registry_key,
            operation,
            principal_id,
            client_key_hash,
            request_fingerprint,
        )
    except Exception as error:
        # Never log request bodies, credentials or idempotency keys. The class
        # and SQLSTATE are enough to diagnose a fail-closed registry outage.
        logger.error(
            "Cognee idempotency registry claim failed",
            error_type=type(error).__name__,
            sqlstate=getattr(error, "sqlstate", None),
        )
        return _response(503, b'{"detail":"Cognee idempotency registry unavailable."}')

    if state == "mismatch":
        return _response(409, b'{"detail":"The idempotency key belongs to a different request."}')
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
        cache_body = _guarded_cache_body(operation, response.status_code, body)
        if 200 <= response.status_code < 300 and cache_body is None:
            # The launch may have started, but without exact run identifiers it
            # cannot be polled safely. Keep the claim inflight for this boot;
            # only a later singleton restart proves the task is gone.
            return _response(
                502,
                b'{"detail":"Cognee returned an invalid guarded launch acceptance."}',
                launch_instance_id=INSTANCE_ID,
            )
        await _complete(
            registry_key,
            response.status_code,
            cache_body if cache_body is not None else body,
            "application/json" if cache_body is not None else content_type,
        )
        return _response(response.status_code, body, content_type, INSTANCE_ID)
    except Exception:
        # The request may already have started a process-local background task.
        # Keep the durable claim fail-closed: a retry gets 409 instead of
        # launching a duplicate. A process restart changes INSTANCE_ID and
        # safely makes the operation replayable because the old task is gone.
        raise
