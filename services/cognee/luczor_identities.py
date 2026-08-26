"""Stable, database-safe identities used by the Luczor Cognee wrapper."""

from hashlib import sha256


def lock_identity(operation: str, principal_id: str, client_key_hash: str) -> str:
    """Hash a NUL-unambiguous tuple before passing it to PostgreSQL text."""

    identity = f"{operation}\0{principal_id}\0{client_key_hash}"
    return sha256(identity.encode("utf-8")).hexdigest()
