"""Exercise the real Luczor Cognee HTTP flow against disposable services."""

from __future__ import annotations

import hashlib
import json
import os
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from typing import Any


BASE_URL = "http://cognee:8000"
FAKE_URL = "http://fake-openai:8080"
DATASET = "luczor-e2e-memory"
MEMORY_TEXT = "Luczor acceptance fact: the deterministic codename is ORBITAL-CEDAR-9274."
QUERY = "ORBITAL-CEDAR-9274"
TIMEOUT_SECONDS = 240


def _api_key() -> str:
    value = os.environ.get("LUCZOR_E2E_API_KEY", "")
    if len(value) < 32:
        raise RuntimeError("LUCZOR_E2E_API_KEY is missing")
    return value


def _request(
    method: str,
    url: str,
    *,
    body: bytes | None = None,
    headers: dict[str, str] | None = None,
    expected: tuple[int, ...] = (200,),
    timeout: int = 15,
) -> tuple[int, Any]:
    request = urllib.request.Request(url, data=body, method=method)
    for name, value in (headers or {}).items():
        request.add_header(name, value)
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            status = response.status
            raw = response.read()
    except urllib.error.HTTPError as error:
        status = error.code
        raw = error.read()
    if status not in expected:
        # Bodies contain only synthetic E2E data. Bound diagnostics so a
        # malformed server cannot flood CI logs.
        detail = raw.decode("utf-8", "replace")[:1000]
        raise RuntimeError(f"{method} {url} returned {status}: {detail}")
    if not raw:
        return status, None
    try:
        return status, json.loads(raw)
    except json.JSONDecodeError as error:
        raise RuntimeError(f"{method} {url} returned non-JSON") from error


def _json_request(
    method: str,
    path: str,
    payload: dict[str, Any] | None = None,
    *,
    extra_headers: dict[str, str] | None = None,
    expected: tuple[int, ...] = (200,),
) -> tuple[int, Any]:
    headers = {
        "Accept": "application/json",
        "Authorization": f"Bearer {_api_key()}",
        "X-Api-Key": _api_key(),
    }
    body = None
    if payload is not None:
        body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        headers["Content-Type"] = "application/json"
    headers.update(extra_headers or {})
    return _request(method, f"{BASE_URL}{path}", body=body, headers=headers, expected=expected)


def _multipart_add(filename: str) -> Any:
    boundary = "----luczor-cognee-e2e-boundary"
    parts = [
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"datasetName\"\r\n\r\n{DATASET}\r\n",
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"run_in_background\"\r\n\r\nfalse\r\n",
        (
            f"--{boundary}\r\n"
            f"Content-Disposition: form-data; name=\"data\"; filename=\"{filename}\"\r\n"
            "Content-Type: text/plain; charset=utf-8\r\n\r\n"
            f"{MEMORY_TEXT}\r\n"
        ),
        f"--{boundary}--\r\n",
    ]
    body = "".join(parts).encode("utf-8")
    headers = {
        "Accept": "application/json",
        "Authorization": f"Bearer {_api_key()}",
        "X-Api-Key": _api_key(),
        "Content-Type": f"multipart/form-data; boundary={boundary}",
    }
    _, payload = _request(
        "POST",
        f"{BASE_URL}/api/v1/add",
        body=body,
        headers=headers,
        timeout=90,
    )
    return payload


def _wait_for_service(url: str, timeout: int = 90) -> None:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            _request("GET", url, expected=(200,), timeout=3)
            return
        except (OSError, RuntimeError):
            time.sleep(1)
    raise RuntimeError(f"service did not become ready: {url}")


def _is_uuid(value: Any) -> bool:
    try:
        uuid.UUID(str(value))
        return True
    except (ValueError, TypeError, AttributeError):
        return False


def _pipeline_status(value: Any) -> str:
    return str(value or "").strip().lower()


def _safe_identity_summary(items: Any) -> list[dict[str, str]]:
    """Return only non-sensitive IDs/names for bounded E2E diagnostics."""

    if not isinstance(items, list):
        return []
    return [
        {
            "id": str(item.get("id", "")),
            "name": str(item.get("name", "")),
            "dataset_id": str(item.get("dataset_id", "")),
        }
        for item in items[:5]
        if isinstance(item, dict)
    ]


