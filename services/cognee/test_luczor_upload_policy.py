"""Regression tests for UploadFile ingestion with local paths disabled."""

import asyncio
import os
import tempfile
import unittest
from pathlib import Path

from luczor_upload_policy import (
    cognee_stored_memory_name,
    guarded_loader,
    guarded_storage,
    is_managed_upload_path,
)


class _Loader:
    def __init__(self):
        self.paths = []

    async def load_file(self, path, _preferred):
        self.paths.append(path)
        return "file:///managed/converted.txt"

    def get_loader(self, _path, _preferred):
        return "deterministic-loader"


class _Upload:
    def __init__(self, filename):
        self.filename = filename
        self.file = object()


class LuczorUploadPolicyTest(unittest.IsolatedAsyncioTestCase):
    def test_maps_only_a_valid_luczor_upload_to_cognee_data_name(self):
        filename = f"luczor-memory-42-{'d' * 64}.txt"
        self.assertEqual(cognee_stored_memory_name(filename), filename[:-4])
        for invalid in ("other.txt", "../luczor-memory-1.txt", None):
            with self.assertRaisesRegex(ValueError, "identity"):
                cognee_stored_memory_name(invalid)

    async def test_allows_only_a_path_registered_by_the_upload_storage_step_once(self):
        with tempfile.TemporaryDirectory() as root:
            managed = Path(root, "upload.txt")
            managed.write_text("safe upload", encoding="utf-8")
            loader = _Loader()

            async def reject_local_path(_path, _preferred):
                raise RuntimeError("Local files are not accepted")

            async def store_upload(_upload):
                return managed.as_uri()

            stored = guarded_storage(store_upload, data_root=root)
            guarded = guarded_loader(reject_local_path, lambda: loader, data_root=root)

            await stored(_Upload(f"luczor-memory-1-{'a' * 64}.txt"))
            result = await guarded(managed.as_uri(), None)

            self.assertEqual(result, ("file:///managed/converted.txt", "deterministic-loader"))
            self.assertEqual(loader.paths, [managed.as_uri()])
            self.assertFalse(is_managed_upload_path(managed.as_uri(), root))
            with self.assertRaisesRegex(RuntimeError, "not accepted"):
                await guarded(managed.as_uri(), None)

    async def test_preexisting_in_root_file_is_not_upload_provenance(self):
        with tempfile.TemporaryDirectory() as root:
            preexisting = Path(root, "other-tenant.txt")
            preexisting.write_text("not this request", encoding="utf-8")

            async def reject_local_path(_path, _preferred):
                raise RuntimeError("Local files are not accepted")

            guarded = guarded_loader(reject_local_path, _Loader, data_root=root)
            self.assertFalse(is_managed_upload_path(preexisting.as_uri(), root))
            with self.assertRaisesRegex(RuntimeError, "not accepted"):
                await guarded(preexisting.as_uri(), None)

    async def test_parallel_contexts_cannot_consume_each_others_upload(self):
        with tempfile.TemporaryDirectory() as root:
            first = Path(root, "first.txt")
            second = Path(root, "second.txt")
            first.write_text("first", encoding="utf-8")
            second.write_text("second", encoding="utf-8")
            both_registered = asyncio.Event()
            registration_count = 0
            registration_lock = asyncio.Lock()

            async def reject_local_path(_path, _preferred):
                raise RuntimeError("Local files are not accepted")

            guarded = guarded_loader(reject_local_path, _Loader, data_root=root)

            async def exercise(own: Path, other: Path, suffix: str):
                nonlocal registration_count

                async def store_upload(_upload):
                    return own.as_uri()

                stored = guarded_storage(store_upload, data_root=root)
                await stored(_Upload(f"luczor-memory-{suffix}-{'b' * 64}.txt"))
                async with registration_lock:
                    registration_count += 1
                    if registration_count == 2:
                        both_registered.set()
                await both_registered.wait()
                with self.assertRaisesRegex(RuntimeError, "not accepted"):
                    await guarded(other.as_uri(), None)
                return await guarded(own.as_uri(), None)

            results = await asyncio.gather(
                exercise(first, second, "1"), exercise(second, first, "2")
            )
            self.assertEqual(
                results,
                [
                    ("file:///managed/converted.txt", "deterministic-loader"),
                    ("file:///managed/converted.txt", "deterministic-loader"),
                ],
            )

    async def test_invalid_upload_filename_never_registers_a_path(self):
        with tempfile.TemporaryDirectory() as root:
            managed = Path(root, "upload.txt")
            managed.write_text("safe upload", encoding="utf-8")
            storage_calls = []

            async def store_upload(upload):
                storage_calls.append(upload)
                return managed.as_uri()

            stored = guarded_storage(store_upload, data_root=root)
            with self.assertRaisesRegex(RuntimeError, "filename"):
                await stored(_Upload("../other-tenant.txt"))
            self.assertEqual(storage_calls, [])
            self.assertFalse(is_managed_upload_path(managed.as_uri(), root))

    async def test_external_absolute_and_file_uri_paths_stay_fail_closed(self):
        with tempfile.TemporaryDirectory() as root, tempfile.TemporaryDirectory() as outside:
            external = Path(outside, "secret.txt")
            external.write_text("not an upload", encoding="utf-8")
            calls = []

            async def reject_local_path(path, _preferred):
                calls.append(path)
                raise RuntimeError("Local files are not accepted")

            guarded = guarded_loader(reject_local_path, _Loader, data_root=root)
            for value in (str(external.resolve()), external.as_uri()):
                with self.assertRaisesRegex(RuntimeError, "not accepted"):
                    await guarded(value, None)

            self.assertEqual(calls, [str(external.resolve()), external.as_uri()])

    def test_missing_directories_and_remote_file_authorities_are_rejected(self):
        with tempfile.TemporaryDirectory() as root:
            self.assertFalse(is_managed_upload_path(Path(root, "missing.txt"), root))
            self.assertFalse(is_managed_upload_path("file://server/share/file.txt", root))

    @unittest.skipIf(os.name == "nt", "Creating symlinks is not reliable on Windows CI")
    async def test_symlink_escape_is_rejected(self):
        with tempfile.TemporaryDirectory() as root, tempfile.TemporaryDirectory() as outside:
            external = Path(outside, "secret.txt")
            external.write_text("not an upload", encoding="utf-8")
            link = Path(root, "escape.txt")
            link.symlink_to(external)

            async def store_upload(_upload):
                return link.as_uri()

            stored = guarded_storage(store_upload, data_root=root)
            with self.assertRaisesRegex(RuntimeError, "unmanaged path"):
                await stored(_Upload(f"luczor-memory-1-{'c' * 64}.txt"))
            self.assertFalse(is_managed_upload_path(link.as_uri(), root))
