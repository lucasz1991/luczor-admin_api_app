#!/bin/sh
set -eu

read_required_secret() {
    secret="/run/secrets/$1"
    if [ ! -s "$secret" ]; then
        echo "Cognee requires the $1 Docker secret." >&2
        exit 1
    fi
    cat "$secret"
}

require_value() {
    name="$1"
    value="$2"
    if [ -z "$value" ]; then
        echo "Cognee requires the $name configuration value." >&2
        exit 1
    fi
}

require_positive_integer() {
    name="$1"
    value="$2"
    case "$value" in
        ''|*[!0-9]*)
            echo "Cognee requires $name to be a positive integer." >&2
            exit 1
            ;;
    esac
    if [ "$value" -le 0 ]; then
        echo "Cognee requires $name to be a positive integer." >&2
        exit 1
    fi
}

require_value DB_NAME "${DB_NAME:-}"
require_value DB_USERNAME "${DB_USERNAME:-}"
require_value LLM_PROVIDER "${LLM_PROVIDER:-}"
require_value LLM_MODEL "${LLM_MODEL:-}"
require_value EMBEDDING_PROVIDER "${EMBEDDING_PROVIDER:-}"
require_value EMBEDDING_MODEL "${EMBEDDING_MODEL:-}"
require_positive_integer EMBEDDING_DIMENSIONS "${EMBEDDING_DIMENSIONS:-}"
require_positive_integer LLM_MAX_COMPLETION_TOKENS "${LLM_MAX_COMPLETION_TOKENS:-}"

export DB_PASSWORD="$(read_required_secret cognee_postgres_password)"

llm_provider="$(printf '%s' "$LLM_PROVIDER" | tr '[:upper:]' '[:lower:]')"
case "$llm_provider" in
    ollama)
        require_value LLM_ENDPOINT "${LLM_ENDPOINT:-}"
        case "$LLM_ENDPOINT" in
            */v1) ;;
            *)
                echo "Cognee requires an Ollama LLM_ENDPOINT ending in /v1." >&2
                exit 1
                ;;
        esac
        # Cognee's OpenAI-compatible Ollama client requires a non-empty value,
        # but Ollama does not authenticate this local connection.
        export LLM_API_KEY="${LLM_API_KEY:-$llm_provider}"
        ;;
    *)
        export LLM_API_KEY="$(read_required_secret cognee_llm_api_key)"
        ;;
esac

embedding_provider="$(printf '%s' "$EMBEDDING_PROVIDER" | tr '[:upper:]' '[:lower:]')"
case "$embedding_provider" in
    fastembed)
        unset EMBEDDING_API_KEY
        ;;
    ollama)
        export EMBEDDING_API_KEY="${EMBEDDING_API_KEY:-$embedding_provider}"
        ;;
    *)
        export EMBEDDING_API_KEY="$(read_required_secret cognee_embedding_api_key)"
        ;;
esac

export FASTAPI_USERS_JWT_SECRET="$(read_required_secret cognee_jwt_secret)"
export DEFAULT_USER_PASSWORD="$(read_required_secret cognee_default_password)"
export FASTAPI_USERS_VERIFICATION_TOKEN_SECRET="$(read_required_secret cognee_verification_secret)"
export FASTAPI_USERS_RESET_PASSWORD_TOKEN_SECRET="$(read_required_secret cognee_reset_secret)"
exec /app/entrypoint.sh "$@"
