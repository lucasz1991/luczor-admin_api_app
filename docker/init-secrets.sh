#!/bin/sh
set -eu

dir="$(dirname "$0")/secrets"
mkdir -p "$dir"

write_if_missing() {
  file="$dir/$1"
  [ -f "$file" ] || printf '%s' "$2" > "$file"
}

write_if_missing app_key "base64:$(openssl rand -base64 32 | tr -d '\n')"
write_if_missing postgres_password "$(openssl rand -base64 36 | tr -d '\n')"
write_if_missing redis_password "$(openssl rand -base64 36 | tr -d '\n')"
write_if_missing openrouter_key ""
write_if_missing github_client_secret ""
write_if_missing github_webhook_secret "$(openssl rand -base64 36 | tr -d '\n')"
write_if_missing reverb_app_secret "$(openssl rand -base64 36 | tr -d '\n')"

if [ ! -f "$dir/job_private_key" ]; then
  openssl genrsa -out "$dir/job_private_key" 3072
fi

chmod 600 "$dir"/*
