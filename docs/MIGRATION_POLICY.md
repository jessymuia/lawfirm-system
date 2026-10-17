# Migration policy: expand / contract (DATA-13)

The SaaS deploys continuously; self-hosted installs lag by months. So **every release's schema must
keep working for the previous release's code**, and no migration may hold a big tenant's tables locked.

## The five steps, across a release boundary

| Release | Step | Allowed in a migration? |
|---|---|---|
| N | **Add**: new nullable column / new table / new index | yes |
| N | **Backfill**: fill the new column for existing rows | **no, queued job** (below) |
| N | **Dual-write**: code writes old and new | (code) |
| N+1 | **Switch reads** to the new column | (code) |
| N+2 | **Drop** the old column/table | yes, with an explicit `allow` and a reason |

Rename = add new, dual-write, switch reads, drop old. Never `renameColumn`.
Changing a column type = add a new column of the new type, then the same steps. Never `->change()`.

## Backfills are queued jobs, not migrations
A migration runs once per tenant, in a deploy, holding locks. A backfill must be chunked, resumable
and throttled, so it runs as a queued job (tenant-aware: the worker restores the tenant) that
processes `chunkById(500)` and can be re-run safely. A migration may only do small, bounded data
fixes, and then says so with an `allow data-rewrite` comment.

## The CI gate
`php artisan migrations:lint` (workflow: `.github/workflows/migration-lint.yml`) fails on, anywhere in a
migration except `down()`:

`drop-column`, `drop-table`, `rename`, `change-type`, `drop-constraint`, `data-rewrite`, `raw-alter`.

Accept a finding deliberately, per rule, with a reason of 10+ characters:

```php
// migration-lint: allow drop-column -- unread since release 4.2, contract step of ticket #123
```

An `allow` with no reason, or for an unknown rule, is itself a failure and does not suppress anything.

### Baseline
Migrations that predate the gate are listed in `database/migrations/lint-baseline.txt` (10 at the time
of writing, including the `090300` dedup). Never add to it; the test suite checks every entry still exists.

## Not yet done
- "Test migrations against the largest tenant's data volume": needs a synthetic large-tenant fixture and a
  CI job with a real MySQL (see ENG-27). Until then, treat any new migration touching `audit`, `documents`,
  `invoices` or `payments` as needing a manual timing run on a production-sized copy.

## Schema drift detection (TEN-8)

`php artisan schema:drift [--tenant=slug]` (scheduled daily at 04:30) compares every firm's database with the schema its
**own applied migrations** produce on a blank database of the same engine. A firm a release behind is compared with its
own version, never with the newest. It reports, per firm:

- tables, columns (type and nullability), indexes and foreign keys that are missing, extra or different;
- missing or extra triggers (the DATA-10 ledger guards are part of the schema);
- migrations recorded in the firm's `migrations` table that do not exist in the code.

Differences are logged as errors (`Schema drift detected`, with the list), stored on the tenant row (`schema_drift`,
`drift_checked_at`) and listed under `schema_drift` in the platform console's `GET /api/platform/fleet`; the command exits
non-zero. On a single-firm install it checks the one database. Fix drift before the next upgrade: restore the expected
definition (the message says what it should be) or, for a deliberate local change, upstream it as a migration.

The reference is built in a scratch database (`schema_ref_*` on the tenant server, or a temporary SQLite file) by a child
process and dropped immediately; its fingerprint is cached in `storage/framework/schema/` per engine and exact migration
set (names and file contents), so a fleet on one version builds it once. The database user needs `CREATE`/`DROP DATABASE`,
which tenant provisioning already requires.

## Rehearsal at volume (DATA-13)

The linter catches the operations that are dangerous by kind. A rehearsal measures what a migration actually costs at the volume it will meet. `php artisan migrations:rehearse` runs migrations on a scratch copy, times each one, and fails when any single migration takes longer than `--budget` seconds (default 60). The scratch database is always dropped.

| Where | Command | Against |
| --- | --- | --- |
| CI (`backend-mysql`) | `migrations:rehearse --synthetic=20000 --last=15 --budget=60` | the newest 15 migrations, on MySQL, after filling a scratch database with 20,000 rows in each growing table (`App\Database\VolumeFiller`, about 260,000 rows). The rows are valid but not realistic: real parents, enum values, distinct values where an index is unique. |
| Before a SaaS fleet upgrade | `migrations:rehearse --largest` (or `--tenant=<slug>`), run from the **new** release | the new release's pending migrations, on a copy of the largest firm's latest snapshot (TEN-14, docs/ops/FIRM_RESTORE.md): its real data volume and shape |

**When a migration is over budget:**

- move its data work into a queued backfill (above);
- or split it into smaller steps;
- or, when the cost is a table rebuild, schedule a maintenance window and say so in the upgrade notes.

Do not raise the budget to make a rehearsal pass.

The firm rehearsal reads the snapshot, never the live database. It needs the same database rights as a restore (create and drop a scratch database).
