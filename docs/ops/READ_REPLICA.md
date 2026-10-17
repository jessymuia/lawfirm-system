# Read replica for reports and the dashboard (ENG-23)

Partner reports (FUN-17) and the dashboard scan many rows. With a replica configured they read from it, so month-end
reporting never slows down the database that takes the writes.

## What reads from the replica

- **Which routes:** those marked `replica` in `routes/api.php`: `GET /api/v1/dashboard` and every
  `GET /api/v1/reports/*`, including their CSV downloads. `ReadReplicaTest` keeps that list exact.
- **What runs there:** while such a request is handled, the replica is the default connection. Authentication and
  authorization have already run on the primary. Anything after the request, such as writing the session, goes to the
  primary again.
- **Everything else** reads and writes on the primary.

## Configure

| Setup | How |
|---|---|
| One firm (self-hosted) | `DB_REPLICA_HOST` (plus `DB_REPLICA_PORT`, `DB_REPLICA_USERNAME` and `DB_REPLICA_PASSWORD` when they differ from the primary's). The replica uses the same database name as the primary. |
| One firm, own connection | `REPORTS_DB_CONNECTION=<name>` names a connection in `config/database.php` and takes precedence. |
| Many firms (cells, TEN-4) | `php artisan cells update eu-1 --replica-host=db-eu-1-ro [--replica-port=3306]`. Each firm reads from its own database on its cell's replica, with the cell's credentials. |
| Many firms, default cell | A firm on the template-only `default` cell uses `DB_REPLICA_HOST`. A cell row with its own `--host` never inherits it, because that replica belongs to another server. |

The replica account needs `SELECT` and, so that lag can be measured, `REPLICATION CLIENT` (MySQL) or `SLAVE MONITOR`
/ `REPLICA MONITOR` (MariaDB). Without it the replica is still used, but no lag figure is shown.

## When the primary is used instead

Before use the replica is checked, at most every 30 seconds per server. The read goes to the primary when:

- it cannot be reached;
- replication has stopped (`Seconds_Behind_Source` is NULL);
- it is more than `DB_REPLICA_MAX_LAG` seconds behind (default 300).

Each of these is logged. A report is therefore never wrong, only sometimes served by the primary.

## What people see

Each answer carries `X-Data-Source: replica|primary` and, when known, `X-Data-Lag: <seconds>`. The reports page and
the dashboard show "Figures are about N minutes behind" once the lag reaches a minute. Below that, nothing is shown.

## Operating it

- **Ad-hoc check:** `curl -sI` on a report and read `X-Data-Source` and `X-Data-Lag`.
- **Persistent lag:** the logs show "Read replica is Ns behind" (notice) or "not replicating" / "unreachable"
  (warning). Repeated warnings mean reports are loading the primary again; fix replication or remove the setting.
- **Failover:** if the primary fails over to the replica, clear `DB_REPLICA_HOST` or the cell's `--replica-host`
  until a new replica exists.
