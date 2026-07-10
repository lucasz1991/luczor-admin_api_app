#!/bin/sh
set -eu

secret=/run/secrets/postgres_password
if [ ! -r "$secret" ]; then
    echo "Cognee requires the postgres_password Docker secret." >&2
    exit 1
fi

export DB_PASSWORD="$(cat "$secret")"
exec /app/entrypoint.sh "$@"
