#!/usr/bin/env bash
# PLAT-6: atomic release switching on a bare-metal/VM server. Replaces `git fetch && git reset --hard`:
# the live code is never modified in place; a new directory is prepared beside it and a symlink is flipped.
#
#   switch-release.sh init <legacy-app-dir>     one-time: create the layout, COPY .env + storage from the old install
#   switch-release.sh deploy <bundle.tar.gz>    prepare, upgrade, flip, health-check; auto-rollback if unhealthy
#   switch-release.sh rollback [release-name]   flip back to the previous (or a named) release
#   switch-release.sh list                      show releases, marking current/previous
#
# Layout under DEPLOY_BASE:
#   releases/<version>-<UTC timestamp>/   immutable code; .env and storage are symlinks into shared/
#   shared/.env  shared/storage/          the only mutable state (never replaced by a deploy)
#   current -> releases/...               what the web server and workers point at
#
# Environment (all optional except DEPLOY_BASE):
#   DEPLOY_BASE   absolute path of the layout root
#   KEEP          releases to retain, default 5 (current and previous are never pruned)
#   PHP           php binary, default php
#   UPGRADE_CMD   run inside the NEW release BEFORE the flip; default "$PHP artisan app:upgrade"
#   RELOAD_CMD    run after every flip (e.g. "sudo systemctl reload php8.2-fpm"); needed when opcache.validate_timestamps=0
#   QUEUE_CMD     run inside current after every flip; default "$PHP artisan queue:restart"
#   HEALTH_CMD    run after the flip; non-zero => automatic rollback (e.g. "curl -fsS http://127.0.0.1/api/v1/health")
#
# Why running app:upgrade before the flip is safe: migrations are expand/contract (docs/MIGRATION_POLICY.md), so the
# release still serving traffic keeps working against the upgraded schema. That same property makes rollback a pure code switch.
set -euo pipefail

: "${DEPLOY_BASE:?DEPLOY_BASE is required}"
BASE="${DEPLOY_BASE%/}"
KEEP="${KEEP:-5}"
PHP="${PHP:-php}"
UPGRADE_CMD="${UPGRADE_CMD:-$PHP artisan app:upgrade}"
QUEUE_CMD="${QUEUE_CMD:-$PHP artisan queue:restart}"
RELOAD_CMD="${RELOAD_CMD:-}"
HEALTH_CMD="${HEALTH_CMD:-}"
REL="$BASE/releases"
SHARED="$BASE/shared"

say() { echo "==> $*"; }
die() { echo "ERROR: $*" >&2; exit 1; }

