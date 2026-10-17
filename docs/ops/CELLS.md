# Cells: placement, capacity and moving firms (TEN-4, TEN-15, TEN-16)

A **cell** is one database server (or one connection-proxy endpoint) that holds many firm databases. With
database-per-tenant the limit is **tables per server** (about 45 tables per firm), not connections: PHP-FPM opens one
connection per in-flight request whatever the number of firms. Capacity is added by adding cells, never by code.

## The registry

`cells` (control plane) holds, per cell: `host`, `port`, `username`, the **name** of its password variable
(`password_env`, always `CELL_PASSWORD_*`), `region`, `capacity` (firms) and `accepting`. Anything left empty falls back
to the tenant connection template (`TENANT_CONNECTION_TEMPLATE`). A firm's `tenants.cell` names its cell; firms in a cell
without a row (the original `default`) use the template unchanged, so a single-server deployment needs no rows at all.

```
php artisan cells list
php artisan cells add eu-1 --host=db-eu-1.internal --port=3306 --region=eu --capacity=400 --password-env=CELL_PASSWORD_EU1
php artisan cells update eu-1 --accepting=0      # stop placing new firms here (full, draining, maintenance)
```

Passwords are never stored in the database. Set `CELL_PASSWORD_EU1` in the environment (or `CELL_PASSWORD_EU1_FILE` with
the name added to `SECRET_FILE_ALLOW`), then run `php artisan config:cache`. The platform console lists cells with
their load at `GET /api/platform/cells`.

## Where a firm's files and background work live (TEN-2)

A firm's row in `tenants` describes all of it:

| What | Column | Comes from |
|---|---|---|
| database | `cell`, `database` | placement (below) |
| files | `files_disk` (keys under `tenants/<slug>/`) | the cell's `files_disk`, else the platform default (`s3` when a bucket is configured, else `local`) |
| background work | `queue` (a lane on the queue connection) | the cell's `queue`, else the connection's own queue |

```
php artisan cells add eu-1 --region=eu --files-disk=s3-eu --queue=eu   # firms placed here keep files in the EU bucket, jobs on the eu lane
```

While a firm is active, uploads, DSAR exports and audit archives go to its `files_disk`. Jobs dispatched without an
explicit queue go to its lane, so run workers per lane: `php artisan queue:work --queue=eu`, plus one on `default`.
In multi-tenant mode the `database` queue and the failed-job log live on the control plane (`jobs`, `failed_jobs`),
not in firm databases; `DB_QUEUE_CONNECTION` overrides that. Relocating a firm moves its lane to the target cell's,
but not its files: object storage is not per cell. Files on a different disk need their own copy. Values are recorded
at provisioning, so changing a cell later does not move firms already on it.

## Read replica (ENG-23)

`php artisan cells update eu-1 --replica-host=db-eu-1-ro [--replica-port=3306]` gives the firms on the cell a read
replica. Their reports and dashboard read their own database there, with the cell's credentials. `--replica-host=none`
removes it. The template-only `default` cell uses `DB_REPLICA_HOST`. See READ_REPLICA.md.

## Placement

`tenants:provision` (and the console) puts a new firm in the **least-utilised** cell (firms / capacity) that is
accepting and below capacity. `--cell=` forces one. **Region pinning (TEN-15):** a firm provisioned with a region is
only ever placed in, or moved to, a cell of that region; if none has room, provisioning fails with a clear message
rather than placing the firm elsewhere.

## Per-cell ceiling and when to add a cell

A firm database has 86 tables. The measured ceilings, the signals, and how to re-measure them are in
[CAPACITY.md](CAPACITY.md) (ENG-27). In short:

- **Capacity:** 200 firms per cell on an untuned or small server; 300 on 16 GB+ with `table_open_cache` and
  `table_definition_cache` at least firms × 20.
- **Table count:** stay below 25 000 tables per server.
- **Monitoring:** `php artisan capacity:report` reports every signal and exits 1 at 80 % of any ceiling.

## Connection proxy

Put ProxySQL, RDS Proxy or PgBouncer-equivalent in front of a cell when you run many PHP-FPM workers or queue workers:
point the cell's `host`/`port` at the proxy. The app needs no change.

### Measuring latency, with and without the proxy

PHP opens a new database connection per request, so the connect is paid on every request. `cells:latency` measures it:

```
php artisan cells:latency                                     # every cell's endpoint
php artisan cells:latency eu-1 --direct-host=10.0.3.7          # eu-1 through its proxy, and the server behind it
php artisan cells:latency --host=10.0.3.9 --port=6033          # an endpoint that is not a cell yet
php artisan cells:latency --max-p95=10                         # exit 1 if a request's database part exceeds 10 ms at p95
```

It reports connect, one query round trip and a request's database part (connect + 5 queries + disconnect) at
p50/p95/max over `--samples` (50), and with `--direct-host` the proxy's cost per request.

| Measured | Where | Endpoint | connect p50 / p95 | request p50 / p95 |
|---|---|---|---|---|
| 2026-10-08 | dev box, MariaDB 10.11, loopback | direct (127.0.0.1:3306) | 0.34 / 0.48 ms | 0.89 / 1.09 ms |
| 2026-10-08 | same | through a pass-through TCP proxy | 0.93 / 1.21 ms | 2.17 / 2.73 ms |

On loopback, an extra hop costs about **1.3 ms per request at p50 (1.6 ms at p95)**, mostly in the connect. That is the
floor of a proxy's cost; a real ProxySQL or RDS Proxy adds its own query routing, and network distance adds per round
trip. **Measure on the real cells before production** (same availability zone as the app servers, the proxy you will
run) and record the figures here. A proxy usually pays for itself once connection counts, not latency, are the limit
(CAPACITY.md).

## Moving a firm between cells (TEN-16)

```
php artisan tenants:relocate acme --to=eu-2            # keeps the old database as an instant rollback
php artisan tenants:relocate acme --to=eu-2 --drop-old # removes it once the move is verified
```

1. The firm becomes **read-only**: writes answer `503 maintenance_read_only` with `Retry-After`, reads keep working.
2. Its data is exported as a PLAT-9 bundle (documents stay where they are: object storage is not per cell).
3. A new database is created on the target cell, migrated, the bundle imported and **verified row by row**.
4. The registry is switched and the read-only window ends.

Any failure switches the firm back to its old database, which was never modified, and drops the half-built one. The
window lasts as long as the export + import (seconds for a small firm, minutes for a large one). The move refuses a
target that is full or not accepting (override with `--ignore-capacity`), in another region than the firm's, or that
resolves to the same database. Queued jobs that write during the window (webhook deliveries, exports) are not carried
over: drain the firm's queue first for a clean move.

Verified against SQLite cells in `tests/Feature/CellsTest.php`. Moving between two real MySQL servers has not been
exercised here; rehearse it on staging first.
