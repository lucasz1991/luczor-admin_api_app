#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
app_root=$(CDPATH= cd -- "$script_dir/.." && pwd)
workspace_root=$(CDPATH= cd -- "$script_dir/../.." && pwd)
environment_file=${1:-"$workspace_root/.env.docker"}
compose_file=${2:-}
if [ -z "$compose_file" ]; then
    if [ -f "$workspace_root/docker-compose.yml" ]; then
        compose_file="$workspace_root/docker-compose.yml"
    else
        compose_file="$app_root/docker-compose.plesk-memory.yml"
    fi
fi
secret_directory="$script_dir/secrets"
api_key_file="$secret_directory/cognee_api_key"
default_password_file="$secret_directory/cognee_default_password"

for required_file in "$compose_file" "$api_key_file" "$default_password_file"; do
    if [ ! -f "$required_file" ]; then
        echo "Required Cognee provisioning file is missing: $required_file" >&2
        exit 1
    fi
done

compose_exec() {
    if [ -f "$environment_file" ]; then
        docker compose --env-file "$environment_file" -f "$compose_file" "$@"
    else
        docker compose -f "$compose_file" "$@"
    fi
}

if [ -s "$api_key_file" ] && [ "${LUCZOR_FORCE_COGNEE_KEY_ROTATION:-false}" != "true" ]; then
    echo "Cognee service API key is already provisioned. Set LUCZOR_FORCE_COGNEE_KEY_ROTATION=true only for an intentional rotation."
    exit 0
fi

provision_output=$(
    compose_exec exec -T cognee /usr/local/bin/python - <<'PYTHON'
import json
import urllib.error
import urllib.parse
import urllib.request

base_url = "http://127.0.0.1:8000"
email = "cognee-service@luczor.follow-flow.de"
with open("/run/secrets/cognee_default_password", encoding="utf-8") as handle:
    password = handle.read().strip()
if not password:
    raise SystemExit("Cognee default password secret is empty")

def send(path, *, body=None, content_type="application/json", token=None, api_key=None):
    headers = {"Accept": "application/json", "Content-Type": content_type}
    if token:
        headers["Authorization"] = "Bearer " + token
    if api_key:
        headers["Authorization"] = "Bearer " + api_key
        headers["X-Api-Key"] = api_key
    method = "POST" if body is not None else "GET"
    request = urllib.request.Request(base_url + path, data=body, headers=headers, method=method)
    with urllib.request.urlopen(request, timeout=15) as response:
        return json.loads(response.read().decode("utf-8") or "{}")

def login():
    form = urllib.parse.urlencode({"username": email, "password": password}).encode()
    result = send("/api/v1/auth/login", body=form, content_type="application/x-www-form-urlencoded")
    token = result.get("access_token") or result.get("token")
    if not isinstance(token, str) or not token:
        raise RuntimeError("Cognee login returned no bearer token")
    return token

try:
    bearer = login()
except urllib.error.HTTPError as login_error:
    if login_error.code not in (400, 401, 404):
        raise
    registration = json.dumps({"email": email, "password": password}).encode()
    try:
        send("/api/v1/auth/register", body=registration)
    except urllib.error.HTTPError as register_error:
        if register_error.code not in (400, 409):
            raise
    bearer = login()

created = send(
    "/api/v1/auth/api-keys",
    body=json.dumps({"name": "luczor-laravel"}).encode(),
    token=bearer,
)

def raw_api_key(value):
    if isinstance(value, dict):
        for name in ("api_key", "key", "token"):
            candidate = value.get(name)
            if isinstance(candidate, str) and candidate:
                return candidate
        for nested in value.values():
            candidate = raw_api_key(nested)
            if candidate:
                return candidate
    return None

api_key = raw_api_key(created)
if not api_key or len(api_key) < 20 or any(character.isspace() for character in api_key):
    raise SystemExit("Cognee returned no valid raw API key")

send("/api/v1/users/me", api_key=api_key)
print("LUCZOR_COGNEE_KEY=" + api_key)
PYTHON
)

marker=$(printf '%s\n' "$provision_output" | awk '/^LUCZOR_COGNEE_KEY=/{line=$0} END{print line}')
case "$marker" in
    LUCZOR_COGNEE_KEY=*) api_key=${marker#LUCZOR_COGNEE_KEY=} ;;
    *) echo "Cognee provisioning returned no validated API key. The existing secret was not changed." >&2; exit 1 ;;
esac

temporary_file=$(mktemp "$secret_directory/.cognee_api_key.XXXXXX")
trap 'rm -f "$temporary_file"' EXIT HUP INT TERM
umask 077
printf '%s' "$api_key" > "$temporary_file"
chmod 600 "$temporary_file"
mv -f "$temporary_file" "$api_key_file"
trap - EXIT HUP INT TERM

echo "Cognee service API key was validated and stored. Restart Laravel/Horizon to load it."
