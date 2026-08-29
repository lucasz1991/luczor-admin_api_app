#!/usr/bin/env bash
set -Eeuo pipefail

target=${1:?Usage: repair-plesk-git-permissions.sh TARGET BARE_REPOSITORY DEPLOY_USER EXPECTED_ORIGIN}
bare_repository=${2:?Usage: repair-plesk-git-permissions.sh TARGET BARE_REPOSITORY DEPLOY_USER EXPECTED_ORIGIN}
deploy_user=${3:?Usage: repair-plesk-git-permissions.sh TARGET BARE_REPOSITORY DEPLOY_USER EXPECTED_ORIGIN}
expected_origin=${4:?Usage: repair-plesk-git-permissions.sh TARGET BARE_REPOSITORY DEPLOY_USER EXPECTED_ORIGIN}
deploy_group=${LUCZOR_PLESK_DEPLOY_GROUP:-psacln}
public_group=${LUCZOR_PLESK_PUBLIC_GROUP:-psaserv}
backup_root=${LUCZOR_PLESK_PERMISSION_BACKUP_DIR:-/var/backups/luczor}
ref=${LUCZOR_PLESK_DEPLOY_REF:-refs/heads/master}

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

[[ ${EUID} -eq 0 ]] || fail 'Must run as root.'
for command_name in git getfacl setfacl runuser realpath sort; do
    command -v "$command_name" >/dev/null || fail "Required command is missing: $command_name"
done

[[ -d "$target" && ! -L "$target" ]] || fail 'Deployment target is missing or is a link.'
[[ -d "$bare_repository" && ! -L "$bare_repository" ]] || fail 'Bare repository is missing or is a link.'
target=$(realpath -e -- "$target")
bare_repository=$(realpath -e -- "$bare_repository")

getent passwd "$deploy_user" >/dev/null || fail 'Deployment user is missing.'
getent group "$deploy_group" >/dev/null || fail "Deployment group is missing: $deploy_group"
getent group "$public_group" >/dev/null || fail "Public group is missing: $public_group"

[[ "$(git --git-dir="$bare_repository" rev-parse --is-bare-repository)" == true ]] \
    || fail 'Repository is not bare.'
[[ "$(git --git-dir="$bare_repository" remote get-url origin)" == "$expected_origin" ]] \
    || fail 'Bare repository origin does not match the expected repository.'

commit=$(git --git-dir="$bare_repository" rev-parse --verify "${ref}^{commit}")
work=$(mktemp -d /tmp/luczor-ownership.XXXXXX)
trap 'rm -rf -- "$work"' EXIT

git --git-dir="$bare_repository" ls-tree -r -z --name-only "$commit" > "$work/tracked.z"
: > "$work/forbidden"

