#!/bin/sh
set -eu

/bin/sh /usr/local/bin/configure-cognee-hba
psql --set=ON_ERROR_STOP=1 --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" \
    --command='SELECT pg_reload_conf();' >/dev/null
