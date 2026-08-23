#!/bin/sh
set -eu

read_required_secret() {
    secret="/run/secrets/$1"
    if [ ! -s "$secret" ]; then
        echo "Cognee database initialization requires the $1 Docker secret." >&2
        exit 1
    fi
    cat "$secret"
}

require_identifier() {
    name="$1"
    value="$2"

    case "$value" in
        ''|*[!A-Za-z0-9_]*)
            echo "$name must be a non-empty PostgreSQL identifier containing only letters, digits, and underscores." >&2
            exit 1
            ;;
    esac

    case "$value" in
        [A-Za-z_]*) ;;
        *)
            echo "$name must start with a letter or underscore." >&2
            exit 1
            ;;
    esac

    if [ "${#value}" -gt 63 ]; then
        echo "$name must not exceed PostgreSQL's 63-byte identifier limit." >&2
        exit 1
    fi
}

: "${POSTGRES_HOST:=postgres}"
: "${POSTGRES_PORT:=5432}"
: "${POSTGRES_ADMIN_DB:=postgres}"
: "${POSTGRES_ADMIN_USER:?POSTGRES_ADMIN_USER is required}"
: "${COGNEE_POSTGRES_DB:?COGNEE_POSTGRES_DB is required}"
: "${COGNEE_POSTGRES_USER:?COGNEE_POSTGRES_USER is required}"

require_identifier POSTGRES_ADMIN_DB "$POSTGRES_ADMIN_DB"
require_identifier POSTGRES_ADMIN_USER "$POSTGRES_ADMIN_USER"
require_identifier COGNEE_POSTGRES_DB "$COGNEE_POSTGRES_DB"
require_identifier COGNEE_POSTGRES_USER "$COGNEE_POSTGRES_USER"

case "$COGNEE_POSTGRES_DB" in
    postgres|template0|template1)
        echo "Cognee must not use a PostgreSQL maintenance or template database." >&2
        exit 1
        ;;
esac

case "$COGNEE_POSTGRES_USER" in
    pg_*)
        echo "Cognee must not use PostgreSQL's reserved pg_ role namespace." >&2
        exit 1
        ;;
esac

if [ "$COGNEE_POSTGRES_DB" = "$POSTGRES_ADMIN_DB" ]; then
    echo "Cognee must use a database separate from the PostgreSQL administration database." >&2
    exit 1
fi

if [ "$COGNEE_POSTGRES_USER" = "$POSTGRES_ADMIN_USER" ]; then
    echo "Cognee must use a PostgreSQL role separate from the administration role." >&2
    exit 1
fi

export PGPASSWORD="$(read_required_secret postgres_password)"
export LUCZOR_COGNEE_DB_PASSWORD="$(read_required_secret cognee_postgres_password)"

cleanup() {
    unset PGPASSWORD LUCZOR_COGNEE_DB_PASSWORD
}
trap cleanup EXIT
trap 'cleanup; exit 1' HUP INT TERM

psql \
    --host="$POSTGRES_HOST" \
    --port="$POSTGRES_PORT" \
    --username="$POSTGRES_ADMIN_USER" \
    --dbname="$POSTGRES_ADMIN_DB" \
    --no-password \
    --quiet \
    --set=ON_ERROR_STOP=1 \
    --set=admin_database="$POSTGRES_ADMIN_DB" \
    --set=admin_user="$POSTGRES_ADMIN_USER" \
    --set=cognee_database="$COGNEE_POSTGRES_DB" \
    --set=cognee_user="$COGNEE_POSTGRES_USER" <<'SQL'
\getenv cognee_password LUCZOR_COGNEE_DB_PASSWORD

SELECT CASE WHEN NOT EXISTS (
    SELECT 1
    FROM pg_database
    WHERE datname = :'admin_database'
      AND pg_get_userbyid(datdba) = :'admin_user'
) THEN 'true' ELSE 'false' END AS admin_database_has_unexpected_owner
\gset
\if :admin_database_has_unexpected_owner
\echo 'Refusing to change database privileges because the administration database is not owned by the configured administration role.'
\quit 2
\endif

SELECT CASE WHEN EXISTS (
    SELECT 1
    FROM pg_roles AS candidate
    WHERE candidate.rolname = :'cognee_user'
      AND (
          candidate.rolsuper
          OR candidate.rolcreatedb
          OR candidate.rolcreaterole
          OR candidate.rolreplication
          OR candidate.rolbypassrls
          OR EXISTS (SELECT 1 FROM pg_auth_members AS membership WHERE membership.member = candidate.oid)
      )
) THEN 'true' ELSE 'false' END AS cognee_role_is_privileged
\gset
\if :cognee_role_is_privileged
\echo 'Refusing to repurpose an existing privileged PostgreSQL role for Cognee.'
\quit 3
\endif

