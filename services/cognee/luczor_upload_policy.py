"""Permit request-proven Cognee uploads without enabling arbitrary local paths.

Cognee 1.4 first copies an HTTP UploadFile into DATA_ROOT_DIRECTORY, then passes
that resulting ``file://`` URI through its generic local-path loader. With
ACCEPT_LOCAL_FILE_PATH=false the second step rejects Cognee's own managed file.
This policy records the exact path returned by that upload spool operation in
the current async context and consumes the permission once at the loader. Merely
being a real file below the data root is not authorization. All unproven paths
continue through Cognee's original fail-closed implementation.
"""

from __future__ import annotations

from contextvars import ContextVar
import importlib
import os
from pathlib import Path
import re
from typing import Any, Awaitable, Callable
from urllib.parse import unquote, urlparse


_installed = False
_managed_upload_paths: ContextVar[tuple[str, ...]] = ContextVar(
    "luczor_managed_upload_paths", default=()
)
_LUCZOR_UPLOAD_NAME = re.compile(r"^luczor-memory-[0-9]+-[a-f0-9]{64}\.txt$")


def cognee_stored_memory_name(filename: Any) -> str:
    """Map Luczor's upload identity to Cognee 1.4's extensionless Data.name."""

    if not isinstance(filename, str) or not _LUCZOR_UPLOAD_NAME.fullmatch(filename):
        raise ValueError("Cognee upload filename is not a Luczor memory identity")
    return filename.removesuffix(".txt")


def _local_path(value: Any) -> Path | None:
    if not isinstance(value, str):
        return None
    parsed = urlparse(value)
    if parsed.scheme == "file":
        # Remote file authorities/UNC shares are never managed uploads.
        if parsed.netloc not in ("", "localhost"):
            return None
        raw_path = unquote(parsed.path)
        if os.name == "nt" and len(raw_path) > 2 and raw_path[0] == "/" and raw_path[2] == ":":
            raw_path = raw_path[1:]
        return Path(raw_path)
    if parsed.scheme:
        return None
    path = Path(value)
    return path if path.is_absolute() else None


def _canonical_managed_path(
    value: Any, data_root: str | os.PathLike[str]
) -> str | None:
    candidate = _local_path(value)
    if candidate is None:
        return None
    try:
        canonical_root = Path(data_root).resolve(strict=True)
        canonical_candidate = candidate.resolve(strict=True)
        canonical_candidate.relative_to(canonical_root)
        if canonical_candidate == canonical_root or not canonical_candidate.is_file():
            return None
        return os.path.normcase(str(canonical_candidate))
    except (OSError, RuntimeError, ValueError):
        return None


def is_managed_upload_path(value: Any, data_root: str | os.PathLike[str]) -> bool:
    """Return whether this exact path is registered in the current context.

    This check does not consume the one-shot registration. Authorization uses
    ``_consume_managed_upload_path`` immediately before loading.
    """

    candidate = _canonical_managed_path(value, data_root)
    return candidate is not None and candidate in _managed_upload_paths.get()


def _register_managed_upload_path(
    value: Any, data_root: str | os.PathLike[str]
) -> None:
    candidate = _canonical_managed_path(value, data_root)
    if candidate is None:
        raise RuntimeError("Cognee upload storage returned an unmanaged path")
    _managed_upload_paths.set((*_managed_upload_paths.get(), candidate))


def _consume_managed_upload_path(
    value: Any, data_root: str | os.PathLike[str]
) -> bool:
    candidate = _canonical_managed_path(value, data_root)
    if candidate is None:
        return False
    registered = list(_managed_upload_paths.get())
    try:
        registered.remove(candidate)
    except ValueError:
        return False
    _managed_upload_paths.set(tuple(registered))
    return True


def guarded_storage(
    upstream: Callable[..., Awaitable[str]],
    *,
    data_root: str,
) -> Callable[..., Awaitable[str]]:
    """Register only paths produced directly from a validated HTTP upload."""

    async def store(data_item: Any) -> str:
        is_upload = hasattr(data_item, "file")
        if is_upload:
            filename = getattr(data_item, "filename", None)
            try:
                cognee_stored_memory_name(filename)
            except ValueError as error:
                raise RuntimeError(str(error)) from error

        stored_path = await upstream(data_item)
        if is_upload:
            _register_managed_upload_path(stored_path, data_root)
        return stored_path

    return store


def guarded_loader(
    upstream: Callable[..., Awaitable[Any]],
    loader_factory: Callable[[], Any],
    *,
    data_root: str,
) -> Callable[..., Awaitable[Any]]:
    async def load(data_item_path: str, preferred_loaders=None):
        if not _consume_managed_upload_path(data_item_path, data_root):
            return await upstream(data_item_path, preferred_loaders)

        loader = loader_factory()
        text_path = await loader.load_file(data_item_path, preferred_loaders)
        selected_loader = loader.get_loader(data_item_path, preferred_loaders)
        return text_path, selected_loader

    return load


def install_managed_upload_policy() -> None:
    global _installed
    if _installed:
        return

    from cognee.infrastructure.loaders import get_loader_engine
    policy_module = importlib.import_module("cognee.tasks.ingestion.data_item_to_text_file")
    ingest_module = importlib.import_module("cognee.tasks.ingestion.ingest_data")
    storage_module = importlib.import_module("cognee.tasks.ingestion.save_data_item_to_storage")

    data_root = os.environ.get("DATA_ROOT_DIRECTORY", "").strip()
    if not data_root or not Path(data_root).is_absolute():
        raise RuntimeError("DATA_ROOT_DIRECTORY must be an absolute path")

    # Depending on package import order, the package attributes may expose the
    # functions rather than their modules. Patch the defining module and the
    # already-imported ingest_data global that calls it.
    upstream_loader = policy_module.data_item_to_text_file
    loader_replacement = guarded_loader(
        upstream_loader, get_loader_engine, data_root=data_root
    )
    upstream_storage = storage_module.save_data_item_to_storage
    storage_replacement = guarded_storage(upstream_storage, data_root=data_root)
    policy_module.data_item_to_text_file = loader_replacement
    ingest_module.data_item_to_text_file = loader_replacement
    storage_module.save_data_item_to_storage = storage_replacement
    ingest_module.save_data_item_to_storage = storage_replacement
    _installed = True
