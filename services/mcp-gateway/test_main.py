import asyncio
from unittest.mock import patch

import httpx
from httpx import ASGITransport, AsyncClient as AsgiClient

import main


def request(method: str, path: str, **kwargs) -> httpx.Response:
    async def send() -> httpx.Response:
        transport = ASGITransport(app=main.app)
        async with AsgiClient(transport=transport, base_url="http://sidecar.test") as client:
            return await client.request(method, path, **kwargs)

    return asyncio.run(send())


def test_health_and_tool_catalog_are_read_only() -> None:
    assert request("GET", "/health").json() == {"status": "ok"}

    response = request("GET", "/v1/tools")

    assert response.status_code == 200
    assert all("server" in tool and "risk" in tool for tool in response.json()["data"])


def test_validate_rejects_unknown_tools() -> None:
    response = request("POST", "/v1/validate", json={"server": "unknown", "tool": "shell", "input": {}})

    assert response.status_code == 422
    assert response.json()["detail"] == "Unknown MCP tool"


def test_call_requires_api_authentication_before_forwarding() -> None:
    response = request("POST", "/v1/call", json={"server": "tests", "tool": "run_suite", "input": {}})

    assert response.status_code == 401


def test_call_forwards_the_original_credential_to_laravel() -> None:
    captured: dict = {}

    class FakeAsyncClient:
        def __init__(self, **kwargs):
            captured["client"] = kwargs

        async def __aenter__(self):
            return self

        async def __aexit__(self, *args):
            return None

        async def post(self, url, *, json, headers):
            captured.update({"url": url, "json": json, "headers": headers})
            request = httpx.Request("POST", url)
            return httpx.Response(200, json={"ok": True}, request=request)

    with patch.object(main.httpx, "AsyncClient", FakeAsyncClient):
        response = request(
            "POST",
            "/v1/call",
            json={"server": "tests", "tool": "run_suite", "input": {"scope": "unit"}},
            headers={"X-Api-Key": "device-test-key"},
        )

    assert response.status_code == 200
    assert captured["headers"]["X-Api-Key"] == "device-test-key"
    assert captured["url"].endswith("/api/v1/mcp/call")
    assert captured["json"]["input"] == {"scope": "unit"}
