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

# Keep the directory root-owned so the reduced-capability entrypoint can safely
# recreate the generated config after a container restart. Redis receives only
# group traversal on the directory and read access to the config file itself.
install -d "$runtime_directory"
chown root:redis "$runtime_directory"
chmod 0710 "$runtime_directory"
rm -f "$config_file"
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
chmod 0400 "$config_file"
chown redis:redis "$config_file"

# The official image normally performs this ownership repair before dropping
# privileges. Once the bind-mount root belongs to Redis, a later container
# restart deliberately skips the recursive walk: root has no DAC override in
# this hardened container, while the Redis process can still access its data.
redis_uid="$(id -u redis)"
data_uid="$(stat -c '%u' /data)"
if [ "$data_uid" != "$redis_uid" ]; then
  find /data \! -user redis -exec chown redis:redis '{}' +
fi

unset password
exec /usr/bin/setpriv --reuid redis --regid redis --clear-groups redis-server "$config_file"
