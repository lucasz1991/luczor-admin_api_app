#!/bin/sh
set -eu

# Existing volumes do not rerun docker-entrypoint-initdb.d. Apply the HBA guard
# before PostgreSQL starts on every boot as well as during first-time init.
/bin/sh /usr/local/bin/configure-cognee-hba
exec /usr/local/bin/docker-entrypoint.sh "$@"
