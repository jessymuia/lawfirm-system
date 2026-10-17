# Restoring one firm, and proving it works (TEN-14)

Multi-tenant (SaaS) deployments only. A self-hosted install has one firm and uses `backup:run` (docs/ops/BACKUP_STRATEGY.md).

**The goal:** "Restore this one firm to 14:00 yesterday without touching anyone else" is a runbook, not a project.

## Snapshots: the database and the files as a pair

`php artisan tenants:snapshot --all` runs nightly at 01:45 (`tenants:snapshot <slug>` for one firm). For each firm it writes the following to the backup disk (`BACKUP_DISK`), under `tenants/<slug>/snapshots/<id>/`:

| File | What |
| --- | --- |
| `database.sql.gz` | the firm database: `mysqldump --single-transaction` on MySQL/MariaDB, or a consistent `VACUUM INTO` copy on SQLite |
| `files-<disk>.zip` | the firm's files on each **local** disk (everything under `tenants/<slug>/`) |
| `manifest.json` | when it was taken; the binary-log position the dump is consistent at; rows per table; how many stored files the database points at |

A firm with its own data key (TEN-13) gets every file encrypted with that key (`.enc`), so purging the firm shreds its snapshots too.

**Files on S3 are not copied.** Turn on **bucket versioning**: a restore then takes each file as it was at the target moment from its object versions. Keep noncurrent versions at least as long as you keep snapshots (a lifecycle rule).

## Point in time (MySQL/MariaDB)

A snapshot records where in the binary log it is consistent. A restore to a later moment replays the firm database's own events from there, read from the server with `mysqlbinlog --read-from-remote-server` and rewritten into the restored copy.

**Requirements:**

- **The binary log on.** It is on by default in MySQL 8. On MariaDB set `log_bin`. Use `binlog_format = ROW`.
- **Log retention.** Keep the log at least as long as the point-in-time window you promise (`binlog_expire_logs_seconds`), e.g. 7 days.
- **Account privileges.** Besides access to the firm databases, the database account needs:
  - `RELOAD` (or `FLUSH_TABLES`) and `REPLICATION CLIENT`, for the dump's coordinates;
  - `REPLICATION SLAVE`, to read the log;
  - `CREATE` and `DROP`, for scratch databases.
- **Client tools.** `mysqldump`, `mysqlbinlog`, `mysql` and `bash` must be on the machine that runs the commands. They are in the Docker image.

SQLite keeps no binary log, so restores there are to a snapshot only.

## Restoring

```
php artisan tenants:snapshot acme --list                      # what there is to restore from
php artisan tenants:restore acme --to="2026-10-07 14:00"       # a rehearsal: restore next to the live database, report, drop
php artisan tenants:restore acme --to="2026-10-07 14:00" --keep          # keep the restored copy, to inspect it
php artisan tenants:restore acme --to="2026-10-07 14:00" --switch --confirm=acme   # the real thing
```

### What a restore does

1. **Loads a snapshot.** It takes the latest snapshot at or before `--to`, or the one named with `--snapshot=`, and loads it into a **new** database next to the live one (`<database>_r<timestamp>`). Nothing else on the server is touched, and no other firm.
2. **Rolls forward.** If `--to` is after the snapshot, it replays the firm's binary-log events up to `--to`. It refuses when the server or the snapshot has no binary log. It also refuses when the snapshot is of a database the firm no longer runs on, after an earlier switch.
3. **Checks the pair.** For every file the restored database points at (`App\Backup\FileReferences`: documents, filed emails, templates, audit archives, the firm logo), the file must:
   - exist now; or
   - be recoverable as it was at the target: from the snapshot's archive for local disks, or from the object version at or before the target on S3.

   Anything else is listed as **missing**, and the restore is **inconsistent**. A database restored to yesterday that points at files deleted today is an incident, not a restore.

### What `--switch` does

`--switch` works only on a consistent restore, and needs the slug repeated in `--confirm`. It:

1. suspends the firm;
2. puts the missing files back;
3. points the firm at the restored database;
4. resumes the firm;
5. takes a fresh snapshot, so point-in-time restores keep working from the new database.

**The previous database is kept**, and its name is recorded. To roll back, point the firm back at it. Drop it once the firm has confirmed the restore.

### The record

Every restore is recorded in the control plane's `tenant_restores` table and the platform audit log (`tenant.restore.*`). The record holds the snapshot, the target, the databases, the file report and the duration.

## Drills

`php artisan tenants:restore-drill` runs every Sunday at 04:00, and on demand with `--tenant=<slug>`. It drills the active firm whose last drill is oldest, so every firm is drilled in turn. A drill:

1. **Restores.** It restores the firm's latest snapshot next to the live database, rolled forward to now when the binary log allows (`point-in-time` mode; `snapshot` mode otherwise).
2. **Checks.**
   - Every table is present.
   - In snapshot mode, the row counts equal the snapshot's manifest.
   - Every referenced file exists or is recoverable.
   - The app reads the firm from the restored copy.
3. **Drops** the copy.
4. **Records** the outcome and the time it took in `tenant_restores` (kind `drill`). `--list` shows the recent drills.

A failed drill, or a failed snapshot, logs at **critical**; route those log entries to your alerting.

**The drill's duration is your measured restore time.** Quote that, not a guess, when a firm asks how long a restore takes.

**Not automated:** restoring onto a different server or region after losing the primary. That is a whole-cell disaster-recovery exercise (docs/ops/CELLS.md, docs/ops/BACKUP_STRATEGY.md), not a per-firm restore.
