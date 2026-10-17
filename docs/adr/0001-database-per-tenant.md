# ADR-0001: Database-per-tenant multi-tenancy (TEN-1)

- **Status:** Accepted (2026-10-09, by the product owner)
- **Date:** 2026-09-30
- **Supersedes:** none · **Blocks:** TEN-2 … TEN-17, PLAT-1, PLAT-5, SEC-12

## Context

The product must run as (a) a multi-tenant SaaS and (b) a single-tenant self-hosted install from one codebase. Tenants are law firms holding privileged client material and client funds, so a cross-tenant leak is an existential event, not a bug.

Facts about the current code that constrain the choice:

- `Utils::createDefaultTableColumns()` adds foreign keys on `created_by` / `updated_by` / `deleted_by` → `users` to every domain table (17 tables). Any model of tenancy must keep `users` in the same database as the data it audits.
- Spatie permission cache, queue, session and lock keys are unprefixed today.
- Documents are written to a flat `cases/docs` path.
- `migration:export` / `import` / `IdMapper` already exist and can become the eject/import/relocation path (PLAT-9).

## Decision

**One database per tenant**, plus a deliberately thin **control-plane database**.

- **Control plane:** tenant registry, domains, cell placement, provisioning state, schema version, login `identities`, entitlements. Rule: *if it could ever join to a case, it does not belong here.*
- **Tenant plane:** all domain data, tenant `users` (with nullable `global_identity_id`), audit rows, sequences.
- **Cells:** capacity is added by adding database instances, ~200–500 tenants each; a tenant row fully describes its cell, bucket prefix and queue lane.
- **Self-host collapse:** the same code with exactly one tenant and the control plane collapsed into it. Differences are configuration and entitlements only, never `if (SAAS)` in domain code.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Shared schema + `tenant_id` | One missing global scope, raw query or job = cross-firm leak of privileged data. Isolation depends on every future line of code being right. Also makes per-firm restore, export and eject a filtering exercise. |
| Schema-per-tenant on one server | Lighter than DB-per-tenant and reasonable on PostgreSQL, but on MySQL a schema *is* a database, so it is the same model. Engine change (MySQL→Postgres) is its own project and is deferred (see Open questions). |

## Consequences

Benefits: isolation enforced by the storage engine; per-tenant backup/PITR/restore; per-tenant eject to self-host is a dump; whale isolation by relocation.

Costs we accept knowingly:

1. **Migration fan-out** is the dominant ongoing operational cost → resumable per-tenant runner with versions and drift detection (TEN-7, TEN-8).
2. **Table-count ceiling** per instance (~30 tables × tenants), not connection count → cells (TEN-4).
3. **Tenant placement / routing** must be correct and fail closed (TEN-3).
4. **No cross-tenant SQL.** Product analytics go through a warehouse that excludes privileged content (ENG-26).
5. **Identity split** (TEN-5): login identity in the control plane, membership in the tenant.

## Open questions (separate ADRs)

- ~~MySQL vs PostgreSQL~~: decided, stay on MySQL/MariaDB (ADR 0002).
- Search engine, hosting topology, pricing/metering dimensions (see backlog §13).

## Implementation status

Verified by the test suite (`tests/Unit/TenantContextTest.php`, `tests/Feature/TenantResolutionTest.php`).

