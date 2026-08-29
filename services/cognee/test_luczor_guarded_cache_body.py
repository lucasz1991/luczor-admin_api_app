"""Executable contracts for guarded Cognee launch-response normalization."""

import ast
import json
import unittest
from pathlib import Path
from uuid import UUID


def _load_guarded_cache_body():
    """Load only the pure production helpers without importing Cognee."""
    source_path = Path(__file__).with_name("luczor_cognee_app.py")
    module = ast.parse(source_path.read_text(encoding="utf-8"), filename=str(source_path))
    selected = [
        node
        for node in module.body
        if isinstance(node, ast.FunctionDef)
        and node.name in {"_uuid_string", "_guarded_cache_body"}
    ]
    namespace = {"json": json, "UUID": UUID}
    exec(compile(ast.Module(body=selected, type_ignores=[]), str(source_path), "exec"), namespace)
    return namespace["_guarded_cache_body"]


class LuczorGuardedCacheBodyTest(unittest.TestCase):
    dataset_id = "3e1e6f13-0360-4bb8-a14e-7ed8c9cb6ff9"
    run_id = "744a537f-bb81-4637-8287-79b5c55f0913"

    def setUp(self):
        self.normalize = _load_guarded_cache_body()

    def _body(self, payload):
        return json.dumps(payload, separators=(",", ":")).encode("utf-8")

    def _normalized(self, payload):
        value = self.normalize("improve", 200, self._body(payload))
        return json.loads(value) if value is not None else None

    def test_accepts_flat_improve_acceptance(self):
        self.assertEqual(
            self._normalized(
                {
                    "pipeline_run_id": self.run_id,
                    "dataset_id": self.dataset_id,
                    "status": "PipelineRunStarted",
                }
            ),
            {
                "pipeline_run_id": self.run_id,
                "dataset_id": self.dataset_id,
                "status": "PipelineRunStarted",
            },
        )

    def test_flattens_the_single_dataset_map_returned_by_cognee_1_4(self):
        self.assertEqual(
            self._normalized(
                {
                    self.dataset_id: {
                        "pipeline_run_id": self.run_id,
                        "dataset_id": self.dataset_id,
                        "status": "DATASET_PROCESSING_INITIATED",
                    }
                }
            ),
            {
                "pipeline_run_id": self.run_id,
                "dataset_id": self.dataset_id,
                "status": "DATASET_PROCESSING_INITIATED",
            },
        )

    def test_rejects_a_mismatched_dataset_map_key(self):
        other_dataset = "46fdc252-d660-4690-8220-04504368422c"
        self.assertIsNone(
            self._normalized(
                {
                    other_dataset: {
                        "pipeline_run_id": self.run_id,
                        "dataset_id": self.dataset_id,
                    }
                }
            )
        )

    def test_rejects_multiple_improve_runs(self):
        other_dataset = "46fdc252-d660-4690-8220-04504368422c"
        self.assertIsNone(
            self._normalized(
                {
                    self.dataset_id: {
                        "pipeline_run_id": self.run_id,
                        "dataset_id": self.dataset_id,
                    },
                    other_dataset: {
                        "pipeline_run_id": "18eb4da1-32d8-4b27-9e68-f6e3c00adc67",
                        "dataset_id": other_dataset,
                    },
                }
            )
        )

    def test_rejects_invalid_run_identifiers(self):
        self.assertIsNone(
            self._normalized(
                {
                    self.dataset_id: {
                        "pipeline_run_id": "not-a-uuid",
                        "dataset_id": self.dataset_id,
                    }
                }
            )
        )


if __name__ == "__main__":
    unittest.main()
