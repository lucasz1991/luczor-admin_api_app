"""A deliberately small, deterministic OpenAI-compatible test provider.

Only the two endpoints exercised by Cognee 1.4 are implemented. Unknown
routes, missing authentication, oversized bodies, and malformed JSON fail
closed. The process never opens an outbound connection.
"""

from __future__ import annotations

import hashlib
import json
import math
import os
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any


MAX_BODY_BYTES = 1024 * 1024
DEFAULT_DIMENSIONS = 32
_LOCK = threading.Lock()
_COUNTERS = {
    "chat_completions": 0,
    "embeddings": 0,
    "auth_failures": 0,
    "invalid_requests": 0,
    "unknown_routes": 0,
}


def reset_counters() -> None:
    with _LOCK:
        for key in _COUNTERS:
            _COUNTERS[key] = 0


def counter_snapshot() -> dict[str, int]:
    with _LOCK:
        return dict(_COUNTERS)


def _increment(name: str) -> None:
    with _LOCK:
        _COUNTERS[name] += 1


def deterministic_embedding(value: Any, dimensions: int = DEFAULT_DIMENSIONS) -> list[float]:
    """Return a stable, normalized vector without model or network access."""
    if dimensions < 1 or dimensions > 4096:
        raise ValueError("embedding dimensions must be between 1 and 4096")
    canonical = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    seed = hashlib.sha256(canonical.encode("utf-8")).digest()
    values = []
    for index in range(dimensions):
        digest = hashlib.sha256(seed + index.to_bytes(4, "big")).digest()
        integer = int.from_bytes(digest[:8], "big")
        values.append((integer / ((1 << 64) - 1)) * 2.0 - 1.0)
    norm = math.sqrt(sum(item * item for item in values)) or 1.0
    return [round(item / norm, 10) for item in values]


def _resolve_ref(schema: dict[str, Any], root: dict[str, Any]) -> dict[str, Any]:
    reference = schema.get("$ref")
    if not isinstance(reference, str) or not reference.startswith("#/"):
        return schema
    current: Any = root
    for part in reference[2:].split("/"):
        part = part.replace("~1", "/").replace("~0", "~")
        if not isinstance(current, dict) or part not in current:
            return schema
        current = current[part]
    return current if isinstance(current, dict) else schema


def schema_example(
    schema: dict[str, Any],
    root: dict[str, Any] | None = None,
    *,
    depth: int = 0,
) -> Any:
    """Build a minimal value conforming to the JSON schema sent by Instructor."""
    root = root or schema
    if depth > 12:
        return None
    schema = _resolve_ref(schema, root)
    if "const" in schema:
        return schema["const"]
    enum = schema.get("enum")
    if isinstance(enum, list) and enum:
        return enum[0]
    for union_key in ("anyOf", "oneOf"):
        alternatives = schema.get(union_key)
        if isinstance(alternatives, list):
            non_null = [
                item
                for item in alternatives
                if isinstance(item, dict) and item.get("type") != "null"
            ]
            if non_null:
                return schema_example(non_null[0], root, depth=depth + 1)
            return None

    schema_type = schema.get("type")
    if isinstance(schema_type, list):
        schema_type = next((item for item in schema_type if item != "null"), "null")
    if schema_type == "object" or isinstance(schema.get("properties"), dict):
        properties = schema.get("properties") or {}
        required = schema.get("required") or list(properties)
        return {
            name: schema_example(properties[name], root, depth=depth + 1)
            for name in required
            if name in properties and isinstance(properties[name], dict)
        }
    if schema_type == "array":
        # Empty collections are valid for Cognee extraction DTOs and avoid
        # fabricating graph facts while still exercising every real pipeline.
        return []
    if schema_type == "boolean":
        return False
    if schema_type == "integer":
        return max(0, int(schema.get("minimum", 0)))
    if schema_type == "number":
        return float(schema.get("minimum", 0.0))
    if schema_type == "null":
        return None
    return "Luczor deterministic local E2E value"


def _json_candidates(text: str) -> list[Any]:
    decoder = json.JSONDecoder()
    candidates = []
    for index, character in enumerate(text):
        if character != "{":
            continue
        try:
            candidate, _ = decoder.raw_decode(text[index:])
        except json.JSONDecodeError:
            continue
        candidates.append(candidate)
    return candidates


