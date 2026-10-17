#!/usr/bin/env bash
# PLAT-5: build the bare-metal release bundle (no Docker): lawfirm-<version>.tar.gz + .sha256
#   scripts/build-release-bundle.sh [outdir]            full build (npm ci + composer install --no-dev)
#   --skip-vendor / --skip-assets                       leave those out (used by tests)
# The bundle contains built assets and production vendor/, and none of the vendor-only tools or tests
# (deploy/dist-excludes.txt).
set -euo pipefail

SKIP_VENDOR=0; SKIP_ASSETS=0; OUT=""
for a in "$@"; do
    case "$a" in
        --skip-vendor) SKIP_VENDOR=1 ;;
        --skip-assets) SKIP_ASSETS=1 ;;
        *) OUT="$a" ;;
    esac
done
ROOT="${ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
OUT="${OUT:-$ROOT/dist}"
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION")"
NAME="lawfirm-$VERSION"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$NAME" "$OUT"
tar -C "$ROOT" \
    --exclude=./.git --exclude=./node_modules --exclude=./vendor --exclude=./dist \
    --exclude=./.env --exclude='./.env.*' --exclude=./storage/logs --exclude=./storage/app \
    --exclude=./storage/framework/cache --exclude=./storage/framework/sessions --exclude=./storage/framework/views \
    --exclude=./public/hot --exclude=./public/storage --exclude=./.phpunit.result.cache --exclude='./*.zip' \
    -cf - . | tar -C "$STAGE/$NAME" -xf -
cp "$ROOT/.env.example" "$STAGE/$NAME/.env.example"

if [ "$SKIP_ASSETS" = 0 ]; then
    ( cd "$ROOT" && npm ci --no-audit --no-fund && npm run build )
    mkdir -p "$STAGE/$NAME/public"
    cp -r "$ROOT/public/build" "$STAGE/$NAME/public/"
fi
if [ "$SKIP_VENDOR" = 0 ]; then
    ( cd "$STAGE/$NAME" && composer install --no-dev --optimize-autoloader --no-scripts --prefer-dist --no-interaction )
fi

# Vendor-only tools and test code out, before anything is archived.
sh "$ROOT/scripts/prune-distribution.sh" "$STAGE/$NAME" "$ROOT/deploy/dist-excludes.txt"

mkdir -p "$STAGE/$NAME/storage/logs" "$STAGE/$NAME/storage/app" "$STAGE/$NAME/storage/framework/"{cache,sessions,views} "$STAGE/$NAME/bootstrap/cache"
tar -C "$STAGE" -czf "$OUT/$NAME.tar.gz" "$NAME"
( cd "$OUT" && sha256sum "$NAME.tar.gz" > "$NAME.tar.gz.sha256" )
echo "Built $OUT/$NAME.tar.gz"