def main() -> None:
    _wait_for_service(f"{BASE_URL}/openapi.json")
    _wait_for_service(f"{FAKE_URL}/health")

    content_hash = hashlib.sha256(MEMORY_TEXT.encode("utf-8")).hexdigest()
    filename = f"luczor-memory-9001-{content_hash}.txt"
    add_response = _multipart_add(filename)
    if not isinstance(add_response, dict):
        raise RuntimeError("add did not return an object")

    _, datasets = _json_request("GET", "/api/v1/datasets")
    matching_datasets = [
        item
        for item in datasets
        if isinstance(item, dict) and item.get("name") == DATASET
    ] if isinstance(datasets, list) else []
    if len(matching_datasets) != 1:
        raise RuntimeError(
            "dataset list did not resolve one exact dataset: "
            f"{_safe_identity_summary(datasets)}"
        )
    dataset_id = matching_datasets[0].get("id")
    if not _is_uuid(dataset_id):
        raise RuntimeError("dataset list returned an invalid dataset UUID")

    _, dataset_data = _json_request("GET", f"/api/v1/datasets/{dataset_id}/data")
    if not isinstance(dataset_data, list) or len(dataset_data) != 1:
        raise RuntimeError(
            "dataset data did not contain one uploaded item: "
            f"{_safe_identity_summary(dataset_data)}"
        )
    stored_item = dataset_data[0]
    if not isinstance(stored_item, dict) or stored_item.get("name") != filename[:-4]:
        raise RuntimeError(
            "stored upload name differs from Cognee 1.4's deterministic filename stem: "
            f"{_safe_identity_summary(dataset_data)}"
        )
    data_id = stored_item.get("id")
    if not _is_uuid(data_id):
        raise RuntimeError("dataset data returned an invalid data UUID")

    lookup_query = urllib.parse.urlencode({"dataset_name": DATASET, "name": filename})
    _, lookup = _json_request("GET", f"/api/v1/luczor/data?{lookup_query}")
    lookup_dataset_id = lookup.get("dataset_id") if isinstance(lookup, dict) else None
    data_ids = lookup.get("data_ids") if isinstance(lookup, dict) else None
    if (
        str(lookup_dataset_id) != str(dataset_id)
        or not isinstance(data_ids, list)
        or [str(value) for value in data_ids] != [str(data_id)]
    ):
        raise RuntimeError(
            "exact data lookup disagrees with Cognee dataset data: "
            f"dataset_id={lookup_dataset_id!s}, data_ids={data_ids!r}, "
            f"expected_dataset_id={dataset_id!s}, expected_data_id={data_id!s}"
        )

    _, provider_baseline = _request("GET", f"{FAKE_URL}/stats")
    if not isinstance(provider_baseline, dict):
        raise RuntimeError("fake provider baseline stats are invalid")

    idempotency_key = hashlib.sha256(f"cognify:{dataset_id}:{content_hash}".encode()).hexdigest()
    _, launch = _json_request(
        "POST",
        "/api/v1/cognify",
        {"datasets": [DATASET], "run_in_background": True},
        extra_headers={"X-Luczor-Idempotency-Key": idempotency_key},
    )
    run = launch.get(str(dataset_id)) if isinstance(launch, dict) else None
    if not isinstance(run, dict) and isinstance(launch, dict) and len(launch) == 1:
        run = next(iter(launch.values()))
    run_id = run.get("pipeline_run_id") if isinstance(run, dict) else None
    if not _is_uuid(run_id):
        raise RuntimeError("background cognify did not return a pipeline run UUID")

    deadline = time.monotonic() + TIMEOUT_SECONDS
    final_status = ""
    while time.monotonic() < deadline:
        query = urllib.parse.urlencode({"dataset_id": dataset_id})
        _, status_payload = _json_request(
            "GET", f"/api/v1/luczor/pipeline-runs/{run_id}?{query}"
        )
        final_status = _pipeline_status(status_payload.get("status"))
        if "completed" in final_status:
            break
        if "failed" in final_status or "error" in final_status:
            raise RuntimeError(f"cognify failed with status {final_status}")
        time.sleep(1)
    else:
        raise RuntimeError(f"cognify did not complete within {TIMEOUT_SECONDS} seconds")

    status_query = urllib.parse.urlencode(
        [("dataset", dataset_id), ("pipeline", "add_pipeline"), ("pipeline", "cognify_pipeline")]
    )
    _, dataset_status = _json_request("GET", f"/api/v1/datasets/status?{status_query}")
    if not isinstance(dataset_status, dict) or str(dataset_id) not in dataset_status:
        raise RuntimeError("dataset status did not include the exact dataset")

    _, search_result = _json_request(
        "POST",
        "/api/v1/search",
        {
            "datasets": [DATASET],
            "query": QUERY,
            "search_type": "CHUNKS",
            "top_k": 5,
            "only_context": True,
        },
    )
    if not isinstance(search_result, list) or not search_result:
        raise RuntimeError("chunk search returned no result")
    if QUERY.lower() not in json.dumps(search_result, ensure_ascii=False).lower():
        raise RuntimeError("chunk search did not return the uploaded deterministic fact")

    _, forget_result = _json_request(
        "POST",
        "/api/v1/forget",
        {"dataset": DATASET, "data_id": data_id, "memory_only": False},
    )
    if not isinstance(forget_result, dict) or forget_result.get("status") != "success":
        raise RuntimeError("forget did not return success")
    if str(forget_result.get("data_id")) != str(data_id):
        raise RuntimeError("forget confirmed a different data UUID")

    _, lookup_after = _json_request("GET", f"/api/v1/luczor/data?{lookup_query}")
    if lookup_after.get("data_ids") != []:
        raise RuntimeError("forgotten data is still present in the relational lookup")

    _, fake_stats = _request("GET", f"{FAKE_URL}/stats")
    required_counts = ("chat_completions", "embeddings")
    if any(
        int(fake_stats.get(name, 0)) <= int(provider_baseline.get(name, 0))
        for name in required_counts
    ):
        raise RuntimeError(
            "background cognify did not exercise both fake provider endpoints: "
            f"before={provider_baseline}, after={fake_stats}"
        )
    for forbidden_count in ("auth_failures", "invalid_requests", "unknown_routes"):
        if int(fake_stats.get(forbidden_count, 0)) != 0:
            raise RuntimeError(f"fake provider recorded a contract violation: {fake_stats}")

    print(
        json.dumps(
            {
                "status": "passed",
                "flow": ["add", "background_cognify", "status", "search", "forget"],
                "dataset_id_valid": True,
                "data_id_valid": True,
                "pipeline_status": final_status,
                "provider_requests": fake_stats,
                "forgotten_data_absent": True,
            },
            separators=(",", ":"),
        )
    )


if __name__ == "__main__":
    main()
