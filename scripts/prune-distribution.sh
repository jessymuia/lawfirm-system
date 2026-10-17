#!/usr/bin/env sh
# PLAT-5: delete everything listed in deploy/dist-excludes.txt from a directory (a build context or staging dir).
# Usage: scripts/prune-distribution.sh <dir> [excludes-file]
set -eu
DIR="${1:?usage: prune-distribution.sh <dir> [excludes-file]}"
LIST="${2:-$DIR/deploy/dist-excludes.txt}"
[ -f "$LIST" ] || { echo "excludes file not found: $LIST" >&2; exit 1; }
while IFS= read -r line; do
    case "$line" in ''|\#*) continue ;; esac
    # Refuse anything that could escape the directory.
    case "$line" in /*|*..*) echo "unsafe path in excludes: $line" >&2; exit 1 ;; esac
    rm -rf "${DIR:?}/$line"
done < "$LIST"