# Release names are <version>-<UTC stamp>; order by the stamp, newest first (directory mtimes come from the tarball, not the deploy).
releases_newest_first() {
    local d
    for d in "$REL"/*/; do
        [ -d "$d" ] || continue
        d="$(basename "$d")"
        echo "${d##*-} $d"
    done | sort -r | cut -d' ' -f2-
}

current_name() { [ -L "$BASE/current" ] && basename "$(readlink "$BASE/current")" || true; }
previous_name() { [ -f "$BASE/.previous" ] && cat "$BASE/.previous" || true; }

# Atomic: build the new link beside the old one, then rename over it (rename(2) is atomic; ln -sfn alone is not).
flip_to() {
    local name="$1"
    [ -d "$REL/$name" ] || die "no such release: $name"
    ln -sfn "releases/$name" "$BASE/current.tmp"
    mv -T "$BASE/current.tmp" "$BASE/current"
}

after_flip() {
    if [ -n "$RELOAD_CMD" ]; then say "Reloading PHP-FPM"; bash -c "$RELOAD_CMD"; fi
    say "Restarting queue workers (graceful)"
    ( cd "$BASE/current" && bash -c "$QUEUE_CMD" ) || echo "WARNING: queue restart failed; restart workers manually" >&2
}

cmd_init() {
    local legacy="${1:?usage: init <legacy-app-dir>}"
    [ -f "$legacy/.env" ] || die "$legacy/.env not found"
    [ -e "$SHARED/.env" ] && die "$SHARED/.env already exists; refusing to overwrite"
    mkdir -p "$REL" "$SHARED"
    cp -p "$legacy/.env" "$SHARED/.env"
    chmod 600 "$SHARED/.env"
    if [ -d "$legacy/storage" ]; then cp -a "$legacy/storage" "$SHARED/storage"; else mkdir -p "$SHARED/storage"; fi
    mkdir -p "$SHARED/storage/logs" "$SHARED/storage/app/public" "$SHARED/storage/framework/"{cache,sessions,views}
    say "Layout ready at $BASE (the old install at $legacy was only read, not modified)."
    say "Next: deploy a bundle, then point the web server root at $BASE/current/public."
}

cmd_deploy() {
    local bundle="${1:?usage: deploy <bundle.tar.gz>}"
    [ -f "$bundle" ] || die "bundle not found: $bundle"
    [ -f "$SHARED/.env" ] || die "$SHARED/.env missing; run 'init' first"
    [ -d "$SHARED/storage" ] || die "$SHARED/storage missing; run 'init' first"

    if [ -f "$bundle.sha256" ]; then
        say "Verifying checksum"
        ( cd "$(dirname "$bundle")" && sha256sum -c "$(basename "$bundle").sha256" >/dev/null ) || die "checksum mismatch; refusing to deploy"
    else
        echo "WARNING: no $bundle.sha256 next to the bundle; integrity not verified" >&2
    fi

    local top name stamp
    top="$(tar -tzf "$bundle" | sed -n 1p | cut -d/ -f1)"   # sed reads to EOF: `head -1` closes the pipe early and tar dies of SIGPIPE under pipefail
    [ -n "$top" ] || die "empty bundle"
    stamp="$(date -u +%Y%m%d%H%M%S%3N)"          # GNU date: millisecond precision so same-second deploys sort correctly
    case "$stamp" in *[!0-9]*) stamp="$(date -u +%Y%m%d%H%M%S)" ;; esac
    name="${top#lawfirm-}-$stamp"
    [ -e "$REL/$name" ] && die "release $name already exists"

    mkdir -p "$REL"
    local tmp="$REL/.incoming-$stamp"
    rm -rf "$tmp"; mkdir -p "$tmp"
    say "Unpacking $top as release $name"
    tar -xzf "$bundle" -C "$tmp"
    mv "$tmp/$top" "$REL/$name"
    rmdir "$tmp"

    local dir="$REL/$name"
    rm -rf "$dir/storage"
    ln -s "$SHARED/storage" "$dir/storage"
    ln -s "$SHARED/.env" "$dir/.env"
    mkdir -p "$dir/bootstrap/cache"

    say "Running upgrade in the new release (live traffic is untouched)"
    if ! ( cd "$dir" && bash -c "$UPGRADE_CMD" ); then
        rm -rf "$dir"
        die "upgrade failed; the new release was discarded and 'current' was not changed"
    fi

    local prev
    prev="$(current_name)"
    say "Switching current -> $name"
    flip_to "$name"
    [ -n "$prev" ] && echo "$prev" > "$BASE/.previous"
    after_flip

    if [ -n "$HEALTH_CMD" ]; then
        say "Health check"
        if ! bash -c "$HEALTH_CMD"; then
            echo "Health check FAILED" >&2
            if [ -n "$prev" ] && [ -d "$REL/$prev" ]; then
                say "Rolling back to $prev"
                flip_to "$prev"
                echo "$name" > "$BASE/.previous"
                after_flip
                die "release $name was unhealthy and has been rolled back to $prev (kept at $REL/$name for inspection)"
            fi
            die "release $name is unhealthy and there is no previous release to roll back to"
        fi
    fi

    prune "$name" "$prev"
    say "Deployed $name"
}

prune() {
    local keep_a="$1" keep_b="${2:-}" n=0 d name
    # newest first; skip dot-dirs (.incoming-*)
    while IFS= read -r name; do
        d="$REL/$name"
        n=$((n + 1))
        [ "$n" -le "$KEEP" ] && continue
        if [ "$name" = "$keep_a" ] || [ "$name" = "$keep_b" ]; then continue; fi
        rm -rf "$d"
        say "Pruned old release $name"
    done < <(releases_newest_first)
}

cmd_rollback() {
    local target="${1:-}" cur
    cur="$(current_name)"
    [ -n "$target" ] || target="$(previous_name)"
    [ -n "$target" ] || die "no previous release recorded; name one explicitly (see 'list')"
    [ "$target" != "$cur" ] || die "$target is already current"
    [ -d "$REL/$target" ] || die "no such release: $target"
    say "Rolling back $cur -> $target (code only; the schema stays as is, which expand/contract keeps compatible)"
    flip_to "$target"
    [ -n "$cur" ] && echo "$cur" > "$BASE/.previous"
    after_flip
    if [ -n "$HEALTH_CMD" ] && ! bash -c "$HEALTH_CMD"; then
        die "rolled back to $target but the health check still fails; see docs/ops/UPGRADE_ROLLBACK.md (restore from backup)"
    fi
    say "Now serving $target"
}

cmd_list() {
    local cur prev name
    cur="$(current_name)"; prev="$(previous_name)"
    while IFS= read -r name; do
        mark=""
        [ "$name" = "$cur" ] && mark=" (current)"
        [ "$name" = "$prev" ] && mark=" (previous)"
        echo "$name$mark"
    done < <(releases_newest_first)
}

case "${1:-}" in
    init)     shift; cmd_init "$@" ;;
    deploy)   shift; cmd_deploy "$@" ;;
    rollback) shift; cmd_rollback "$@" ;;
    list)     cmd_list ;;
    *)        sed -n '2,12p' "$0"; exit 2 ;;
esac
