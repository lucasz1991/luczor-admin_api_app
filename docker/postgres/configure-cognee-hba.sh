#!/bin/sh
set -eu

require_identifier() {
    value="$1"
    case "$value" in
        ''|*[!A-Za-z0-9_]*|[0-9]*)
            echo "Cognee HBA identifiers must be valid PostgreSQL identifiers." >&2
            exit 1
            ;;
    esac
}

: "${PGDATA:=/var/lib/postgresql/data}"
: "${COGNEE_POSTGRES_DB:?COGNEE_POSTGRES_DB is required}"
: "${COGNEE_POSTGRES_USER:?COGNEE_POSTGRES_USER is required}"

require_identifier "$COGNEE_POSTGRES_DB"
require_identifier "$COGNEE_POSTGRES_USER"

hba_file="$PGDATA/pg_hba.conf"
guard_file="$PGDATA/luczor-cognee-hba.conf"
include_line="include_if_exists luczor-cognee-hba.conf"
legacy_include_line="include_if_exists 'luczor-cognee-hba.conf'"

if [ ! -f "$hba_file" ]; then
    # On a fresh volume the official entrypoint creates pg_hba.conf first and
    # invokes this helper from docker-entrypoint-initdb.d afterwards.
    exit 0
fi

guard_tmp="$(mktemp "$PGDATA/luczor-cognee-hba.conf.XXXXXX")"
hba_tmp="$(mktemp "$PGDATA/pg_hba.conf.XXXXXX")"
cleanup() {
    rm -f "$guard_tmp" "$hba_tmp"
}
trap cleanup EXIT HUP INT TERM

{
    printf 'local %s %s scram-sha-256\n' "$COGNEE_POSTGRES_DB" "$COGNEE_POSTGRES_USER"
    printf 'local all %s reject\n' "$COGNEE_POSTGRES_USER"
    printf 'host %s %s all scram-sha-256\n' "$COGNEE_POSTGRES_DB" "$COGNEE_POSTGRES_USER"
    printf 'host all %s all reject\n' "$COGNEE_POSTGRES_USER"
} > "$guard_tmp"

# The include must be the first HBA record because PostgreSQL uses the first
# matching rule and never falls through after an authentication failure.
{
    printf '%s\n' "$include_line"
    awk -v include_line="$include_line" -v legacy_include_line="$legacy_include_line" \
        '$0 != include_line && $0 != legacy_include_line { print }' "$hba_file"
} > "$hba_tmp"

chown --reference="$hba_file" "$guard_tmp" "$hba_tmp"
chmod --reference="$hba_file" "$guard_tmp" "$hba_tmp"
mv -f "$guard_tmp" "$guard_file"
mv -f "$hba_tmp" "$hba_file"
trap - EXIT HUP INT TERM
