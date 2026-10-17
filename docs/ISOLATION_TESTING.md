# Cross-tenant isolation testing (TEN-10)

The one failure a multi-tenant legal product cannot survive is one firm seeing another firm's data. This suite is the permanent
gate against it: `php artisan test --testsuite=Isolation`, run by `.github/workflows/isolation.yml` on every pull request and on `main`.

## What it does

`tests/Isolation/IsolationTestCase.php` provisions **two firms through the real lifecycle** (`TenantLifecycle::provision`: own
database, migrations, roles, admin, domain), seeds them with **different amounts of data** (A: 2 clients, B: 5) carrying a **canary**
string that exists in one firm only (`CANARY-A`, `CANARY-B`), switches the application to multi-tenant mode, and then talks to it
over real HTTP on each firm's own host with real session cookies.

Two design choices matter:

- **One process.** Production serves a request per process; the suite serves everything from one, like a queue worker or Octane.
  Anything memoised across requests (permission registry, rate limiter, cache store, plan, session) shows up as a leak. Only the
  session/login state is reset between requests (as a new process would), so a cookie-less request is really anonymous.
- **Canaries and unequal sizes.** If a canary of one firm appears anywhere in a response, file, export or job result of the other,
  the test fails whatever the cause. Ids that exist only in B must be unreachable from A, and ids in both must return the caller's own row.

## Two runs: default drivers and production drivers

`isolation.yml` runs the suite twice:

| Job | Firms' databases | Cache and sessions | Files |
|---|---|---|---|
| `isolation` | a SQLite file each | file store, per-firm directories | local disk |
| `isolation-drivers` | a database each on **one MySQL 8 server** | **one Redis** (cache, sessions, a queue), separated only by the firm prefix | **one S3 bucket** (moto's S3 server in CI; MinIO or AWS the same), separated only by the key prefix |

The second run is switched on by `ISOLATION_DB=mysql`, `ISOLATION_REDIS=1`, `ISOLATION_S3=1` (connections from the usual
`DB_*`, `REDIS_*`, `AWS_*` variables). `DriversIsolationTest` first checks the run really uses those drivers, so a
misconfigured job fails instead of silently testing SQLite again, then covers what only shows on shared infrastructure:
every Redis key carries its own firm's prefix and no value of the other firm; a firm's cache flush on the shared Redis
leaves the other firm's keys; jobs on a shared Redis queue run in their own firm; both firms' objects sit in one bucket
under their own prefixes, the rows match the objects, and a document id of the other firm cannot be previewed or deleted.

To run it locally: a MySQL/MariaDB the test user may create databases on, a Redis, and any S3 endpoint with a bucket, then
`ISOLATION_DB=mysql ISOLATION_REDIS=1 ISOLATION_S3=1 DB_HOST=… REDIS_HOST=… AWS_ENDPOINT=… AWS_BUCKET=… AWS_USE_PATH_STYLE_ENDPOINT=true php artisan test --testsuite=Isolation`.

## Coverage

| File | Proves |
|---|---|
| `SessionIsolationTest` | a session from firm A is not accepted on B's host (cookie name, login key, store directory); logout is per firm; interleaved requests never see the other firm's user |
| `ApiIsolationTest` | every parameterless and single-id GET route under `/api/v1`, with ids 1-7, both directions; lists, show/update/delete/restore of B-only ids from A (404), bulk delete, global search, forged `Host`/`X-Forwarded-Host`, control-plane and platform-host separation |
| `FilesIsolationTest` | files live under `tenants/<firm>/`; B-only documents cannot be previewed or deleted from A; same document id returns the caller's own file; listings and versions |
| `StateIsolationTest` | cache entries, locks, counters, rate-limit and failed-login lockout, the permission registry, user permissions, plan/entitlements, feature-flag overrides |
| `QueueIsolationTest` | a job carries its firm; runs in that firm whatever the worker's ambient firm; sees only its data and cache; refused if it names no firm, an unknown firm, or a suspended firm; the process fails closed afterwards |
| `CommandIsolationTest` | `tenants:usage` per firm; `webhooks:deliver` fan-out (A's event never reaches B's endpoint); one broken firm does not stop the others; `health:probe`, `support:expire`, `retention:run`, `billing:run` change nothing and leave the process fail-closed |
| `ExportIsolationTest` | full-firm zip, CSV exports and the `portability:export` bundle contain only the exporting firm; two firms exporting in the same second get different scratch files |
| `DriversIsolationTest` | the run uses the drivers it claims; on Redis: per-firm key prefixes, a flush scoped to one firm, a shared queue; on S3: one bucket, per-firm prefixes, objects match rows, no cross-firm preview or delete |
| `StructureIsolationTest` | the preconditions (two active firms, separate databases, different data, multi mode); control-plane and firm tables are disjoint; unknown hosts never fall back to a firm; API keys belong to one firm; a key is not a session and a session is not a key |

## Rules for changing the app

- **New tenant-scoped state** (a cache key, a file path, a singleton that remembers something): it must be namespaced by
  `TenantContext`. Add a test here that writes it as A and reads it as B.
- **New route:** it is covered automatically by the `ApiIsolationTest` sweep if it is a GET with at most one id. Mutating routes
  with special payloads need an explicit case.
- **New console command that fans out over firms:** add it to `CommandIsolationTest`.
- **Never** "fix" a failure here by loosening the assertion. A failure means data crossed a boundary.

## What it found

- **Cross-firm session replay (critical).** Sessions shared one cookie name, one login key and one store, so a session issued by
  firm A authenticated the user with the same id in firm B. Fixed by `TenantSessionGuard` and per-firm session cookie/storage in
  `TenantContext`; proven both ways: 6 session tests fail with the fix removed, and pass with it.
- The permission registry and the rate limiter kept the previous firm's state when the active firm changed (long-lived processes only).
- The full-firm export used a scratch name shared across firms.
- **One firm's cache flush emptied every firm's cache on Redis** (found by the production-drivers run). Laravel's Redis
  store flushes with `FLUSHDB`, ignoring the prefix, so `cache:clear` for one firm cleared every firm's cache and locks
  (and sessions sharing the database). `App\Tenancy\TenantRedisStore` now deletes only the active prefix's keys (SCAN in
  batches); `RedisIntegrationTest` and `DriversIsolationTest` keep it that way.

