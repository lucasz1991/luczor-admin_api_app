"""Composable ASGI lifespan support for the Luczor Cognee wrapper."""

from contextlib import asynccontextmanager


def install_luczor_lifespan(app, initialize, shutdown):
    """Fence the complete upstream Cognee lifespan with Luczor's lease."""
    upstream_lifespan = app.router.lifespan_context

    @asynccontextmanager
    async def luczor_lifespan(application):
        try:
            # Cognee startup performs stale pipeline recovery. Acquire the
            # singleton lease first so even that startup work is fenced.
            await initialize()
            async with upstream_lifespan(application) as upstream_state:
                yield upstream_state
        finally:
            # Keep the lease through upstream shutdown as background pipelines
            # wind down. Initialization and upstream startup can both fail
            # partially; shutdown is idempotent and releases either state.
            await shutdown()

    app.router.lifespan_context = luczor_lifespan
