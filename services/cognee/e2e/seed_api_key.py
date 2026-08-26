"""Seed one fixed API key into the disposable Cognee PostgreSQL database."""

from __future__ import annotations

import asyncio
import os


async def seed() -> None:
    api_key = os.environ.get("LUCZOR_E2E_API_KEY", "")
    if len(api_key) < 32:
        raise RuntimeError("LUCZOR_E2E_API_KEY must be an explicit test-only key")

    from sqlalchemy import delete

    from cognee.infrastructure.databases.relational import get_relational_engine
    from cognee.low_level import setup
    from cognee.modules.users.api_key.hash_api_key import prepare_api_key
    from cognee.modules.users.methods import get_default_user
    from cognee.modules.users.models.UserApiKey import UserApiKey

    await setup()
    user = await get_default_user()
    engine = get_relational_engine()
    async with engine.get_async_session() as session:
        await session.execute(delete(UserApiKey).where(UserApiKey.name == "luczor-e2e"))
        session.add(
            UserApiKey(
                user_id=user.id,
                api_key=prepare_api_key(api_key),
                label="e2e-only****",
                name="luczor-e2e",
            )
        )
        await session.commit()
    print("Disposable Cognee API principal created.")


if __name__ == "__main__":
    asyncio.run(seed())
