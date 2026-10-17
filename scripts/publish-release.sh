#!/usr/bin/env bash
# PLAT-5: publish a release by hand when the hosted pipeline (.github/workflows/release.yml) cannot run, for example when
# Actions minutes are used up. Builds and pushes the container image, packages the Helm chart and builds the bare-metal bundle,
# exactly as the pipeline does, from this machine.
#
#   export REGISTRY_USER=<your github user>  REGISTRY_TOKEN=<a token with write:packages>
#   scripts/publish-release.sh ghcr.io/<owner>/<repo>            # publish VERSION as :<version> and :latest
#   scripts/publish-release.sh ghcr.io/<owner>/<repo> --dry-run  # build and package only, push nothing
#
# Needs: docker (with buildx), helm, php, and a clean checkout of the tagged commit (the tag v<VERSION> must exist and point at HEAD).
# The token is read from the environment and piped to `docker login`; it is never written to disk or printed.
set -euo pipefail

IMAGE="${1:?usage: scripts/publish-release.sh <registry/owner/repo> [--dry-run]}"
DRY=0; [ "${2:-}" = "--dry-run" ] && DRY=1
ROOT="${ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$ROOT"

VERSION="$(tr -d '[:space:]' < VERSION)"
SEMVER='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$'
[[ "$VERSION" =~ $SEMVER ]] || { echo "VERSION '$VERSION' is not a semantic version." >&2; exit 1; }
case "$IMAGE" in *[A-Z]*|*" "*) echo "Image names must be lower case with no spaces: $IMAGE" >&2; exit 1 ;; esac

for tool in docker helm php; do
    command -v "$tool" >/dev/null || { echo "$tool is required." >&2; exit 1; }
done

# Publish only what was tagged: the image must be the code the tag names.
if [ "${PUBLISH_NO_GIT:-0}" != "1" ]; then
    [ -z "$(git status --porcelain)" ] || { echo "Working tree is not clean." >&2; exit 1; }
    TAGGED="$(git rev-parse -q --verify "refs/tags/v$VERSION^{commit}" || true)"
    [ -n "$TAGGED" ] || { echo "Tag v$VERSION does not exist. Run scripts/release.sh first." >&2; exit 1; }
    [ "$TAGGED" = "$(git rev-parse HEAD)" ] || { echo "HEAD is not the commit tagged v$VERSION." >&2; exit 1; }
fi

# A pre-release (1.2.0-rc.1) is never tagged :latest.
TAGS=(-t "$IMAGE:$VERSION")
case "$VERSION" in *-*) : ;; *) TAGS+=(-t "$IMAGE:latest") ;; esac

if [ "$DRY" = 0 ]; then
    : "${REGISTRY_USER:?set REGISTRY_USER}" "${REGISTRY_TOKEN:?set REGISTRY_TOKEN}"
    printf '%s' "$REGISTRY_TOKEN" | docker login "${IMAGE%%/*}" -u "$REGISTRY_USER" --password-stdin
fi

echo "== image $IMAGE:$VERSION"
if [ "$DRY" = 1 ]; then
    docker build -f Dockerfile "${TAGS[@]}" .
else
    docker buildx build -f Dockerfile "${TAGS[@]}" --push .
fi

echo "== helm chart"
mkdir -p dist
helm package deploy/helm/lawfirm --destination dist

echo "== bundle"
scripts/build-release-bundle.sh dist

echo
ls -1 dist
if [ "$DRY" = 1 ]; then
    echo "Dry run: nothing was pushed."
else
    echo "Pushed $IMAGE:$VERSION. Attach dist/* to the GitHub release for v$VERSION (gh release create v$VERSION dist/* --notes-file <notes>)."
fi
