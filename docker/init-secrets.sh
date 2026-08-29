#!/bin/sh
set -eu

umask 077

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
dir=${LUCZOR_DOCKER_SECRETS_DIR:-"$script_dir/secrets"}

case "$dir" in
  /*) ;;
  *)
    echo "LUCZOR_DOCKER_SECRETS_DIR must be an absolute path when it is set." >&2
    exit 1
    ;;
esac

if [ -L "$dir" ] || { [ -e "$dir" ] && [ ! -d "$dir" ]; }; then
  echo "Secret directory must be a real directory, not a link: $dir" >&2
  exit 1
fi

mkdir -p -- "$dir"
chmod 700 "$dir"

temporary_file=
cleanup_temporary_file() {
  if [ -n "$temporary_file" ]; then
    rm -f -- "$temporary_file"
  fi
}
trap cleanup_temporary_file EXIT HUP INT TERM

assert_safe_secret_file() {
  file=$1
  if [ -L "$file" ] || { [ -e "$file" ] && [ ! -f "$file" ]; }; then
    echo "Secret target must be a regular file, not a link: $file" >&2
    exit 1
  fi
}

install_new_secret() {
  name=$1
  source_file=$2
  file="$dir/$name"

  assert_safe_secret_file "$file"
  if [ -f "$file" ]; then
    chmod 600 "$file"
    return
  fi

  # A hard-link create is atomic and fails instead of replacing a target that
  # appeared after the check above. The temporary inode never leaves this
  # mode-0700 directory.
  if ln "$source_file" "$file" 2>/dev/null; then
    chmod 600 "$file"
    return
  fi

  assert_safe_secret_file "$file"
  if [ -f "$file" ]; then
    chmod 600 "$file"
    return
  fi

  echo "Unable to create secret without replacing an existing target: $file" >&2
  exit 1
}

write_if_missing() {
  name=$1
  value=$2
  file="$dir/$name"

  assert_safe_secret_file "$file"
  if [ -f "$file" ]; then
    chmod 600 "$file"
    return
  fi

  temporary_file=$(mktemp "$dir/.$name.XXXXXX")
  printf '%s' "$value" > "$temporary_file"
  chmod 600 "$temporary_file"
  install_new_secret "$name" "$temporary_file"
  rm -f -- "$temporary_file"
  temporary_file=
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

job_key="$dir/job_private_key"
assert_safe_secret_file "$job_key"
if [ ! -f "$job_key" ]; then
  temporary_file=$(mktemp "$dir/.job_private_key.XXXXXX")
  openssl genrsa -out "$temporary_file" 3072 2>/dev/null
  chmod 600 "$temporary_file"
  install_new_secret job_private_key "$temporary_file"
  rm -f -- "$temporary_file"
  temporary_file=
else
  chmod 600 "$job_key"
fi

trap - EXIT HUP INT TERM
printf '%s\n' "Secrets are ready in $dir. Optional external integrations remain disabled until their own keys are configured."
