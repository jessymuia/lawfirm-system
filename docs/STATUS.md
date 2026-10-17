# Health, status page and SLA measurement (PLAT-12)

A **cell** is one deployment: app servers plus the databases they serve (`CELL_ID`, default `default`). Everything here is per cell;
run a fleet of cells and point each cell's endpoints at your monitor or status hostname.

## Endpoints (public, any host, no session)

| Path | Use |
|---|---|
| `GET /health/live` | Liveness. Always `200 {"status":"ok"}`; touches no dependency, so a database outage never makes an orchestrator restart-loop the app. |
| `GET /health/ready` | Readiness. Checks the critical components (`database`, `cache`, `storage`) now; `503` if any is down. Body is statuses only, no detail. Point load balancers here. |
| `GET /status` | Human status page (no scripts, no external assets, strict CSP). |
| `GET /status.json` | Same data as JSON: overall status, components with 30-day availability, open and recent incidents. |

All are throttled (120/min per client) and independent of any firm: in multi-tenant mode they answer on every host (a load balancer
probes by address) without touching a firm's database, and expose no firm names. Exactly these four paths bypass tenant resolution.

## What is measured

`php artisan health:probe` (scheduled every minute) records one row per component in `health_probes`
(control database in multi mode, the application database otherwise):

| Component | Check |
|---|---|
| `database` | `select 1` on the platform store |
| `cache` | write/read-back/forget of a random key |
| `storage` | write/read-back/delete on the default disk (`STATUS_STORAGE_DISK` to change) |
| `queue` | backlog over `status.queue_backlog_degraded` (default 1000) is *degraded*; sync/null queues report "runs inline"; the database queue in multi mode is *unknown* (its table lives in each firm's database) |
| `tenant_databases` | multi mode: `select 1` on a random sample (default 25) of active firms; some unreachable = *degraded*, all = *down* |
| `scheduler` | not probed: *down* when no probe has been recorded for 3 minutes. The probe is the heartbeat. |

A recorded measurement older than 3 minutes is shown as *unknown*, never as its last good value. Failure details are fixed phrases
(`check failed`, `backlog`, `2/25 unreachable`): exception text can carry hosts and credentials and this data is public.
Overall status: a critical component down = **down**; any other component down or degraded = **degraded**.

## SLA: how availability is computed

`availability = (measured − down) ÷ measured`, over the last N days (default 30, at most 90) of recorded probes, per component.

- *degraded* counts as available; *unknown* is not measured.
- Probes inside a **maintenance** incident that covers the component (no components named = all) are excluded; overlapping windows are merged so nothing is excluded twice. An ordinary incident never excuses downtime: probes are the record, incidents are narrative.
- **Coverage** is reported next to availability: the share of expected one-minute probes that exist. Gaps (scheduler stopped, host powered off) are *not* counted as available or as down, so a low coverage means the availability figure rests on little data. Read them together.
- No measurements gives `null`, never 100%.
- History is kept 120 days (`status.keep_days`).

Staff read it at `GET /api/platform/sla?days=30` (per component: availability, measured/down/degraded/maintenance minutes, coverage, and
`target` if `STATUS_SLA_TARGET` is set). Publishing a target is a commercial decision; the code only displays and measures.

## Incidents and maintenance

Multi-tenant: platform console, `POST /api/platform/incidents` and `POST /api/platform/incidents/{id}/updates` (role `operate`, audited, refusals audited too);
`GET /api/platform/incidents`, `/health` (adds latency, reasons and which firms' databases are unreachable) need `view`.
Self-hosted: `php artisan status:incident open "text" --title=… --impact=minor|major|none|maintenance --components=queue`, then
`status:incident update "text" --id=N --status=resolved`.

Statuses: investigating → identified → monitoring → resolved; maintenance starts `scheduled` and needs `--starts`/`--ends`.
Only maintenance can be in the future. A resolved incident is closed (409): open a new one. **The text is public and escaped, never write anything about a specific firm in it.**

## Limits

- Probes run from inside the deployment: they see what the application sees but **not** an outage of DNS, the load balancer or the network in front of it. Add an external monitor on `/health/ready` for that, and use its figures for any contractual SLA.
- One-minute resolution: shorter blips are invisible.
- The tenant-database check is a random sample, not every firm; the console view checks every active firm (first 200) on demand.
- Request-level success rates (5xx ratio, latency percentiles) are not measured here; that is the APM item in ENG-24.
- Not exercised against real load balancers or a real multi-cell fleet; tests cover the logic with SQLite/MariaDB.
