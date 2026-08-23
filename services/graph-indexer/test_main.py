import asyncio
import os
from unittest.mock import AsyncMock

import httpx

os.environ.setdefault("REDIS_URL", "redis://127.0.0.1:6379/1")
os.environ.setdefault("GRAPHIFY_API_KEY", "test-internal-key")
os.environ.pop("QDRANT_URL", None)

import main  # noqa: E402  Environment must be configured before app import.


def request(method: str, path: str, **kwargs) -> httpx.Response:
    async def send() -> httpx.Response:
        transport = httpx.ASGITransport(app=main.app)
        async with httpx.AsyncClient(transport=transport, base_url="http://sidecar.test") as client:
            return await client.request(method, path, **kwargs)

    return asyncio.run(send())


def fake_cache() -> AsyncMock:
    fake_cache = AsyncMock()
    main.cache = fake_cache
    return fake_cache


def test_health_checks_the_cache_without_exposing_details() -> None:
    cache = fake_cache()

    response = request("GET", "/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok"}
    cache.ping.assert_awaited_once()


def test_index_requires_the_internal_service_credential() -> None:
    fake_cache()
    payload = {
        "user_id": 7,
        "repo_id": "luczor",
        "commit_sha": "a" * 40,
    }

    assert request("POST", "/api/v1/index", json=payload).status_code == 401
    assert request(
        "POST",
        "/api/v1/index",
        json=payload,
        headers={"X-Luczor-Internal-Key": "wrong"},
    ).status_code == 401


def test_index_is_deterministic_and_bounded() -> None:
    cache = fake_cache()
    payload = {
        "user_id": 7,
        "repo_id": "luczor",
        "branch": "main",
        "commit_sha": "a" * 40,
        "changed_files": ["src/App.vue", "src/App.vue", "README.md"],
        "symbols": {"src/App.vue": ["send"]},
    }

    response = request(
        "POST",
        "/api/v1/index",
        json=payload,
        headers={"X-Luczor-Internal-Key": "test-internal-key"},
    )

    assert response.status_code == 200
    snapshot = response.json()["data"]
    assert snapshot["changed_files"] == ["README.md", "src/App.vue"]
    assert len(snapshot["snapshot_hash"]) == 64
    cache.set.assert_awaited_once()


def test_index_rejects_oversized_changed_file_lists() -> None:
    fake_cache()

    response = request(
        "POST",
        "/api/v1/index",
        json={
            "user_id": 7,
            "repo_id": "luczor",
            "commit_sha": "a" * 40,
            "changed_files": [f"file-{index}" for index in range(1001)],
        },
        headers={"X-Luczor-Internal-Key": "test-internal-key"},
    )

    assert response.status_code == 422
