"""Executable concurrency contracts for the Cognee Add recovery barrier."""

import ast
import asyncio
import unittest
from contextlib import asynccontextmanager
from pathlib import Path


def _load_barrier_namespace():
    """Load the production barrier without importing Cognee runtime dependencies."""
    source_path = Path(__file__).with_name("luczor_cognee_app.py")
    module = ast.parse(source_path.read_text(encoding="utf-8"), filename=str(source_path))
    selected_names = {
        "_add_barrier_condition",
        "_active_add_operations",
        "_exclusive_add_lookup_active",
        "_pending_exclusive_add_lookups",
    }
    selected_functions = {
        "_enter_add_operation",
        "_leave_add_operation",
        "_exclusive_add_lookup",
    }
    selected_nodes = []
    for node in module.body:
        if isinstance(node, ast.Assign) and any(
            isinstance(target, ast.Name) and target.id in selected_names
            for target in node.targets
        ):
            selected_nodes.append(node)
        elif isinstance(node, (ast.AsyncFunctionDef, ast.FunctionDef)) and node.name in selected_functions:
            selected_nodes.append(node)

    namespace = {
        "asyncio": asyncio,
        "asynccontextmanager": asynccontextmanager,
    }
    exec(compile(ast.Module(body=selected_nodes, type_ignores=[]), str(source_path), "exec"), namespace)
    return namespace


class LuczorAddBarrierTest(unittest.IsolatedAsyncioTestCase):
    async def test_waiting_lookup_blocks_a_later_add_until_recovery_finishes(self):
        barrier = _load_barrier_namespace()
        enter_add = barrier["_enter_add_operation"]
        leave_add = barrier["_leave_add_operation"]
        exclusive_lookup = barrier["_exclusive_add_lookup"]

        first_add_entered = asyncio.Event()
        finish_first_add = asyncio.Event()
        lookup_entered = asyncio.Event()
        finish_lookup = asyncio.Event()
        second_add_entered = asyncio.Event()
        events = []

        async def first_add():
            await enter_add()
            try:
                events.append("first-add")
                first_add_entered.set()
                await finish_first_add.wait()
            finally:
                await leave_add()

        async def lookup():
            async with exclusive_lookup():
                events.append("lookup")
                lookup_entered.set()
                await finish_lookup.wait()

        async def second_add():
            await enter_add()
            try:
                events.append("second-add")
                second_add_entered.set()
            finally:
                await leave_add()

        first_task = asyncio.create_task(first_add())
        lookup_task = None
        second_task = None
        try:
            await first_add_entered.wait()

            lookup_task = asyncio.create_task(lookup())
            for _ in range(10):
                await asyncio.sleep(0)
                if barrier["_pending_exclusive_add_lookups"] == 1:
                    break
            self.assertEqual(barrier["_pending_exclusive_add_lookups"], 1)

            second_task = asyncio.create_task(second_add())
            await asyncio.sleep(0)
            self.assertFalse(second_add_entered.is_set())

            finish_first_add.set()
            await asyncio.wait_for(lookup_entered.wait(), timeout=1)
            self.assertFalse(second_add_entered.is_set())

            finish_lookup.set()
            await asyncio.wait_for(second_add_entered.wait(), timeout=1)

            await asyncio.gather(first_task, lookup_task, second_task)
            self.assertEqual(events, ["first-add", "lookup", "second-add"])
            self.assertEqual(barrier["_pending_exclusive_add_lookups"], 0)
            self.assertFalse(barrier["_exclusive_add_lookup_active"])
            self.assertEqual(barrier["_active_add_operations"], 0)
        finally:
            finish_first_add.set()
            finish_lookup.set()
            tasks = [task for task in (first_task, lookup_task, second_task) if task is not None]
            await asyncio.gather(*tasks, return_exceptions=True)


if __name__ == "__main__":
    unittest.main()
