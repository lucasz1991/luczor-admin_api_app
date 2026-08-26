"""Static safety contract for the isolated Compose acceptance path."""

import unittest
from pathlib import Path


class ComposeContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        root = Path(__file__).resolve().parents[4]
        cls.compose = (root / "docker-compose.cognee-e2e.yml").read_text(encoding="utf-8")

    def test_runtime_network_is_internal_and_has_no_published_ports(self):
        self.assertIn("internal: true", self.compose)
        self.assertNotIn("ports:", self.compose)
        self.assertNotIn("network_mode: host", self.compose)

    def test_test_path_has_no_persistent_or_production_volumes(self):
        self.assertNotIn("volumes:", self.compose)
        self.assertIn("tmpfs:", self.compose)
        self.assertNotIn("postgres_data", self.compose)
        self.assertNotIn("cognee_data", self.compose)

    def test_both_provider_routes_are_explicitly_local(self):
        self.assertIn("LLM_PROVIDER: custom", self.compose)
        self.assertIn("EMBEDDING_PROVIDER: openai_compatible", self.compose)
        self.assertGreaterEqual(self.compose.count("http://fake-openai:8080/v1"), 2)
        self.assertNotIn("api.openai.com", self.compose)

    def test_arbitrary_local_file_paths_remain_disabled(self):
        self.assertIn('ACCEPT_LOCAL_FILE_PATH: "false"', self.compose)
        self.assertIn("read_only: true", self.compose)
        self.assertNotIn("volumes:", self.compose)

    def test_relational_and_graph_state_use_disposable_postgres(self):
        self.assertIn("GRAPH_DATABASE_PROVIDER: postgres", self.compose)
        self.assertIn("GRAPH_DATASET_DATABASE_HANDLER: postgres_graph", self.compose)
        self.assertGreaterEqual(self.compose.count("postgres-e2e"), 3)


if __name__ == "__main__":
    unittest.main()
