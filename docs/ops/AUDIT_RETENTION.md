# Audit trail: online window, archives and retention (DATA-11)

Every change to a record writes a row to `audits` (owen-it/laravel-auditing). Left alone, that table becomes the
largest in every firm's database, so it is managed by month.

## Online window (plan entitlement)

How many months stay in the live table, where the app can show them (matter activity, audit views), is the plan
limit `audit_online_months` in `config/entitlements.php`:

| Plan | Months online |
|---|---|
| trial | 3 |
| starter | 12 |
| professional | 36 |
| enterprise | 84 |
| self_hosted, unlicensed | unlimited (nothing is archived) |

A firm can get a different value through the usual per-firm override (`tenants.entitlements`). The current month is
always online. Every plan must state the limit (a missing limit counts as 0, which would archive everything); a test
checks this.

## Archiving (`audits:archive`, monthly on the 1st at 03:30)

For each firm, every month older than its window is moved out of the table:

1. The month's rows are written, every column as stored, to `tenants/<firm>/audit-archive/<YYYY-MM>.jsonl.gz` on
   `AUDIT_ARCHIVE_DISK` (default: the default disk).
2. The file is read back and its SHA-256 and row count checked. If they don't match, the file is deleted and nothing
   is removed.
3. The archive is recorded in `audit_archives` (month, rows, id range, SHA-256, size).
4. The rows are removed: by dropping the month's partition when the table is partitioned and the partition holds
   exactly the archived rows, otherwise in batches by id. Only rows with an id inside the archived range are ever
   removed, so a row that arrives late with an old timestamp is kept.

A run that stops half-way is finished by the next one. `--dry-run` lists what is due; `--tenant=<slug>` limits it to
one firm.

To put a month back (an investigation, a court order): `php artisan audits:restore <YYYY-MM> [--tenant=<slug>]`. The
file is checked against its SHA-256 first. The next monthly run archives those rows again if the month is still
outside the window.

## Partitions (MySQL / MariaDB)

`php artisan audits:partition --enable [--tenant=<slug>]` rebuilds `audits` with one RANGE partition per month, plus
two months ahead and a catch-all. Two schema changes come with it:

- `created_at` becomes NOT NULL;
- the primary key becomes `(id, created_at)`, because MySQL requires the partition column in every unique key.

The rebuild rewrites the whole table, so run it in a maintenance window, firm by firm. After that, `audits:archive`
keeps the next partitions ready and archives a month by dropping its partition, which is instant. Without partitions
(SQLite, or before you enable them) archiving deletes in batches.

## Retention of the archives

Archive files are kept for `max(AUDIT_ARCHIVE_YEARS, AUDIT_RETENTION_FLOOR_YEARS)` years after their month (defaults:
10 and 7), then deleted by the same monthly run, which also records `pruned_at`.

- **Floor:** `AUDIT_RETENTION_FLOOR_YEARS` is the professional-rules floor; configuring a shorter `AUDIT_ARCHIVE_YEARS`
  does not go below it. Set the floor to what your regulator requires. Seven years is common for legal records, but
  this is not legal advice.
- **Legal hold:** while the firm has any active legal hold (SEC-13), no archive is deleted.

## Limits

- An eject bundle (PLAT-9) and a firm's final export (TEN-9) contain the online audit rows only. Restore archived
  months first if they must travel. Purging a firm deletes its archive files with the rest of its prefix.
- Restoring and verifying read the whole month file into memory. That is fine for months of typical size; split very
  large firms' archiving more often if a month grows beyond a few hundred MB.
