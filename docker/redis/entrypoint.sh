#!/bin/sh
set -eu

secret_file=/run/secrets/redis_password
runtime_directory=/tmp/luczor-redis
config_file="$runtime_directory/redis.conf"
maxmemory="${LUCZOR_REDIS_MAXMEMORY:-192mb}"

fail() {
  printf '%s\n' "Redis startup failed: $1" >&2
  exit 1
}

[ -r "$secret_file" ] || fail 'redis_password secret is missing or unreadable.'
[ "$(awk 'END { print NR }' "$secret_file")" -eq 1 ] \
  || fail 'redis_password must contain exactly one line.'
LC_ALL=C grep -Eq '^[A-Za-z0-9+/_=.-]{32,}$' "$secret_file" \
  || fail 'redis_password must contain at least 32 safe base64/base64url characters.'
printf '%s' "$maxmemory" | LC_ALL=C grep -Eq '^[1-9][0-9]*(k|kb|m|mb|g|gb)$' \
  || fail 'LUCZOR_REDIS_MAXMEMORY must be a positive Redis memory value such as 192mb.'

password="$(cat "$secret_file")"

install -d -m 0700 -o redis -g redis "$runtime_directory"
umask 0077
{
  printf '%s\n' \
    'bind 0.0.0.0' \
    'protected-mode yes' \
    'port 6379' \
    'daemonize no' \
    'logfile ""' \
    'dir /data' \
    'appendonly yes' \
    'appendfsync everysec' \
    "maxmemory $maxmemory" \
    'maxmemory-policy noeviction'
  printf 'requirepass %s\n' "$password"
} > "$config_file"
chown redis:redis "$config_file"

# The official image normally performs this ownership repair before dropping
# privileges. This wrapper keeps that behaviour while ensuring the password is
# never passed to redis-server as a process argument.
find /data \! -user redis -exec chown redis:redis '{}' +

unset password
exec gosu redis redis-server "$config_file"
