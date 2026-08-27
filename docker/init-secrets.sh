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
write_if_missing internal_service_key "$(openssl rand -base64 36 | tr -d '\n')"
write_if_missing cognee_api_key ""
write_if_missing cognee_postgres_password "$(openssl rand -hex 32 | tr -d '\n')"
write_if_missing cognee_llm_api_key ""
write_if_missing cognee_embedding_api_key ""
write_if_missing cognee_jwt_secret "$(openssl rand -base64 48 | tr -d '\n')"
write_if_missing cognee_default_password "$(openssl rand -base64 36 | tr -d '\n')"
write_if_missing cognee_verification_secret "$(openssl rand -base64 48 | tr -d '\n')"
write_if_missing cognee_reset_secret "$(openssl rand -base64 48 | tr -d '\n')"

if [ ! -f "$dir/job_private_key" ]; then
  openssl genrsa -out "$dir/job_private_key" 3072
fi

chmod 600 "$dir"/*

printf '%s\n' "Secrets are ready in $dir. Optional external integrations remain disabled until their own keys are configured."