SELECT CASE WHEN EXISTS (
    SELECT 1
    FROM pg_database
    WHERE datname = :'cognee_database'
      AND pg_get_userbyid(datdba) NOT IN (:'admin_user', :'cognee_user')
) THEN 'true' ELSE 'false' END AS cognee_database_has_unexpected_owner
\gset
\if :cognee_database_has_unexpected_owner
\echo 'Refusing to take ownership of an existing database owned by an unrelated role.'
\quit 4
\endif

SELECT format(
    'CREATE ROLE %I WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS PASSWORD %L',
    :'cognee_user',
    :'cognee_password'
)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'cognee_user')
\gexec

SELECT format(
    'ALTER ROLE %I WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS PASSWORD %L',
    :'cognee_user',
    :'cognee_password'
)
\gexec

BEGIN;
-- Remove PUBLIC's default CONNECT/TEMPORARY grants cluster-wide. Direct Cognee
-- grants are removed from every existing non-target database as defence in
-- depth; pg_hba.conf also rejects future databases before ACL evaluation.
SELECT format('REVOKE CONNECT, TEMPORARY ON DATABASE %I FROM PUBLIC', datname)
FROM pg_database
WHERE datallowconn
\gexec
SELECT format('REVOKE ALL PRIVILEGES ON DATABASE %I FROM %I', datname, :'cognee_user')
FROM pg_database
WHERE datname <> :'cognee_database'
\gexec
SELECT format('GRANT CONNECT, TEMPORARY ON DATABASE %I TO %I', :'admin_database', :'admin_user')
\gexec
COMMIT;

SELECT CASE WHEN has_database_privilege(:'cognee_user', :'admin_database', 'CONNECT')
    OR has_database_privilege(:'cognee_user', :'admin_database', 'TEMPORARY')
    THEN 'true' ELSE 'false' END AS cognee_can_connect_to_admin_database
\gset
\if :cognee_can_connect_to_admin_database
\echo 'Cognee database isolation failed: the Cognee role can still connect to the administration database.'
\quit 5
\endif

SELECT CASE WHEN NOT (
    has_database_privilege(:'admin_user', :'admin_database', 'CONNECT')
    AND has_database_privilege(:'admin_user', :'admin_database', 'TEMPORARY')
) THEN 'true' ELSE 'false' END AS admin_lost_admin_database_access
\gset
\if :admin_lost_admin_database_access
\echo 'Cognee database isolation failed: the administration role lost access to its database.'
\quit 6
\endif

SELECT format('CREATE DATABASE %I OWNER %I', :'cognee_database', :'cognee_user')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'cognee_database')
\gexec

SELECT format('ALTER DATABASE %I OWNER TO %I', :'cognee_database', :'cognee_user')
\gexec
SELECT format('REVOKE CONNECT, TEMPORARY ON DATABASE %I FROM PUBLIC', :'cognee_database')
\gexec
SELECT format('GRANT CONNECT, TEMPORARY ON DATABASE %I TO %I', :'cognee_database', :'cognee_user')
\gexec

SELECT CASE WHEN EXISTS (
    SELECT 1
    FROM pg_database
    WHERE datallowconn
      AND datname <> :'cognee_database'
      AND (
          has_database_privilege(:'cognee_user', datname, 'CONNECT')
          OR has_database_privilege(:'cognee_user', datname, 'TEMPORARY')
      )
) THEN 'true' ELSE 'false' END AS cognee_has_foreign_database_access
\gset
\if :cognee_has_foreign_database_access
\echo 'Cognee database isolation failed: the Cognee role can access a non-target database.'
\quit 7
\endif

SELECT CASE WHEN NOT (
    has_database_privilege(:'cognee_user', :'cognee_database', 'CONNECT')
    AND has_database_privilege(:'cognee_user', :'cognee_database', 'TEMPORARY')
) THEN 'true' ELSE 'false' END AS cognee_lost_target_database_access
\gset
\if :cognee_lost_target_database_access
\echo 'Cognee database isolation failed: the Cognee role lost access to its target database.'
\quit 8
\endif
SQL

psql \
    --host="$POSTGRES_HOST" \
    --port="$POSTGRES_PORT" \
    --username="$POSTGRES_ADMIN_USER" \
    --dbname="$COGNEE_POSTGRES_DB" \
    --no-password \
    --quiet \
    --set=ON_ERROR_STOP=1 \
    --set=cognee_user="$COGNEE_POSTGRES_USER" <<'SQL'
CREATE EXTENSION IF NOT EXISTS vector;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
SELECT format('ALTER SCHEMA public OWNER TO %I', :'cognee_user')
\gexec
SELECT format('GRANT ALL ON SCHEMA public TO %I', :'cognee_user')
\gexec
SQL

echo "Cognee PostgreSQL database and role are ready."
