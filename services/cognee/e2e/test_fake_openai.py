"""Unit contracts for the deterministic local provider."""

import unittest

try:
    from .fake_openai import deterministic_embedding, extract_schema, schema_example
except ImportError:  # Direct discovery with e2e as the start directory.
    from fake_openai import deterministic_embedding, extract_schema, schema_example


class FakeOpenAITest(unittest.TestCase):
    def test_embeddings_are_deterministic_normalized_and_input_sensitive(self):
        first = deterministic_embedding("alpha", 16)
        second = deterministic_embedding("alpha", 16)
        different = deterministic_embedding("beta", 16)

        self.assertEqual(first, second)
        self.assertNotEqual(first, different)
        self.assertEqual(len(first), 16)
        self.assertAlmostEqual(sum(value * value for value in first), 1.0, places=8)

    def test_schema_example_resolves_defs_and_required_fields(self):
        schema = {
            "$defs": {
                "Result": {
                    "type": "object",
                    "properties": {
                        "items": {"type": "array", "items": {"type": "string"}},
                        "ok": {"type": "boolean"},
                    },
                    "required": ["items", "ok"],
                }
            },
            "$ref": "#/$defs/Result",
        }

        self.assertEqual(schema_example(schema), {"items": [], "ok": False})

    def test_extracts_json_schema_from_response_format(self):
        schema = {"type": "object", "properties": {"summary": {"type": "string"}}}
        payload = {
            "response_format": {
                "type": "json_schema",
                "json_schema": {"name": "answer", "schema": schema},
            }
        }

        self.assertEqual(extract_schema(payload), schema)

    def test_extracts_instructor_schema_embedded_in_message(self):
        schema = {"type": "object", "properties": {"nodes": {"type": "array"}}}
        payload = {
            "messages": [
                {"role": "system", "content": f"Return JSON using this schema: {schema!r}"},
                {
                    "role": "user",
                    "content": 'Schema follows: {"type":"object","properties":{"nodes":{"type":"array"}}}',
                },
            ]
        }

        self.assertEqual(extract_schema(payload), schema)


if __name__ == "__main__":
    unittest.main()
