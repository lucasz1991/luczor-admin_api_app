"""Regression tests for PostgreSQL-safe advisory-lock identities."""

import unittest

from luczor_identities import lock_identity


class LuczorIdentityTest(unittest.TestCase):
    def test_lock_identity_is_stable_lowercase_sha256_without_nul(self):
        first = lock_identity("cognify", "principal-1", "a" * 64)
        second = lock_identity("cognify", "principal-1", "a" * 64)

        self.assertEqual(first, second)
        self.assertRegex(first, r"^[a-f0-9]{64}$")
        self.assertNotIn("\0", first)

    def test_nul_delimited_fields_remain_unambiguous_before_hashing(self):
        self.assertNotEqual(
            lock_identity("ab", "c", "d"),
            lock_identity("a", "bc", "d"),
        )
        self.assertNotEqual(
            lock_identity("cognify", "principal-1", "a" * 64),
            lock_identity("cognify", "principal-2", "a" * 64),
        )
