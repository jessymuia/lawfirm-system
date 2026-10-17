#!/usr/bin/env bash
# PLAT-5: cut a semver release. Updates VERSION, the Helm chart and CHANGELOG, then commits and tags v<version>.
#   scripts/release.sh 1.2.0            (normal)
#   scripts/release.sh 1.3.0-rc.1       (pre-release: published as a pre-release, never tagged "latest")
# Pushing the tag (git push origin main v1.2.0) triggers .github/workflows/release.yml, which builds and publishes.
# Env: ROOT=<dir> operate on another tree; RELEASE_NO_GIT=1 only edit files (used by the tests).
set -euo pipefail

NEW="${1:?usage: scripts/release.sh <MAJOR.MINOR.PATCH[-prerelease]>}"
ROOT="${ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$ROOT"

SEMVER='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$'
if ! [[ "$NEW" =~ $SEMVER ]]; then
    echo "'$NEW' is not a valid semantic version (expected e.g. 1.2.3 or 1.2.3-rc.1, no leading 'v')." >&2
    exit 1
fi

CURRENT="$(tr -d '[:space:]' < VERSION)"
if [ "$(php -r 'echo version_compare($argv[1], $argv[2], ">") ? "ok" : "no";' "$NEW" "$CURRENT")" != "ok" ]; then
    echo "New version $NEW must be greater than the current $CURRENT." >&2
    exit 1
fi

if [ "${RELEASE_NO_GIT:-0}" != "1" ]; then
    [ -z "$(git status --porcelain)" ] || { echo "Working tree is not clean; commit or stash first." >&2; exit 1; }
    git rev-parse -q --verify "refs/tags/v$NEW" >/dev/null && { echo "Tag v$NEW already exists." >&2; exit 1; }
fi

grep -q '^## \[Unreleased\]' CHANGELOG.md || { echo "CHANGELOG.md has no '## [Unreleased]' section." >&2; exit 1; }
# Refuse an empty release: something must be listed under [Unreleased].
BODY="$(awk '/^## \[Unreleased\]/{f=1;next} /^## \[/{f=0} f' CHANGELOG.md | grep -v '^[[:space:]]*$' || true)"
PROMOTES=0
if [ -z "$BODY" ]; then
    # Promoting 1.2.0-rc.N to 1.2.0 needs no new notes: the rc's changes are what ships.
    case "$CURRENT" in "$NEW"-*) PROMOTES=1 ;; esac
    [ "$PROMOTES" = 1 ] || { echo "Nothing is listed under [Unreleased] in CHANGELOG.md; describe the release first." >&2; exit 1; }
fi

echo "$NEW" > VERSION
sed -i -E "s/^version: .*/version: $NEW/; s/^appVersion: .*/appVersion: \"$NEW\"/" deploy/helm/lawfirm/Chart.yaml
sed -i -E "s/^## \[Unreleased\]/## [Unreleased]\n\n## [$NEW] - $(date -u +%Y-%m-%d)/" CHANGELOG.md
if [ "$PROMOTES" = 1 ]; then
    sed -i -E "s/^(## \[$NEW\] - .*)$/\1\n- Final release of $CURRENT (no changes since the release candidate)./" CHANGELOG.md
fi
sed -i -E "s/^LAWFIRM_VERSION=.*/LAWFIRM_VERSION=$NEW/" deploy/selfhost/.env.example

# PLAT-10: the guide written for the unreleased changes now belongs to this release; then gate the result.
GUIDE_MOVED=0
if [ -f docs/upgrade/unreleased.md ]; then mv docs/upgrade/unreleased.md "docs/upgrade/$NEW.md"; GUIDE_MOVED=1; fi
if command -v php >/dev/null 2>&1 && [ -f artisan ] && ! php artisan release:check; then
    echo "release:check failed; reverting the release edits." >&2
    [ "$GUIDE_MOVED" = 1 ] && mv "docs/upgrade/$NEW.md" docs/upgrade/unreleased.md
    echo "$CURRENT" > VERSION
    if [ "${RELEASE_NO_GIT:-0}" != "1" ]; then git checkout -- CHANGELOG.md deploy/helm/lawfirm/Chart.yaml deploy/selfhost/.env.example; fi
    exit 1
fi

if [ "${RELEASE_NO_GIT:-0}" != "1" ]; then
    git add VERSION CHANGELOG.md docs/upgrade deploy/helm/lawfirm/Chart.yaml deploy/selfhost/.env.example
    git commit -m "Release $NEW"
    git tag -a "v$NEW" -m "Release $NEW"
    echo "Tagged v$NEW. Publish with: git push origin HEAD v$NEW"
else
    echo "Updated files for $NEW (no git)."
fi
