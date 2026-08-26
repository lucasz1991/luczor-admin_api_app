#!/bin/sh
set -eu

fail() {
    echo "Cognee E2E configuration rejected: $1" >&2
    exit 1
}

[ "${LUCZOR_COGNEE_E2E:-}" = "1" ] || fail "LUCZOR_COGNEE_E2E must equal 1"
[ "${DB_HOST:-}" = "postgres-e2e" ] || fail "DB_HOST must be the disposable PostgreSQL service"
[ "${GRAPH_DATABASE_PROVIDER:-}" = "postgres" ] || fail "graph storage must use disposable PostgreSQL"
[ "${GRAPH_DATABASE_HOST:-}" = "postgres-e2e" ] || fail "graph storage must target disposable PostgreSQL"
[ "${LLM_PROVIDER:-}" = "custom" ] || fail "LLM_PROVIDER must be custom"
[ "${EMBEDDING_PROVIDER:-}" = "openai_compatible" ] || fail "EMBEDDING_PROVIDER must be openai_compatible"
[ "${ACCEPT_LOCAL_FILE_PATH:-}" = "false" ] || fail "arbitrary local paths must remain disabled"
[ "${LLM_ENDPOINT:-}" = "http://fake-openai:8080/v1" ] || fail "LLM_ENDPOINT must target the local fake provider"
[ "${EMBEDDING_ENDPOINT:-}" = "http://fake-openai:8080/v1" ] || fail "EMBEDDING_ENDPOINT must target the local fake provider"
[ -n "${LUCZOR_E2E_DB_PASSWORD:-}" ] || fail "test database password is missing"
[ -n "${LUCZOR_E2E_PROVIDER_API_KEY:-}" ] || fail "test provider key is missing"

export DB_PASSWORD="$LUCZOR_E2E_DB_PASSWORD"
export LLM_API_KEY="$LUCZOR_E2E_PROVIDER_API_KEY"
export EMBEDDING_API_KEY="$LUCZOR_E2E_PROVIDER_API_KEY"
export FASTAPI_USERS_JWT_SECRET="e2e-only-jwt-secret-with-at-least-32-bytes"
export DEFAULT_USER_PASSWORD="e2e-only-default-user-password"
export FASTAPI_USERS_VERIFICATION_TOKEN_SECRET="e2e-only-verification-secret-32-bytes"
export FASTAPI_USERS_RESET_PASSWORD_TOKEN_SECRET="e2e-only-reset-secret-with-32-bytes"

# Execute the pinned image's startup after the E2E-only endpoint policy has
# been verified. This still runs the real migrations and Luczor wrapper.
exec /app/entrypoint.sh "$@"
