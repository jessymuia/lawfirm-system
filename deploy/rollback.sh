#!/usr/bin/env bash
# PLAT-6: roll the server back to the previous release (or a named one) - a symlink flip, no rebuild.
#   DEPLOY_BASE=/var/www/lawfirm deploy/rollback.sh [release-name]
# Run from the server, or from CI via: deploy/deploy.sh <env> --rollback [release-name]
exec "$(dirname "$0")/switch-release.sh" rollback "$@"
