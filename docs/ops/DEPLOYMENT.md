# Deployment

## Model

A plain PHP-FPM server with a **Supervisor-managed queue worker** — not a
containerized platform. `deploy/deploy.sh <staging|production>` is called
by `.github/workflows/deploy.yml` for both environments (only the target
differs); see §5.2 of `docs/SYSTEM_REVIEW_AND_MIGRATION_PLAN.md` for the
gating pipeline (CI → staging → smoke suite → manual-approval production
gate).

## What `deploy.sh` does, in order

Atomic release switching (PLAT-6). Full detail, settings, one-time conversion and rollback: **`docs/ops/UPGRADE_ROLLBACK.md`**.

1. Builds one release bundle **in CI** (frontend assets + production `vendor/`, `scripts/build-release-bundle.sh`); the server builds nothing.
2. Uploads the bundle and its `.sha256` to `$DEPLOY_PATH/incoming/`.
3. Runs `deploy/switch-release.sh` on the server (piped over ssh): verify checksum, unpack into a **new** `releases/<version>-<stamp>/`
   next to the live one, link `shared/.env` and `shared/storage`.
4. Runs `php artisan app:upgrade` in the new release **before** the switch (migrate, role catalog, caches, `queue:restart`). Failure discards the
   new release and leaves `current` untouched.
5. Flips the `current` symlink atomically, reloads PHP-FPM, gracefully restarts the queue workers (`queue:restart`; deliberately not a hard
   `supervisorctl restart`, which would kill an in-flight job such as a half-sent SMS).
6. Health check; on failure it switches back to the previous release by itself.
7. Prunes old releases (keeps 5; current and previous are never removed).

Rollback: `deploy/deploy.sh <env> --rollback [release]`.

## Required secrets/vars (per GitHub environment: `staging`, `production`)

| Name | Purpose |
|------|---------|
| `DEPLOY_SSH_KEY` | Private key with access to the target server |
| `DEPLOY_HOST` | `user@host` for the target server |
| `DEPLOY_PATH` | Absolute path of the layout root on the server (`releases/`, `shared/`, `current`) |
| `HEALTH_CMD`, `RELOAD_CMD`, … (optional) | See `docs/ops/UPGRADE_ROLLBACK.md` |

The script never sends `.env` through CI: it lives in `$DEPLOY_PATH/shared/.env` on the server; only the SSH key passes through CI.

## Prerequisites on the target server

- The release layout created once with `switch-release.sh init <old-install>` (copies `.env` and `storage/`, see UPGRADE_ROLLBACK.md).
- `php-fpm` with the web root at `$DEPLOY_PATH/current/public` (nginx: `$realpath_root`), MySQL access, and a Supervisor program running
  `php $DEPLOY_PATH/current/artisan queue:work --queue=default,bulk --timeout=3700` so `queue:restart` has a worker to restart (lanes: docs/ops/QUEUES.md).
- The deploying user can write to `$DEPLOY_PATH` and reload PHP-FPM (`RELOAD_CMD`, e.g. via a sudoers rule for that single command).

## Production gate

Per `.github/workflows/deploy.yml`, `deploy-production` only runs after
`smoke-staging` passes, and the `production` GitHub environment requires a
manual approval — nothing reaches production without both a green smoke
run against staging and a human sign-off.
