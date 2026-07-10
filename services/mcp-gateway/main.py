import os

import httpx
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

app = FastAPI(title="Luczor MCP Gateway", version="1.0")

TOOLS = [
    {"server": "github", "tool": "repositories", "scopes": ["repo:read"], "risk": "low"},
    {"server": "github", "tool": "branches", "scopes": ["repo:write"], "risk": "normal"},
    {"server": "github", "tool": "pull_requests", "scopes": ["repo:write"], "risk": "normal"},
    {"server": "repository", "tool": "files", "scopes": ["project:read"], "risk": "low"},
    {"server": "repository", "tool": "git_diff", "scopes": ["project:read"], "risk": "low"},
    {"server": "browser", "tool": "navigate", "scopes": ["device:browser"], "risk": "normal"},
    {"server": "database", "tool": "query_readonly", "scopes": ["project:db:read"], "risk": "sensitive"},
    {"server": "ssh", "tool": "run_profile", "scopes": ["server:profile"], "risk": "critical"},
    {"server": "desktop", "tool": "device_job", "scopes": ["device:control"], "risk": "critical"},
    {"server": "tests", "tool": "run_suite", "scopes": ["project:tests"], "risk": "normal"},
]


class Call(BaseModel):
    server: str
    tool: str
    input: dict = Field(default_factory=dict)


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.get("/v1/tools")
def tools() -> dict[str, list[dict]]:
    return {"data": TOOLS}


@app.post("/v1/validate")
def validate(call: Call) -> dict:
    descriptor = next((item for item in TOOLS if item["server"] == call.server and item["tool"] == call.tool), None)
    if not descriptor:
        raise HTTPException(422, "Unknown MCP tool")
    return {"data": descriptor, "execution": "delegated_to_laravel_policy_layer"}


@app.post("/v1/call")
async def call(
    request: Call,
    x_api_key: str | None = Header(default=None),
    authorization: str | None = Header(default=None),
) -> dict:
    """Forward an MCP call without ever becoming an authority or executor.

    Laravel checks the original API key, owner/project/device scope, policy,
    approval and audit trail. The gateway deliberately accepts no service key
    and cannot execute binaries, SQL or SSH itself.
    """
    if not x_api_key and not authorization:
        raise HTTPException(401, "An API key is required for MCP calls")

    headers: dict[str, str] = {"Accept": "application/json"}
    if x_api_key:
        headers["X-Api-Key"] = x_api_key
    if authorization:
        headers["Authorization"] = authorization

    base_url = os.getenv("LUCZOR_API_URL", "http://nginx").rstrip("/")
    try:
        async with httpx.AsyncClient(timeout=30.0) as client:
            response = await client.post(f"{base_url}/api/v1/mcp/call", json=request.model_dump(), headers=headers)
    except httpx.HTTPError as error:
        raise HTTPException(503, "Luczor policy API is unreachable") from error

    try:
        body = response.json()
    except ValueError:
        body = {"message": "Luczor policy API returned an invalid response"}
    if response.is_error:
        raise HTTPException(response.status_code, body.get("message", "MCP call rejected"))
    return body