## Does the suite catch a regression? (mutation check)

Each of these leaks was introduced one at a time and the relevant tests were run; every one is caught (restored afterwards):
session cookie name shared; login key shared; session directory shared; cache prefix shared (a physically shared store); cache
directory shared; permission registry not cleared on firm switch; rate limiter not refreshed; storage prefix shared; host
resolving to a fixed firm; queued job not activating its firm; job without a firm allowed to run; worker left pointing at a firm after
a job; export scratch file shared; usage and webhook fan-outs run in the wrong firm. The first run of the cache-prefix mutation
*survived* (the file store is separated by directory, which hid the missing prefix); that gap is closed by
`test_a_physically_shared_cache_store_is_separated_by_prefix`. This is a spot check of 14 mutations, not exhaustive.

## Limits (read these)

- **Tenant databases are SQLite** in this suite, so it does not exercise MySQL-specific behaviour (grants, `USE`, per-database users).
- The cache is the **file** store and sessions are **file**; Redis key prefixes, S3 prefixes and database sessions are not exercised
  here. The namespacing code is shared, but it is not proven against those drivers.
- **Queue:** the suite uses a *central* queue store (a database outside every firm). Each firm's own database also has a `jobs`
  table, so with the stock `database` queue connection a job would be written into that firm's database, where one worker running
  fail-closed cannot see it. That arrangement was not exercised here; multi-tenant deployments should use a central queue (ENG-20).
- Making this a **required check on `main`** is a repository setting (branch protection) and cannot be applied from a workflow.
- Webhook URL validation is switched off in the suite (`sso.block_private_hosts=false`), because the sandbox has no DNS; it has its own tests.