def extract_schema(payload: dict[str, Any]) -> dict[str, Any] | None:
    response_format = payload.get("response_format")
    if isinstance(response_format, dict):
        json_schema = response_format.get("json_schema")
        if isinstance(json_schema, dict) and isinstance(json_schema.get("schema"), dict):
            return json_schema["schema"]

    tools = payload.get("tools")
    if isinstance(tools, list) and tools:
        function = tools[0].get("function") if isinstance(tools[0], dict) else None
        if isinstance(function, dict) and isinstance(function.get("parameters"), dict):
            return function["parameters"]

    messages = payload.get("messages")
    if not isinstance(messages, list):
        return None
    for message in reversed(messages):
        content = message.get("content") if isinstance(message, dict) else None
        if not isinstance(content, str):
            continue
        # Candidates are emitted outermost-first for each opening brace. The
        # first schema is therefore the complete Instructor contract, not a
        # nested property such as ``{"type":"array"}``.
        for candidate in _json_candidates(content):
            if isinstance(candidate, dict) and (
                "properties" in candidate or "$defs" in candidate or "type" in candidate
            ):
                return candidate
    return None


def chat_completion(payload: dict[str, Any]) -> dict[str, Any]:
    schema = extract_schema(payload)
    content_value: Any = (
        schema_example(schema)
        if schema is not None
        else {"result": "Luczor deterministic local E2E value"}
    )
    content = json.dumps(content_value, ensure_ascii=False, separators=(",", ":"))
    return {
        "id": "chatcmpl-luczor-e2e",
        "object": "chat.completion",
        "created": 0,
        "model": str(payload.get("model") or "openai/luczor-e2e-chat"),
        "choices": [
            {
                "index": 0,
                "message": {"role": "assistant", "content": content},
                "finish_reason": "stop",
            }
        ],
        "usage": {"prompt_tokens": 1, "completion_tokens": 1, "total_tokens": 2},
    }


def embedding_response(payload: dict[str, Any], dimensions: int) -> dict[str, Any]:
    raw_input = payload.get("input", [])
    inputs = raw_input if isinstance(raw_input, list) else [raw_input]
    # A single token-array represents one input; a list of strings represents
    # a batch. This mirrors OpenAI's two accepted embedding input shapes.
    if inputs and all(isinstance(item, int) for item in inputs):
        inputs = [inputs]
    return {
        "object": "list",
        "data": [
            {
                "object": "embedding",
                "index": index,
                "embedding": deterministic_embedding(value, dimensions),
            }
            for index, value in enumerate(inputs)
        ],
        "model": str(payload.get("model") or "luczor-e2e-embedding"),
        "usage": {"prompt_tokens": len(inputs), "total_tokens": len(inputs)},
    }


class FakeOpenAIHandler(BaseHTTPRequestHandler):
    server_version = "LuczorFakeOpenAI/1.0"

    def log_message(self, _format: str, *_args: Any) -> None:
        # Request bodies and Authorization headers must never reach logs.
        return

    def _write_json(self, status: int, payload: Any) -> None:
        body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:  # noqa: N802 - BaseHTTPRequestHandler contract
        if self.path == "/health":
            self._write_json(200, {"status": "ok"})
            return
        if self.path == "/stats":
            self._write_json(200, counter_snapshot())
            return
        _increment("unknown_routes")
        self._write_json(404, {"error": "unsupported route"})

    def do_POST(self) -> None:  # noqa: N802 - BaseHTTPRequestHandler contract
        expected = os.environ.get("LUCZOR_E2E_PROVIDER_API_KEY", "")
        if not expected or self.headers.get("Authorization") != f"Bearer {expected}":
            _increment("auth_failures")
            self._write_json(401, {"error": "invalid test credential"})
            return

        raw_length = self.headers.get("Content-Length", "")
        try:
            length = int(raw_length)
        except ValueError:
            length = -1
        if length < 0 or length > MAX_BODY_BYTES:
            _increment("invalid_requests")
            self._write_json(413, {"error": "invalid request size"})
            return
        try:
            payload = json.loads(self.rfile.read(length))
        except (json.JSONDecodeError, UnicodeDecodeError):
            _increment("invalid_requests")
            self._write_json(400, {"error": "invalid json"})
            return
        if not isinstance(payload, dict):
            _increment("invalid_requests")
            self._write_json(400, {"error": "json object required"})
            return

        if self.path == "/v1/chat/completions":
            _increment("chat_completions")
            self._write_json(200, chat_completion(payload))
            return
        if self.path == "/v1/embeddings":
            dimensions = int(os.environ.get("LUCZOR_E2E_EMBEDDING_DIMENSIONS", DEFAULT_DIMENSIONS))
            _increment("embeddings")
            self._write_json(200, embedding_response(payload, dimensions))
            return

        _increment("unknown_routes")
        self._write_json(404, {"error": "unsupported route"})


def main() -> None:
    if not os.environ.get("LUCZOR_E2E_PROVIDER_API_KEY"):
        raise SystemExit("LUCZOR_E2E_PROVIDER_API_KEY is required")
    server = ThreadingHTTPServer(("0.0.0.0", 8080), FakeOpenAIHandler)
    server.serve_forever()


if __name__ == "__main__":
    main()
