# Legacy cutover: rehearsal and runbook (MIG-6, CARRY-5)

The legacy production system is this application's own schema as it stood before the 2026 rebuild (every migration
dated before 2026), with its data coded the old way. The cutover upgrades **a copy** of that database with the same
command every upgrade uses, `php artisan app:upgrade`, and then points the new application at it. Nothing is
transformed by hand or by a separate import, so the rehearsal exercises exactly the code the cutover runs.

> The older `migration:export` / `migration:import` JSON path (§6.3 of the migration plan) copies rows without applying
> the status mapping or computing invoice totals; do not use it for the cutover. To move the upgraded firm into a
> multi-tenant deployment afterwards, use `portability:export` / `portability:import` (docs/PORTABILITY.md).

## Before: sign off the mapping

`php artisan migration:status-report --connection=source --signoff=…` against production, signed
(docs/ops/MIGRATION_STATUS_MAPPING.md). The upgrade refuses a database with unmapped values.

## Rehearsal (on staging, as many times as needed)

1. Take a dump of production as the cutover will (`mysqldump --single-transaction --routines`, gzip is fine) and copy
   the old `storage/app` (the documents) to staging.
2. On staging, with the new release deployed:
   ```
   php artisan migration:rehearse-cutover --dump=/data/prod.sql.gz --files=/data/legacy/storage/app
   ```
   (or `--from=source` to copy straight from a database). It never touches the source. It:

   | Phase | What it does | Stops the rehearsal when |
   |---|---|---|
   | restore | loads the dump into a scratch database `cutover_<time>` | the dump cannot be loaded |
   | preflight | values vs the mapping; orphaned references; duplicate case numbers / client phones (the upgrade renames them `-DUP1`…); invoice lines whose total is not quantity × rate + tax; document rows without a file | a value is not mapped |
   | upgrade | `app:upgrade` on the copy, backfills run inline | the upgrade fails |
   | verify | rows per table unchanged (catalog tables may only grow); invoice states exactly as the mapping says; money to the cent (lines, expenses); every invoice's totals match its lines; password hashes unchanged; the app reads its data; no pending migrations | any check fails |

   The report (JSON, on the backup disk under `cutover-rehearsals/`, or `--report=`) lists every warning, the timings
   and the slowest migrations. `--keep` leaves the upgraded copy to open with the new app; `--accept-unmapped` goes
   past unmapped values to see the rest (rehearsal only).
3. Fix what it finds **in the legacy data** (or in the mapping), take a new dump, rehearse again, until it passes with
   no warning you have not accepted in writing.
4. Open the kept copy with the new application on staging and have users check their own matters, invoices and
   documents (a sample per person), then sign the rehearsal off.

No production copy yet? `php artisan migration:legacy-fixture practice_db --matters=5000 [--problems]` builds a
practice database shaped like the legacy system (refuses a database that has tables), to learn the procedure on.

## Cutover day

| Step | Command / action | Time (from the rehearsal) |
|---|---|---|
| 1. Announce, then freeze the old system | read-only (or down) | — |
| 2. Final dump and documents copy | `mysqldump --single-transaction --routines legacy | gzip > final.sql.gz`; `rsync` storage/app | dump: measure on staging |
| 3. Restore on the new database server | `gunzip -c final.sql.gz | mysql new_db` | rehearsal `restore` |
| 4. Mapping gate | `php artisan migration:status-report` (exit 0) | seconds |
| 5. Upgrade | `php artisan app:upgrade --backup --maintenance` | rehearsal `migrations_seconds` |
| 6. Verify | the rehearsal's checks: `php artisan test --testsuite=Migration` with `DB_SOURCE_*` = the final dump restored read-only | rehearsal `verify` |
| 7. Switch DNS / proxy to the new system; people sign in again (their passwords carry over) | | |
| 8. Background backfills finish while people work (public ids, conflicts register, document text, matter types, FX) | queue workers running | rehearsal `backfills_seconds` |

Rollback: docs/ops/MIGRATION_ROLLBACK_PLAN.md (the old system stays read-only, never deleted, for the agreed window).

## Rehearsal record

| Date | Data | Result | Restore | Schema migrations (downtime) | Backfills (after reopening) | Findings |
|---|---|---|---|---|---|---|
| 2026-10-08 | practice database, 60 matters with planted problems (MariaDB 10.11, dev box) | stopped at preflight as intended; with `--accept-unmapped`: passed | 0.2 s | 3 s | — | caught all of: `invoices.status = 2` and `clients.status = 0` unmapped (blocking), a duplicate case number (renamed `-DUP1`), an invoice line that does not add up, a document without its file |
| 2026-10-08 | practice database, 5,000 matters, ~65,000 rows, clean | passed | 1.0 s | 11.4 s (invoice status/number backfill 6 s, document versioning 3 s) | 44 s | none; invoice states 2,501 submitted / 833 draft / 1,666 void exactly as mapped |
| _to do_ | **staging, copy of production** | | | | | |

The two slow migrations update row by row, so they grow linearly: about 1.2 ms per invoice and 0.6 ms per document on
this hardware. Measure on staging with the real volume before fixing the window.
