# Capacity: ceilings per cell, and when to scale (ENG-27)

A cell is one database server holding many firm databases (TEN-4), plus the web and queue nodes in front of it. This
page records what was measured, the ceilings that follow from it, and the signal that says when to act.
`php artisan capacity:report` shows every signal for every cell. It exits 1 when any signal is at WARN (80 % of its
ceiling) or FULL, so it can drive an alert.

## What was measured (October 2026)

Environment: 4 vCPU, 15 GB RAM, MariaDB 10.11 with a 128 MB buffer pool. Web, database and load generator all ran on
the same machine, so these are floors, not production figures. Re-measure on your own instance class (below).

### Database server: firms per server (`capacity:soak`)

Each firm has **86 tables** (82 when the soak below ran). The soak added scratch firms 100 at a time and ran a typical request against random
firms. The request touches 17 tables, from sign-in and permissions to a dashboard's reads.

**With the server's default caches** (`table_open_cache` 2 000, `table_definition_cache` 400):

| Firms | Tables | Request p50 / p95 (ms) | Tables opened per 1 000 queries | Catalogue scan (ms) |
|---|---|---|---|---|
| 100 | 8 200 | 2.6 / 8.9 | 64 | 13 |
| 200 | 16 400 | 4.9 / 13.6 | 602 | 23 |
| 300 | 24 600 | 8.9 / 13.9 | 813 | 36 |
| 400 | 32 800 | 10.9 / 17.5 | 930 | 48 |
| 600 | 49 200 | 12.9 / 21.7 | 1 064 | 104 |

**With the caches sized to the cell** (`table_open_cache` and `table_definition_cache` 30 000; `open_files_limit`
20 000):

| Firms | Tables | Request p50 / p95 (ms) | Tables opened per 1 000 queries | Catalogue scan (ms) |
|---|---|---|---|---|
| 100 | 8 200 | 2.4 / 5.1 | 64 | 13 |
| 200 | 16 400 | 3.0 / 6.4 | 60 | 28 |
| 300 | 24 600 | 4.0 / 12.5 | 62 | 46 |
| 400 | 32 800 | 5.8 / 14.4 | 49 | 61 |
| 600 | 49 200 | 8.2 / 17.1 | 60 | 103 |

What this shows:

- **Untuned, the table cache is the first limit.** Past about 100 firms, nearly every query reopens a table, and
  request time grows 5x by 600 firms.
- **Tuned, opening stays flat.** It is only new firms warming up, and latency doubles from 100 to 300 firms, then
  again by 600.
- **The next limit is the buffer pool.** It held the working set only up to about 300 firms here; with more memory
  the curve moves right.
- **Catalogue scans grow linearly**, about 0.17 ms per firm. Backups, `schema:drift` and migrations feel this first.
- **Provisioning a firm's tables took about 0.3 s**, roughly flat to 400 firms, then 0.4 to 0.6 s.

### Web node: requests per second (`loadtest/k6/staff-day.js`)

One firm, 60 staff logins, each person looping through a working day with 1 s think time between screens. PHP
OPcache was on, with 4 PHP workers on the same 4 vCPU as the database and k6.

| Concurrent people | Requests/s | Screen p95 | Report p95 | Errors |
|---|---|---|---|---|
| 10 | 26 | 55 ms | 48 ms | 0 % |
| 25 | 65 | 52 ms | 55 ms | 0 % |
| 50 | 123 | 91 ms | 94 ms | 0 % |
| 100 | 133 | 457 ms | 462 ms | 0 % |

The node saturates at about **130 requests/s**, roughly 33 per vCPU while sharing the box with its database. Above
about 60 concurrently active people, requests queue. The objective is a screen's p95 under 500 ms (the script's
threshold), so plan for **about 50 concurrently active people per 4 vCPU of PHP**.

Without OPcache the same node saturated at about 32 requests/s: enable it (the production image does).

## Ceilings and signals

| Resource | Ceiling | Signal (`capacity:report`) | Action |
|---|---|---|---|
| Firms per cell | `capacity`. **200** on an untuned or small (≤ 8 GB) server. **300** on 16 GB+ with the caches sized as below. Re-measure before going higher. | firms ÷ capacity ≥ 80 % | the cell stops accepting at capacity; add a cell (`cells add`) |
| Tables per server | **25 000** (about 300 firms) | tables ÷ 25 000 | lower `capacity` or add a cell |
| Table cache | `table_open_cache` and `table_definition_cache` ≥ firms × 20; `open_files_limit` ≥ twice that | cache below the need; tables opened per second > 5 | raise the caches, then add a cell |
| Memory | buffer pool hit ratio ≥ 99 % | hit ratio below 99 % | a bigger instance (buffer pool about 70 % of RAM), or a new cell |
| Connections | `max_connections`. Each busy PHP worker and queue worker holds one. | peak used ÷ max ≥ 80 % | raise `max_connections`, add a connection proxy (CELLS.md), or a cell |
| Web | about 33 requests/s per vCPU, or 50 active people per 4 vCPU | p95 of screens (APM, ENG-24) above 500 ms | add web replicas; they are stateless with Redis sessions (ENG-20) |
| Queue | interactive backlog below 500 | jobs waiting on the interactive lane | add queue workers; bulk has its own (QUEUES.md) |
| Storage | the volume | database size; keep 30 % free | grow the volume; files are on object storage per firm (TEN-2) |

## Re-measure for your instance class

**Never on production.** On a staging copy of a cell server:

1. `php artisan capacity:soak --firms=600 --step=100 --requests=600 --json > soak.json`. It drops its scratch
   databases unless `--keep`.
2. Read the request p95 and tables-opened columns. Set `capacity` a step below where p95 crosses 20 ms or opening
   stops being flat. Size the caches as in the table above and run again.
3. Create a few staff logins on a staging deployment, then run:
   `EMAIL_PATTERN='load-{n}@firm.test' USERS=60 VUS=50 BASE_URL=https://staging… k6 run loadtest/k6/staff-day.js`.
   Raise `VUS` until the thresholds fail. That is the node's ceiling.
4. Record the figures here, with the instance class and date.

The `loadtest` workflow (GitHub Actions, run by hand) does steps 1 and 3 on a runner, for comparing releases.
