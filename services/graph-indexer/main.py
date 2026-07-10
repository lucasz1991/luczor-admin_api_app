import hashlib
import json
import os
from datetime import datetime, timezone
from typing import Any

from fastapi import FastAPI
from pydantic import BaseModel, Field
import redis.asyncio as redis
import httpx

app = FastAPI(title="Luczor Graph Indexer", version="1.0")
cache = redis.from_url(os.environ["REDIS_URL"], password=os.getenv("REDIS_PASSWORD"), decode_responses=True)
QDRANT_COLLECTION = "luczor_graph_snapshots"


class IndexRequest(BaseModel):
    user_id: int
    repo_id: str = Field(min_length=1, max_length=190)
    branch: str = Field(default="main", max_length=160)
    commit_sha: str = Field(min_length=1, max_length=80)
    changed_files: list[str] = Field(default_factory=list, max_length=1000)
    symbols: dict[str, list[str]] = Field(default_factory=dict)


class ImpactRequest(BaseModel):
    user_id: int | None = None
    repo_id: str | None = None
    branch: str | None = None
    commit_sha: str | None = None
    changed_files: list[str] = Field(default_factory=list)
    code: list[dict[str, Any]] = Field(default_factory=list)
    code_limit: int = Field(default=12, ge=1, le=30)


def cache_key(user_id: int | None, repo_id: str | None, branch: str | None) -> str:
    return f"graph:{user_id or 'none'}:{repo_id or 'none'}:{branch or 'none'}"


def qdrant_url() -> str:
    return os.getenv("QDRANT_URL", "").rstrip("/")


def qdrant_headers() -> dict[str, str]:
    api_key = os.getenv("QDRANT_API_KEY", "")
    return {"api-key": api_key} if api_key else {}


def snapshot_point_id(snapshot_hash: str) -> int:
    # Qdrant numeric point IDs are unsigned 64-bit. Keep the first 60 bits so
    # PHP, JavaScript, and database clients can also represent the value safely.
    return int(snapshot_hash[:15], 16)


def snapshot_vector(snapshot: dict[str, Any]) -> list[float]:
    """Build a stable local vector without sending repository metadata to a provider."""
    terms = [
        snapshot["repo_id"], snapshot["branch"], snapshot["commit_sha"],
        *snapshot.get("changed_files", []), *snapshot.get("symbols", {}).keys(),
    ]
    values = [0.0] * 32
    for term in terms:
        digest = hashlib.sha256(str(term).encode("utf-8")).digest()
        for index, byte in enumerate(digest):
            values[index % len(values)] += (byte / 127.5) - 1.0
    magnitude = sum(value * value for value in values) ** 0.5 or 1.0
    return [value / magnitude for value in values]


async def ensure_qdrant_collection(client: httpx.AsyncClient) -> None:
    response = await client.put(
        f"{qdrant_url()}/collections/{QDRANT_COLLECTION}",
        headers=qdrant_headers(),
        json={"vectors": {"size": 32, "distance": "Cosine"}},
    )
    if response.status_code not in (200, 409):
        response.raise_for_status()


async def store_qdrant_snapshot(snapshot: dict[str, Any]) -> None:
    if not qdrant_url():
        return
    async with httpx.AsyncClient(timeout=5.0) as client:
        await ensure_qdrant_collection(client)
        response = await client.put(
            f"{qdrant_url()}/collections/{QDRANT_COLLECTION}/points",
            headers=qdrant_headers(),
            json={
                "points": [{
                    "id": snapshot_point_id(snapshot["snapshot_hash"]),
                    "vector": snapshot_vector(snapshot),
                    "payload": snapshot,
                }],
            },
        )
        response.raise_for_status()


async def load_qdrant_snapshot(request: ImpactRequest) -> dict[str, Any]:
    """Read only the caller's repository/branch snapshot from Qdrant."""
    if not qdrant_url() or request.user_id is None or not request.repo_id or not request.branch:
        return {}

    must: list[dict[str, Any]] = [
        {"key": "user_id", "match": {"value": request.user_id}},
        {"key": "repo_id", "match": {"value": request.repo_id}},
        {"key": "branch", "match": {"value": request.branch}},
    ]
    if request.commit_sha:
        must.append({"key": "commit_sha", "match": {"value": request.commit_sha}})

    async with httpx.AsyncClient(timeout=5.0) as client:
        response = await client.post(
            f"{qdrant_url()}/collections/{QDRANT_COLLECTION}/points/scroll",
            headers=qdrant_headers(),
            json={"filter": {"must": must}, "limit": 1, "with_payload": True, "with_vector": False},
        )
        response.raise_for_status()
        points = response.json().get("result", {}).get("points", [])
        if not points:
            return {}
        payload = points[0].get("payload", {})
        return payload if isinstance(payload, dict) else {}


@app.get("/health")
async def health() -> dict[str, str]:
    await cache.ping()
    if qdrant_url():
        async with httpx.AsyncClient(timeout=3.0) as client:
            response = await client.get(f"{qdrant_url()}/healthz", headers=qdrant_headers())
            response.raise_for_status()
    return {"status": "ok"}


@app.post("/api/v1/index")
async def index(request: IndexRequest) -> dict[str, Any]:
    snapshot = {
        "user_id": request.user_id,
        "repo_id": request.repo_id,
        "branch": request.branch,
        "commit_sha": request.commit_sha,
        "changed_files": sorted(set(request.changed_files)),
        "symbols": request.symbols,
    }
    snapshot["snapshot_hash"] = hashlib.sha256(json.dumps(snapshot, sort_keys=True).encode()).hexdigest()
    snapshot["indexed_at"] = datetime.now(timezone.utc).isoformat()
    await cache.set(cache_key(request.user_id, request.repo_id, request.branch), json.dumps(snapshot), ex=60 * 60 * 24 * 7)
    qdrant_status = "disabled"
    if qdrant_url():
        await store_qdrant_snapshot(snapshot)
        qdrant_status = "indexed"
    return {"data": snapshot, "source_status": {"cache": "indexed", "qdrant": qdrant_status}}


@app.post("/api/v1/impact")
async def impact(request: ImpactRequest) -> dict[str, Any]:
    raw = await cache.get(cache_key(request.user_id, request.repo_id, request.branch))
    snapshot = json.loads(raw) if raw else {}
    qdrant_snapshot: dict[str, Any] = {}
    qdrant_status = "disabled"
    if qdrant_url():
        qdrant_snapshot = await load_qdrant_snapshot(request)
        qdrant_status = "retrieved" if qdrant_snapshot else "empty"
    if qdrant_snapshot:
        snapshot = qdrant_snapshot
    changed = list(dict.fromkeys(request.changed_files + snapshot.get("changed_files", [])))
    provided = {str(item.get("path")): item for item in request.code if item.get("path")}
    candidates: list[dict[str, Any]] = []
    for path in changed:
        item = provided.get(path, {})
        candidates.append({
            "path": path,
            "reason": "git_changed" if path in request.changed_files else "graph_snapshot",
            "score": 0.95 if path in request.changed_files else 0.72,
            "tokens": int(item.get("tokens", 120)),
        })
    candidates.extend(item for path, item in provided.items() if path not in changed)
    candidates.sort(key=lambda item: float(item.get("score", 0)), reverse=True)
    return {
        "code": candidates[: request.code_limit],
        "source_status": {
            "graph": "indexed" if snapshot else "empty",
            "cache": "hit" if raw else "miss",
            "qdrant": qdrant_status,
            "snapshot_hash": snapshot.get("snapshot_hash"),
            "commit_sha": snapshot.get("commit_sha"),
        },
    }