while IFS= read -r -d '' relative_path; do
    case "$relative_path" in
        ''|/*|../*|*/../*|*/..)
            printf '%q\n' "$relative_path" >> "$work/forbidden"
            continue
            ;;
    esac

    case "$relative_path" in
        .env.example|\
        storage/app/.gitignore|\
        storage/app/public/.gitignore|\
        storage/framework/.gitignore|\
        storage/framework/cache/.gitignore|\
        storage/framework/sessions/.gitignore|\
        storage/framework/testing/.gitignore|\
        storage/logs/.gitignore)
            ;;
        .env|.env.*|\
        vendor|vendor/*|\
        node_modules|node_modules/*|\
        docker/secrets|docker/secrets/*|\
        docker/data|docker/data/*|\
        docker/volumes|docker/volumes/*|\
        storage/*)
            printf '%q\n' "$relative_path" >> "$work/forbidden"
            ;;
    esac
done < "$work/tracked.z"

if [[ -s "$work/forbidden" ]]; then
    printf 'Protected or runtime paths are still tracked:\n' >&2
    sed 's/^/  /' "$work/forbidden" >&2
    exit 1
fi

{
    while IFS= read -r -d '' relative_path; do
        [[ "$relative_path" == */* ]] || continue
        parent=${relative_path%/*}
        while [[ -n "$parent" ]]; do
            printf '%s\0' "$parent"
            [[ "$parent" == */* ]] || break
            parent=${parent%/*}
        done
    done < "$work/tracked.z"
} | sort -zu > "$work/directories.z"

while IFS= read -r -d '' relative_path; do
    absolute_path="$target/$relative_path"
    [[ "$absolute_path" == "$target/"* ]] || fail "Path escaped the deployment target: $relative_path"
    [[ ! -L "$absolute_path" ]] || fail "Tracked parent is a link: $absolute_path"
    [[ ! -e "$absolute_path" || -d "$absolute_path" ]] || fail "Tracked parent has the wrong type: $absolute_path"
done < "$work/directories.z"

if [[ -L "$backup_root" || ( -e "$backup_root" && ! -d "$backup_root" ) ]]; then
    fail 'Permission backup root is not a real directory.'
fi
install -d -m 0700 -o root -g root "$backup_root"
[[ "$(stat -c '%U:%G:%a' "$backup_root")" == 'root:root:700' ]] \
    || fail 'Permission backup root is not exclusively owned by root.'
acl_backup=$(mktemp "$backup_root/permissions-before-git-repair-$(date -u +%Y%m%dT%H%M%SZ).XXXXXX.acl")
chmod 0600 "$acl_backup"

backup_acl() {
    if [[ -e "$1" || -L "$1" ]]; then
        getfacl -h -p -- "$1" >> "$acl_backup"
    fi
}

backup_acl "$target"
while IFS= read -r -d '' relative_path; do backup_acl "$target/$relative_path"; done < "$work/directories.z"
while IFS= read -r -d '' relative_path; do backup_acl "$target/$relative_path"; done < "$work/tracked.z"

# Plesk owns the additional-domain root and configured document root through
# the subscription user with psaserv; deployable content below uses psacln.
chown -h "$deploy_user:$public_group" "$target"
chmod 0750 "$target"

while IFS= read -r -d '' relative_path; do
    absolute_path="$target/$relative_path"
    if [[ -d "$absolute_path" && ! -L "$absolute_path" ]]; then
        chown -h "$deploy_user:$deploy_group" "$absolute_path"
        chmod 0755 "$absolute_path"
    fi
done < "$work/directories.z"

while IFS= read -r -d '' relative_path; do
    absolute_path="$target/$relative_path"
    if [[ -e "$absolute_path" || -L "$absolute_path" ]]; then
        chown -h "$deploy_user:$deploy_group" "$absolute_path"
        [[ -L "$absolute_path" ]] || chmod u+rw,go-w "$absolute_path"
    fi
done < "$work/tracked.z"

[[ -d "$target/public" && ! -L "$target/public" ]] || fail 'Public document root is missing or is a link.'
chown -h "$deploy_user:$public_group" "$target/public"
chmod 0750 "$target/public"

probe_directory() {
    runuser -u "$deploy_user" -- env LUCZOR_PROBE_DIR="$1" /bin/sh -c '
        umask 077
        probe="${LUCZOR_PROBE_DIR}/.luczor-permission-probe.$$"
        : > "$probe"
        rm -f -- "$probe"
    '
}

probe_directory "$target"
while IFS= read -r -d '' relative_path; do
    absolute_path="$target/$relative_path"
    if [[ -d "$absolute_path" && ! -L "$absolute_path" ]]; then
        runuser -u "$deploy_user" -- test -w "$absolute_path"
        runuser -u "$deploy_user" -- test -x "$absolute_path"
        probe_directory "$absolute_path"
    fi
done < "$work/directories.z"

[[ "$(stat -c '%U:%G:%a' "$target")" == "$deploy_user:$public_group:750" ]] \
    || fail 'Deployment target ownership is not the expected Plesk mode.'
[[ "$(stat -c '%U:%G:%a' "$target/public")" == "$deploy_user:$public_group:750" ]] \
    || fail 'Public document root ownership is not the expected Plesk mode.'

printf 'Git-managed ownership repaired for commit %s.\n' "$commit"
printf 'ACL rollback file: %s\n' "$acl_backup"
