#!/usr/bin/env bash
set -euo pipefail

# ENG-6 / PLAT-6: CI-side deploy to a plain PHP-FPM + Supervisor server (docs/ops/DEPLOYMENT.md).
# Builds one release bundle, uploads it, and runs deploy/switch-release.sh on the server, which unpacks it NEXT to the
# live release, runs `app:upgrade`, flips the `current` symlink atomically and health-checks. Nothing in the live
# directory is modified in place (there is no `git reset --hard`), and a bad release rolls itself back.
#
#   deploy.sh <staging|production>                       deploy the current checkout
#   deploy.sh <staging|production> --rollback [release]  switch back to the previous (or a named) release
#
# Required environment (GitHub Actions secrets/vars per environment, never hardcoded):
#   DEPLOY_SSH_KEY  private key for the target server
#   DEPLOY_HOST     user@host
#   DEPLOY_PATH     absolute path of the layout root on the server (holds releases/, shared/, current)
# Optional (forwarded to the server side): KEEP, PHP, UPGRADE_CMD, RELOAD_CMD, QUEUE_CMD, HEALTH_CMD
#
# One-time server setup: docs/ops/UPGRADE_ROLLBACK.md ("Converting an existing git-checkout server").
# .env and storage live in $DEPLOY_PATH/shared and never pass through CI.

TARGET_ENV="${1:?Usage: deploy.sh <staging|production> [--rollback [release]]}"
shift || true

if [[ "$TARGET_ENV" != "staging" && "$TARGET_ENV" != "production" ]]; then
    echo "Unknown target environment: $TARGET_ENV (expected 'staging' or 'production')" >&2
    exit 1
fi

: "${DEPLOY_SSH_KEY:?DEPLOY_SSH_KEY is required}"
: "${DEPLOY_HOST:?DEPLOY_HOST is required}"
: "${DEPLOY_PATH:?DEPLOY_PATH is required}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SSH_KEY_FILE="$(mktemp)"
trap 'rm -f "$SSH_KEY_FILE"' EXIT
echo "$DEPLOY_SSH_KEY" > "$SSH_KEY_FILE"
chmod 600 "$SSH_KEY_FILE"
SSH_OPTS=(-i "$SSH_KEY_FILE" -o StrictHostKeyChecking=accept-new)

# Environment forwarded to the server-side script (quoted with printf %q; values never touch a shell unquoted).
remote_env() {
    local out="DEPLOY_BASE=$(printf %q "$DEPLOY_PATH")" v
    for v in KEEP PHP UPGRADE_CMD RELOAD_CMD QUEUE_CMD HEALTH_CMD; do
        if [ -n "${!v:-}" ]; then out+=" $v=$(printf %q "${!v}")"; fi
    done
    echo "$out"
}

# Pipe the script from THIS checkout over ssh so the server needs no pre-installed copy and always runs the matching version.
run_remote() {
    ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "$(remote_env) bash -s -- $*" < "$ROOT/deploy/switch-release.sh"
}

if [ "${1:-}" = "--rollback" ]; then
    echo "==> Rolling back $TARGET_ENV ($DEPLOY_HOST:$DEPLOY_PATH)"
    run_remote rollback ${2:+"$(printf %q "$2")"}
    exit 0
fi

echo "==> Deploying to $TARGET_ENV ($DEPLOY_HOST:$DEPLOY_PATH)"

echo "==> Building the release bundle (frontend assets + production vendor/, built here, not on the server)"
OUT="$(mktemp -d)"
"$ROOT/scripts/build-release-bundle.sh" "$OUT"
BUNDLE="$(ls "$OUT"/lawfirm-*.tar.gz)"
NAME="$(basename "$BUNDLE")"

echo "==> Uploading $NAME"
ssh "${SSH_OPTS[@]}" "$DEPLOY_HOST" "mkdir -p $(printf %q "$DEPLOY_PATH")/incoming"
rsync -az -e "ssh ${SSH_OPTS[*]}" "$BUNDLE" "$BUNDLE.sha256" "$DEPLOY_HOST:$DEPLOY_PATH/incoming/"

echo "==> Switching release on the server"
run_remote deploy "$(printf %q "$DEPLOY_PATH/incoming/$NAME")"

echo "==> Deploy to $TARGET_ENV complete"
