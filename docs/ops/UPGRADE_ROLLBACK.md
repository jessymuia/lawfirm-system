# Upgrade, release switching and rollback (PLAT-6)

## `php artisan app:upgrade`

The single command that brings an installation up to the release the code is. It is **idempotent**: running it twice is safe and
the second run changes nothing.

| Step | What | Notes |
|------|------|-------|
| 1 | Preflight | PHP ≥ 8.2, required extensions, `APP_KEY`, writable `storage/` + `bootstrap/cache`, database reachable |
| 2 | Downgrade guard | Refuses if the recorded release is **more than one minor** newer than this code (see Rollback). Same-minor/previous-minor → warning only |
| 3 | Pending list | Lists unapplied migrations (control plane in multi mode) |
| 4 | `--backup` | Runs `backup:run` first; a failed backup stops the upgrade before anything changes |
| 5 | `--maintenance` | `down --retry=60` during migration. **Always lifted**, including when the upgrade fails |
| 5b | Rehearse (SaaS) | before a release with schema changes, run `php artisan migrations:rehearse --largest` from the new release: its pending migrations, timed on a copy of the largest firm (docs/MIGRATION_POLICY.md, "Rehearsal at volume"). Stop if one is over budget |
| 6 | Migrate | single mode: `migrate`. multi mode: control plane (`--database=control`), then `tenants:migrate` (per-tenant, failure-isolated), or `tenants:migrate --queue` to let workers do it in parallel and `tenants:migrate --resume --queue` to pick up what is left; watch with `tenants:status` |
| 7 | Catalog sync | Permissions + built-in roles; `admin` is re-synced to **every** permission (single mode; tenants get theirs at provisioning) |
| 8 | Caches | `config:cache route:cache view:cache event:cache` (skip with `--skip-cache`) |
| 9 | `queue:restart` | Graceful: workers finish the current job, then the supervisor restarts them on the new code |
| 10 | Record | One row in `app_releases` (version, previous_version, applied_at). Control DB in multi mode, app DB otherwise |

`--dry-run` stops after step 3 and changes nothing (it does not even create the release table).

On failure it prints the failing step, the rollback instructions, exits 1, and does **not** record the release. Fix the cause and run it
again; migrations already applied stay applied and the rest resume.

`app:install` (first install, containers, Helm hook) now calls `app:upgrade --skip-cache`, so first install and every upgrade take one path.

## Atomic release switching (bare metal / VM)

`deploy/deploy.sh` no longer touches the live directory (the old `git fetch && git reset --hard` is gone). Layout on the server:

```
$DEPLOY_PATH/
  releases/<version>-<UTC stamp>/   immutable code; .env and storage are symlinks into shared/
  shared/.env  shared/storage/      the only mutable state; a deploy never replaces it
  current -> releases/...           web root = current/public; workers run from current
  incoming/                         uploaded bundles
```

`deploy.sh <env>` builds one bundle in CI, uploads it, and pipes `deploy/switch-release.sh` to the server, which:

1. verifies the `.sha256` (refuses a mismatch),
2. unpacks into a **new** release directory beside the live one and links `shared/`,
3. runs `app:upgrade` **inside the new release, before the flip**. If it fails the new directory is deleted and `current` is untouched,
4. flips `current` atomically (new symlink + `mv -T`, a single `rename(2)`; readers never see it missing),
5. runs `RELOAD_CMD` (PHP-FPM reload, required when `opcache.validate_timestamps=0`) and `queue:restart`,
6. runs `HEALTH_CMD` if set; if it fails the previous release is switched back automatically (the bad one is kept for inspection),
7. prunes to the newest `KEEP` (default 5) releases, never removing current or previous.

Running `app:upgrade` before the flip is safe because migrations are expand/contract (`docs/MIGRATION_POLICY.md`): the release still serving
traffic works against the upgraded schema.

Settings (env vars, forwarded to the server): `KEEP`, `PHP`, `UPGRADE_CMD` (default `php artisan app:upgrade`; add `--backup` if you want a
backup per deploy), `RELOAD_CMD`, `QUEUE_CMD`, `HEALTH_CMD`, e.g. `HEALTH_CMD='curl -fsS http://127.0.0.1/api/v1/health'`.

Web server: point the root at `$DEPLOY_PATH/current/public` and, for nginx, use `fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;`
so PHP-FPM resolves the symlink per request rather than caching the old release. Supervisor's queue program runs
`php $DEPLOY_PATH/current/artisan queue:work --queue=default,bulk --timeout=3700` (it is restarted onto the new code by `queue:restart`).

### Converting an existing git-checkout server (one time)

1. On the server (copy `deploy/switch-release.sh` there, it ships in every bundle):
   `DEPLOY_BASE=/var/www/lawfirm-new bash switch-release.sh init /var/www/lawfirm`. It **copies** `.env` and `storage/` into
   `shared/`; the old install is only read and keeps serving.
2. Set `DEPLOY_PATH=/var/www/lawfirm-new` in the GitHub environment and run the deploy once.
3. Switch the web root and the Supervisor program to `/var/www/lawfirm-new/current`, reload the web server and supervisor.
4. After a confidence period, remove the old directory.

## Rollback

### Why it is a code switch
Migrations are forward-only, and every schema change follows expand/contract, so **the previous minor release works against the newer schema**.
Rolling back therefore means running older code, not reversing the database.

### Bare metal
```
deploy/deploy.sh production --rollback            # previous release
deploy/deploy.sh production --rollback <name>     # a specific release (see: switch-release.sh list)
# or on the server:  DEPLOY_BASE=... deploy/rollback.sh [name]
```
It flips `current`, reloads PHP-FPM, restarts workers, runs `HEALTH_CMD`. Running it again rolls forward again.

### Containers
Redeploy the previous image tag (`LAWFIRM_VERSION` for compose, `--version` for Helm). The one-shot migrate step runs `app:install` →
`app:upgrade`, which accepts the previous minor (with a warning) and refuses anything older.

### Further than one minor, or after a destructive migration
`app:upgrade` refuses (`--allow-downgrade` overrides at your own risk). Restore the database backup taken before the upgrade
(`php artisan backup:run --backup` before every upgrade, `docs/ops/BACKUP_STRATEGY.md`) and redeploy the matching release. Data entered after the
backup is lost, which is why the backup must come first.

## Checklist for a release
- [ ] `CHANGELOG.md` has the notes; destructive migrations are called out.
- [ ] A backup exists and a restore has been tested.
- [ ] `php artisan app:upgrade --dry-run` on staging lists what you expect.
- [ ] Deploy to staging, smoke suite, then production.