- **TEN-2 (done, minimal):** control-plane connection `control`, `tenants` and `tenant_domains` tables (`php artisan migrate --database=control --path=database/migrations/control`), `Tenant` / `TenantDomain` models. Identities, cells and provisioning-job state are still to do.
- **TEN-3 (done):** `ResolveTenant` global middleware resolves host -> tenant -> connection before routing. Unknown host is 404, inactive tenant is 403. In `TENANCY_MODE=multi` the default connection is fail-closed until a tenant is resolved. Queued jobs carry their tenant and restore it before running.
- **TEN-6 (partial):** cache prefix, file-cache directory and Spatie permission cache key are namespaced per tenant. Session and lock prefixes still to do. Verification found that the file cache driver ignores `cache.prefix`, so it now gets a per-tenant directory.
- **TEN-10 (started):** the two-tenant isolation tests cover HTTP resolution, database data, cache and jobs. Still to add: files, exports, console commands, global search; make it a required CI gate.
- **TEN-12 (done):** documents are written under `tenants/{key}/cases/docs` with a SHA-256 checksum; `documents:relocate` moves older files there with checksum verification; with `TENANCY_STORAGE_ROLE_ARN` each firm's S3 access uses STS credentials tagged with the firm, confined by the policy from `storage:iam-policy` to its own prefix (docs/ops/STORAGE_ISOLATION.md).
- **TEN-7 (done, core):** `php artisan tenants:migrate [--tenant=slug] [--retry-failed]` migrates each tenant independently, records `schema_version` / `migration_status` / `migration_error` in the control plane, never lets one failure block the rest, is idempotent, and leaves the process fail-closed afterwards. `php artisan tenants:status` shows the version distribution and failures. Since then: `--queue` (one unique `MigrateTenant` platform job per firm, so workers migrate in parallel), `--resume` (only firms pending, failed, stuck or behind), a per-firm lock so two runs never touch one database, and a fleet dashboard at `/api/platform/dashboard`. TEN-8 drift detection is `schema:drift`.
- **TEN-9 (done, core):** `tenants:provision`, `:suspend`, `:resume`, `:offboard`, `:legal-hold`, `:purge`, all backed by `TenantLifecycle`. Deletion is one recorded sequence: final export at offboarding → retention window → database, files, `PurgeStep`s (key, warehouse), sign-in, export (docs/ops/FIRM_DELETION.md); `tenants:purge-due` runs it daily.
  - Provision creates the database, migrates, seeds roles/permissions and reference data, creates the first admin (generated password shown once, never stored in plaintext) and a domain. It is resumable and idempotent.
  - Purge is refused under legal hold (even after the retention window, even if the tenant asked for deletion), before the retention window ends, and before offboarding. It drops the database, deletes `tenants/{slug}/` from configured disks, and removes domains, leaving a `purged` tombstone row.
  - Verified against real SQLite databases. The MySQL `CREATE`/`DROP DATABASE` path is only unit-tested for SQL safety; exercise it on a real server before relying on it.
  - Still to do: mandatory export before deletion (PLAT-9), key destruction (TEN-13), warehouse expunge (ENG-26), API/queued provisioning. Court reference data is now seeded from the TEN-11 system catalog.
- **TEN-11 (done, core):** courts, court types and hearing types are platform-owned system rows (`is_system`, stable `catalog_key`), defined in `App\Catalog\SystemCatalog` and re-synced into every firm by `tenants:migrate` / `app:upgrade` / provisioning. Firms cannot rename or delete them, only alias them, and add their own rows freely. Expense categories stay firm-owned. See docs/SYSTEM_CATALOG.md.
- **TEN-5 (done, core):** control-plane `identities` + `tenant_memberships`; tenant `users.global_identity_id` (nullable, unique, deliberately not a foreign key).
  - One person = one identity: provisioning the same email in a second firm reuses their login (`admin_password` is returned as null).
  - In multi mode `POST /login` authenticates the identity and requires a membership in the firm the host resolved to; no resolved firm means no login. Single mode is unchanged.
  - Deactivating (soft-deleting) a user in one firm leaves the person's other firms working; a disabled identity is locked out everywhere.
  - Changing a password via `/profile/password` updates the identity, so it applies to every firm. Each tenant row keeps a copy of the hash so a standalone tenant dump (eject / self-host) can still authenticate.
  - A tenant dump has no dangling foreign keys and contains no control-plane tables (tested).
  - The tenant's own domain is added to Sanctum's stateful list when the host resolves.
  - Password recovery/reset works in multi mode: single-use, 60-minute token stored on the identity, link built on the firm's own domain, identical response for unknown / non-member emails, 5 requests/hour per email+IP, and a token only redeems at a firm the person belongs to. A reset changes the shared password and refreshes the redeeming firm's hash copy.
  - Still to do: MFA (SEC-12), SSO, the `/firms` switcher endpoint and UI (UI-16), inviting existing identities as non-admin members, stale tenant-side hash copies in other firms after a password change.
- **Not yet done in multi mode:** running arbitrary console commands per tenant, 

### DATA-8 (gap-free numbering) — done
Invoice and receipt numbers come from `SequenceService` (locked counter row, per-year format), drawn in the same transaction as the insert, so a failed insert never burns a number. Verified with 6 parallel workers × 10 creates on MariaDB: distinct, sequential, zero errors. Trust-ledger `reference_number` is user-entered and not covered.

Note (DATA-8): the sequence counter row is created with a no-op upsert, not `insertOrIgnore`. The latter left a shared lock that made two first-time callers deadlock when upgrading to exclusive (found by the parallel test, fixed).
