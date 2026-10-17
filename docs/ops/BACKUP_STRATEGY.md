# Backup Strategy

> **Note:** this file was deleted by commit `830bffa` ("Delete docs/ops
> directory") and reconstructed here from the code that implements and
> references it (`app/Console/Commands/RunBackup.php`, `config/backup.php`,
> `app/Console/Kernel.php`), since no `.git` history was available in the
> environment this was rebuilt in to `git revert` the original. If the
> original file differs from this reconstruction, prefer the original.

## What gets backed up

Nightly, at **02:00** (`app/Console/Kernel.php`, `backup:run`, before the
08:00 hearing-reminder job so a slow dump never contends with it):

1. A full logical `mysqldump` of the database, gzip-compressed.
2. A `tar.gz` archive of `storage/app` — documents not yet migrated to S3
   (see FUN-5 in the migration plan).

Both are written to `config('backup.disk')` (env `BACKUP_DISK`), **never**
the same disk the database or app server lives on in production — set
`BACKUP_DISK=s3` (or another off-server disk) for any production deploy.
Locally this defaults to the `backups` disk, which is `storage/backups`, a
sibling of `storage/app` so the file-backup step never has to exclude its
own output.

## Multi-tenant deployments: per-firm snapshots and restores

In multi-tenant mode every firm is snapshotted on its own, database and files together, nightly. A firm can be restored to a snapshot, or (MySQL/MariaDB with the binary log) to any moment, without touching other firms. A weekly drill proves it works and logs how long it took: docs/ops/FIRM_RESTORE.md (TEN-14).

## Retention

`backup:run` prunes backups older than `BACKUP_RETENTION_DAYS` (default 30,
`config('backup.retention_days')`). This is a floor, not a full policy —
trust/financial records may need a longer, firm-specific retention answer
for compliance; that decision is still owed and isn't encoded in the code.

## Why shell out instead of a package

`RunBackup` shells out to `mysqldump`/`tar` rather than adding
`spatie/laravel-backup` or similar — those binaries are already required
wherever MySQL itself is required, so nothing extra needs installing on
the production host.

## Verifying it works: the restore drill (CARRY-7)

`php artisan backup:drill` restores the newest `backup:run` pair (or `--backup=<Y-m-d_His>`) **beside** the live install
and checks it, without touching live data:

1. **fetch** the dump and the storage archive from the backup disk;
2. **restore_database** into a scratch database `drill_<time>` on the same server (`gunzip | mysql`);
3. **restore_files** into `storage/framework/drills/<id>/files`;
4. **verify**:
   - every table of the dump, with its row count; `migrations`, `users`, `clients`, `cases`, `documents` present;
     at least one person who can sign in;
   - the application's own code reads it: its migration history against the migrations this release ships
     (`pending_migrations` > 0 just means the backup is older than the code, which `app:upgrade` handles;
     an unknown migration fails the drill) and its models with relations (users, matters with clients, invoices with lines);
   - every document row on the local disk has its file in the archive (`documents_missing` must be 0; S3 documents are
     counted as `documents_elsewhere`: they are protected by bucket versioning, not by this archive);
5. **cleanup**: the scratch database and directory are removed (`--keep` leaves them to inspect).

Each phase is timed. The report (JSON) is kept on the backup disk under `drills/`, the result is logged, and a failure
is logged at `critical`. The scheduler runs it **monthly (1st, 05:00)**; that is the recurring calendar entry.

### Drill record

| Date | Where | Backup | Data | Result | Restore time |
|---|---|---|---|---|---|
| 2026-10-08 | dev box (4 vCPU, MariaDB 10.11), local backup disk | `database-2026-10-08_135040` + `storage-app-…` | 86 tables, 260,545 rows (20,000 users, matters, clients, documents), 40 MB of files | passed: 0 documents missing, 0 pending migrations, app reads data | 8.6 s total (database 7.8 s, files 0.4 s, checks 0.2 s) |

**Still owed:** the same drill on production backups (a production-sized dump, the real off-server backup disk) with the
time recorded here; then the monthly scheduled runs keep the record current. Multi-tenant firms are drilled weekly per
firm by `tenants:restore-drill` (docs/ops/FIRM_RESTORE.md).

## Failure handling

The scheduled run logs a `critical` log entry on failure
(`Kernel::schedule()`'s `onFailure` callback) — wire this to whatever
alerting the ops team uses (email, Slack, PagerDuty); nothing does that
automatically today.
