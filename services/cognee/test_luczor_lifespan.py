"""Executable contract tests for composition with Cognee's own lifespan."""

import unittest
from contextlib import asynccontextmanager

from luczor_lifespan import install_luczor_lifespan


class _Router:
    def __init__(self, lifespan_context):
        self.lifespan_context = lifespan_context


class _App:
    def __init__(self, lifespan_context):
        self.router = _Router(lifespan_context)


class LuczorLifespanTest(unittest.IsolatedAsyncioTestCase):
    async def test_wraps_upstream_lifespan_in_the_required_order(self):
        events = []

        @asynccontextmanager
        async def upstream(application):
            events.append("upstream-start")
            try:
                yield {"upstream": application}
            finally:
                events.append("upstream-stop")

        app = _App(upstream)

        async def initialize():
            events.append("luczor-start")

        async def shutdown():
            events.append("luczor-stop")

        install_luczor_lifespan(app, initialize, shutdown)

        async with app.router.lifespan_context(app) as state:
            self.assertIs(state["upstream"], app)
            events.append("serving")

        self.assertEqual(
            events,
            [
                "luczor-start",
                "upstream-start",
                "serving",
                "upstream-stop",
                "luczor-stop",
            ],
        )

    async def test_initialization_failure_still_cleans_partial_luczor_state(self):
        events = []

        @asynccontextmanager
        async def upstream(_application):
            events.append("upstream-start")
            try:
                yield None
            finally:
                events.append("upstream-stop")

        app = _App(upstream)

        async def initialize():
            events.append("luczor-start")
            raise RuntimeError("lease unavailable")

        async def shutdown():
            events.append("luczor-stop")

        install_luczor_lifespan(app, initialize, shutdown)

        with self.assertRaisesRegex(RuntimeError, "lease unavailable"):
            async with app.router.lifespan_context(app):
                self.fail("A failed initialization must never serve requests.")

        self.assertEqual(
            events,
            ["luczor-start", "luczor-stop"],
        )

    async def test_upstream_startup_failure_releases_the_already_acquired_lease(self):
        events = []

        @asynccontextmanager
        async def upstream(_application):
            events.append("upstream-start")
            raise RuntimeError("upstream unavailable")
            yield  # pragma: no cover - required async-generator shape

        app = _App(upstream)

        async def initialize():
            events.append("luczor-start")

        async def shutdown():
            events.append("luczor-stop")

        install_luczor_lifespan(app, initialize, shutdown)

        with self.assertRaisesRegex(RuntimeError, "upstream unavailable"):
            async with app.router.lifespan_context(app):
                self.fail("A failed upstream startup must never serve requests.")

        self.assertEqual(
            events,
            ["luczor-start", "upstream-start", "luczor-stop"],
        )


if __name__ == "__main__":
    unittest.main()
