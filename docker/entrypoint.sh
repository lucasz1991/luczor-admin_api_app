#!/bin/sh
set -eu

read_secret() {
  file="/run/secrets/$1"
  if [ -r "$file" ]; then
    cat "$file"
  fi
}

export APP_KEY="$(read_secret app_key)"
export DB_PASSWORD="$(read_secret postgres_password)"
export REDIS_PASSWORD="$(read_secret redis_password)"
export OPENROUTER_API_KEY="$(read_secret openrouter_key)"
export GITHUB_CLIENT_SECRET="$(read_secret github_client_secret)"
export GITHUB_WEBHOOK_SECRET="$(read_secret github_webhook_secret)"
export REVERB_APP_SECRET="$(read_secret reverb_app_secret)"
export PUSHER_APP_SECRET="$REVERB_APP_SECRET"

exec "$@"
