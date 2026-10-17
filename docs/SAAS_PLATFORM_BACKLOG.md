# SaaS Platform Backlog — Multi-Tenancy, Scale & Productization

**Created:** 2026-09-20
**Extends:** `docs/SYSTEM_REVIEW_AND_MIGRATION_PLAN.md` (the original review closed the *remediation* gaps; this backlog covers turning the remediated system into a dual-deployment product)
**Target:** one codebase serving (a) a multi-tenant SaaS and (b) single-tenant self-hosted installations

---

## How to use this document

- **IDs continue the existing convention** (`SEC-*`, `DATA-*`, `FUN-*`, `UI-*`, `ENG-*`, `MIG-*`) so code comments can keep citing them the way they already do. Two new prefixes are introduced for genuinely new work streams: **`TEN-*`** (tenancy) and **`PLAT-*`** (platform/commercial/dual-deployment).
- Numbering resumes where §7 of the plan stopped: `SEC-10+`, `DATA-8+`, `FUN-8+`, `UI-13+`, `ENG-7+`, `MIG-8+`.
- **Priority:** `P0` blocks other work or is a correctness/security risk · `P1` required for first paying SaaS tenant · `P2` required to scale or to win mid-size firms · `P3` valuable, schedulable later.
- **Size:** `S` ≤ 3d · `M` ~1–2w · `L` ~3–6w · `XL` > 6w (should be split further once started).
- §12 has a flat import table for bulk-loading into the tracker. §14 records what is built and what remains, checked against the code.

> **One rule that governs the ordering:** anything that has to touch *every tenant database* is dramatically cheaper before tenant fan-out than after. Those items are tagged **`[pre-fan-out]`** and should be pulled forward even if their business value is not yet urgent.

---

## 1. Epics

| Epic | Prefix | Outcome |
|---|---|---|
| A. Tenancy foundation | `TEN` | Tenant isolation enforced by the storage engine; provisioning and migrations automated |
| B. Data model for scale | `DATA` | Sequences, identifiers, ledgers and audit data correct at multi-tenant volume |
| C. Application architecture | `ENG` | Logic out of controllers, versioned API, deny-by-default authorization |
| D. Scale & operability | `ENG` | Search, queues, storage, replicas, observability |
| E. Platform & dual deployment | `PLAT` | Entitlements, licensing, self-host artifact, admin/support tooling |
| F. Security & compliance | `SEC` | Matter-level access, ethical walls, SSO, retention/legal hold |
| G. Product depth | `FUN` | Portal, payments, trust reconciliation, assembly, reporting |
| H. Experience | `UI` | Tenant branding, remaining table conversions, accessibility |
| I. Carry-over | various | Items left open by the original plan's §7 checklist |

---

## 2. Carry-over from the original plan (close these first — they are cheap and already scoped)

These are unchecked or caveated in §7 of the plan. They are not new work; they are unfinished work.

### CARRY-1 — Observe a green GitHub Actions run and enable branch protection
**Priority:** P0 · **Size:** S · **Closes:** ENG-1
**Why.** Every local gate passes but the workflows themselves have never been observed green. The plan's own history shows that *executing* gates surfaced seven real bugs that static reading missed — including one (`private formatRow()`) that broke every list endpoint in the app.
**Acceptance.**
- [x] `ci.yml` passes end-to-end on a real runner, including the MySQL-backed and Playwright jobs *(green on `main` for the #7 and #9 merges)*
- [ ] `main` requires the workflow to pass before merge
- [x] Coverage percentage recorded; `--min=<actual>` added as a floor

### CARRY-2 — Commit `composer.lock` for the Laravel 12 upgrade
**Priority:** P0 · **Size:** S · **Closes:** ENG-2 follow-up
**Why.** The L12 jump resolved cleanly but the lockfile was deleted and not recommitted; a fresh clone is currently non-reproducible.
**Acceptance.** `composer install` from a clean clone produces the same tree CI ran against.

### CARRY-3 — Rotate the TextSMS credentials at the provider and audit git history
**Priority:** P0 · **Size:** S · **Closes:** SEC-1 remainder
**Why.** The key was removed from code but a leaked key stays valid until rotated. Out-of-repo action, never verified.
**Acceptance.** New key issued and in the secret store; old key revoked; history scanned (gitleaks) and scrubbed or the repo rotated.

### CARRY-4 — Sign off `MIGRATION_STATUS_MAPPING.md` against real production data
**Priority:** P1 · **Size:** S · **Closes:** MIG-3
**Why.** The file was re-authored from code review after being deleted; it has never been checked against an actual database.

### CARRY-5 — Rehearse the legacy cutover on staging
**Priority:** P1 · **Size:** M · **Closes:** MIG-6
**Why.** The only remaining hard gate before the legacy migration.

### CARRY-6 — Burn down the PHPStan baseline
**Priority:** P2 · **Size:** M · **Closes:** ENG-2 follow-up
**Why.** ~130 findings from Larastan v3's stricter relation typing. Agree a per-sprint reduction target; forbid growth.
**Acceptance.**
- [x] Baseline shrinks every sprint; CI fails if it grows
- [x] **Done: the baseline is empty**

### CARRY-7 — Run and document a real restore drill
**Priority:** P1 · **Size:** S · **Closes:** ENG-6 / FUN-5 caveats
**Why.** `backup:run` is scheduled and tested at `--dry-run` level only. An untested backup is a belief, not a backup.
**Acceptance.** Documented drill restoring DB **and** document storage to a working app; timed; added to a recurring calendar.

---

## 3. Epic A — Tenancy foundation (`TEN-*`)

### TEN-1 — ADR: adopt database-per-tenant `[pre-fan-out]`
**Priority:** P0 · **Size:** S · **Blocks:** everything in this epic
**Why.** Every other decision depends on this. Retrofitting tenancy later costs roughly an order of magnitude more, and the cost grows with each feature shipped in the meantime.
**Scope.** Write the ADR recording: chosen model (database-per-tenant), rejected alternatives (shared-schema `tenant_id`, schema-per-tenant) with reasons, the control-plane/tenant-plane split, and the self-host collapse strategy.
**Acceptance.**
- [ ] ADR committed under `docs/adr/`, reviewed and approved
- [x] Explicitly records *why* shared-schema was rejected (single missing global scope = cross-firm leak; unacceptable for privileged legal data)
- [x] Records the known costs accepted: migration fan-out, table-count ceiling, tenant placement

### TEN-2 — Control-plane database and tenant registry
**Priority:** P0 · **Size:** M · **Depends on:** TEN-1
**Why.** The only data that must be queried *across* tenants needs a home that is deliberately thin.
**Scope.** `tenants` (slug, name, status, region, plan, cell/connection pointer, schema_version), `tenant_domains`, placement/cell registry, provisioning job state.
**Acceptance.**
- [x] Separate connection; control-plane migrations run independently of tenant migrations
- [x] A tenant row fully describes where its data, files and queues live
- [x] Documented rule: *if it could ever join to a case, it does not belong here*

### TEN-3 — Tenant resolution middleware (fail-closed)
**Priority:** P0 · **Size:** M · **Depends on:** TEN-2
**Why.** Resolution must happen before routing, and an unresolved tenant must be an error — never a silent fallback to a default connection.
**Acceptance.**
- [x] Subdomain/custom domain → tenant → connection switch, ahead of route dispatch
- [x] Unresolvable tenant returns 404/400; **never** falls back to the control plane or a default DB
- [x] Console commands and queued jobs carry and restore tenant context explicitly
- [x] Test proves a job serialized under tenant A cannot execute against tenant B

### TEN-4 — Tenant-aware connection management and cell placement
**Priority:** P0 · **Size:** M · **Depends on:** TEN-3
**Why.** The real ceiling of this model is **table count per instance** (~30 tables × thousands of tenants), not connection count — PHP-FPM opens one connection per in-flight request regardless of tenant count. Capacity is added by adding cells.
**Scope.** Cell concept (~200–500 tenants per instance), routing via the registry, connection proxy (ProxySQL / RDS Proxy).
**Acceptance.**
- [x] Adding a cell requires no code change *(`php artisan cells add`; placement is automatic)*
- [ ] Connection latency measured with and without the proxy; result documented
- [x] Runbook states the per-cell tenant ceiling and the signal to add a cell *(docs/ops/CELLS.md)*

### TEN-5 — Split identity: control-plane `identities` + tenant `users` `[pre-fan-out]`
**Priority:** P0 · **Size:** L · **Depends on:** TEN-2
**Why.** `Utils::createDefaultTableColumns()` puts FKs on `created_by`/`updated_by`/`deleted_by` → `users` across all 17 domain tables. `users` therefore **must** live in the tenant database — a control-plane `users` table would require cross-database FKs (impossible in PostgreSQL; on MySQL it breaks the moment tenants span instances and makes a single-tenant dump unrestorable, destroying the ejection story).
**Scope.** Control-plane `identities` (email, password hash / SSO subject, MFA) as the authentication + login-routing record; tenant `users` remains the membership/profile record, gaining `global_identity_id`.
**Acceptance.**
- [x] One person can hold membership in two firms with one login
- [x] Deactivating a user in firm A has no effect in firm B
- [x] A tenant dump restored standalone resolves all its own FKs and audit rows
- [x] **Add the nullable `global_identity_id` column now**, while there is one database to migrate

### TEN-6 — Tenant-prefix all cache, queue, session and lock keys `[pre-fan-out]`
**Priority:** P0 · **Size:** M
**Why.** Spatie's permission cache is the sharpest edge: an unprefixed cache key means firm A's role cache authorizes firm B. Same class of bug applies to every cache entry, queue name, session prefix and lock.
**Acceptance.**
- [x] Prefixing is a framework-level default, not a per-call convention
- [x] `PermissionRegistrar` cache key includes the tenant
- [x] Test asserts a permission change in tenant A does not alter effective permissions in tenant B
- [x] Static check or test fails on an unprefixed cache/lock key

### TEN-7 — Tenant-aware migration runner
**Priority:** P0 · **Size:** L · **Depends on:** TEN-2
**Why.** This is the dominant ongoing operational cost of database-per-tenant. `artisan migrate` fan-out is not sufficient — partial failure must never leave a tenant silently half-migrated.
**Scope.** Resumable job queue, per-tenant `schema_version` in the control plane, explicit `pending`/`running`/`failed` states with retry.
**Acceptance.**
- [x] Tenants at different versions mid-rollout is a normal, reportable state
- [x] A failed tenant is flagged, retryable, and does not block the rest
- [x] Runner is idempotent and safe to re-run
- [x] Ops dashboard shows version distribution across the fleet

### TEN-8 — Schema drift detection
**Priority:** P1 · **Size:** M · **Depends on:** TEN-7
**Why.** Drift *will* occur — failed migrations, support-engineer edits, self-hosted customers who changed something. Undetected drift makes the next migration fail unpredictably.
**Acceptance.** Scheduled job diffs each tenant schema against the expected version and alerts on divergence.

### TEN-9 — Tenant lifecycle automation
**Priority:** P1 · **Size:** L · **Depends on:** TEN-7
**Scope.** Provision (create DB, migrate, seed catalog, create first admin), suspend, resume, offboard, delete.
**Acceptance.**
- [x] Provisioning a tenant is a single API/command call, fully automated
- [x] Suspension blocks access without destroying data
- [x] Deletion runs the full sequence: export → retention window → DB dropped, bucket prefix purged, encryption key destroyed, warehouse rows expunged
- [x] **Legal hold can block deletion**, including a tenant's own deletion request

### TEN-10 — Cross-tenant isolation test suite as a permanent CI gate
**Priority:** P0 · **Size:** M · **Depends on:** TEN-3
**Why.** Isolation is the product's core promise; it must be continuously proven, not assumed.
**Acceptance.**
- [x] Suite runs against **at least two provisioned tenants** (a single-tenant run can mask a leak)
- [x] Asserts a session authenticated as tenant A cannot read tenant B's rows, files, cache entries or queued job results
- [x] Covers API, console commands, queued jobs, exports and global search
- [ ] Gate is required on `main`

### TEN-11 — Split reference data: system catalog vs tenant overlay `[pre-fan-out]`
**Priority:** P1 · **Size:** M
**Why.** `CourtTypeSeeder`, `HearingTypeSeeder`, `ExpenseCategorySeeder` and `CourtSeeder` currently create ordinary tenant-editable rows. Court hierarchies change when the world changes, not when a firm edits something — and tenant-edited system rows create drift that can never be upgraded cleanly.
**Scope.** Platform-owned catalog (courts, court types, hearing types, currencies, tax rates, UTBMS codes) flagged `is_system`, id-stable, non-deletable, **idempotently re-synced on every upgrade**; tenant overlay for firm-specific categories and local aliases.
**Acceptance.**
- [x] System rows cannot be edited or deleted by a tenant
- [x] Re-sync is idempotent and safe on a version-lagging self-hosted install
- [x] Firms can add their own categories without touching the catalog

**Status (done):** courts, court types, hearing types, currencies and tax rates; see `docs/SYSTEM_CATALOG.md`. UTBMS codes (FUN-13) are a fixed standard kept in code (`App\EBilling\Utbms`) by decision.

### TEN-12 — Per-tenant object storage partitioning `[pre-fan-out]`
**Priority:** P0 · **Size:** M
**Why.** `DocumentController.php:90` writes to a flat `cases/docs` path on the `local` disk by default. Per-tenant prefixes let IAM enforce isolation at the storage layer, independent of application code — and migration gets more expensive with every file uploaded.
**Acceptance.**
- [x] All object keys carry the tenant identifier
- [x] Prefix-level IAM policy blocks cross-tenant reads even if the app is wrong
- [x] Existing documents migrated with checksum verification (reuse `migration:document-checksums`)
- [x] Checksum-on-write becomes a permanent integrity check, not migration-only tooling

### TEN-13 — Per-tenant envelope encryption + BYOK
**Priority:** P2 · **Size:** L · **Depends on:** TEN-12
**Why.** Enterprise legal buyers ask for customer-managed keys. Key destruction is also the cleanest crypto-shred on offboarding.
**Acceptance.**
- [x] Per-tenant data key
- [x] BYOK/CMK optional per tenant
- [x] Key destruction documented as a deletion mechanism

### TEN-14 — Per-tenant backup, PITR and restore drills
**Priority:** P1 · **Size:** M · **Depends on:** TEN-2, CARRY-7
**Why.** This is the headline benefit of the chosen model — "restore this one firm to 14:00 yesterday without touching anyone else" should be a runbook, not a project.
**Acceptance.**
- [x] Per-tenant backup and PITR verified by drill
- [x] **DB and file store restored as a consistent pair** (a DB restored to yesterday pointing at files deleted today is an incident)
- [x] Restore drills scheduled and logged

### TEN-15 — Region and data-residency pinning
**Priority:** P2 · **Size:** M · **Depends on:** TEN-4
**Why.** Kenya's DPA and any EU client will eventually require it. The routing hook is cheap now and expensive to retrofit.
**Acceptance.** `tenants.region` determines cell, bucket and queue; documented residency guarantee per region.

### TEN-16 — Tenant relocation between cells
**Priority:** P2 · **Size:** L · **Depends on:** TEN-4
**Why.** Whale isolation and rebalancing. A 200-lawyer firm should not share a cell with 400 solos.
**Acceptance.** Relocation is a routine, tested operation with a brief read-only window — not a rebuild.

### TEN-17 — Single-tenant collapse mode for self-hosted
**Priority:** P1 · **Size:** M · **Depends on:** TEN-3, TEN-5
**Why.** The dual-deployment promise only holds if self-host is *the same code with one tenant*, not a second product.
**Acceptance.**
- [x] Self-host boots with the control plane collapsed into the single tenant
- [x] No `if (SAAS)` branching in domain code — differences expressed only as configuration and entitlements
- [x] CI runs the test suite in both modes

---

## 4. Epic B — Data model for scale (`DATA-*`)

### DATA-8 — Per-tenant locked sequence service `[pre-fan-out]`
**Priority:** P0 · **Size:** M
**Why.** `Invoice::nextInvoiceNumber()` uses `MAX(id)+1` (`app/Models/Invoice.php:68`) — two concurrent creates collide, and the unique index converts that into a 500 for the user. Per-tenant, numbering must also be **gap-free and usually per-year** (`INV-2026-000123`), which is a statutory requirement in many jurisdictions.
**Scope.** `sequences` table per tenant with `SELECT … FOR UPDATE`; applies to invoice, receipt and trust-ledger numbers; configurable format per firm.
**Acceptance.**
- [x] Concurrency test: N parallel invoice creates produce N distinct, sequential numbers, zero errors
- [x] Per-year reset supported and configurable
- [x] No gaps under normal operation; gaps auditable when they occur

### DATA-9 — ULID/UUIDv7 for externally-visible identifiers `[pre-fan-out]`
**Priority:** P1 · **Size:** M
**Why.** Auto-increment ids leak volume, collide on merge, and make ejection/import a remapping exercise — which is precisely why `Services/Migration/IdMapper` exists. Sortable UUIDs remove that whole class of problem for new entities.
**Acceptance.** New externally-exposed resources use ULIDs; integer PKs may remain internal but are no longer exposed in API responses or URLs.

### DATA-10 — Append-only enforcement for trust and invoice ledgers
**Priority:** P0 · **Size:** M
**Why.** Client funds are regulated. The point of immutability is being able to *prove* it to an auditor — so it must be enforced below the application layer, not by model convention.
**Acceptance.**
- [x] No `UPDATE`/hard `DELETE` on ledger tables; enforced by DB grants or triggers
- [x] Corrections are reversing entries (the existing `void` endpoint is the right shape — generalise it)
- [x] Balances derived from the ledger reconcile against any cached totals; test proves it

### DATA-11 — Audit table partitioning, retention and archival
**Priority:** P1 · **Size:** M
**Why.** `owen-it/laravel-auditing` grows unbounded and will become the largest table in every tenant database.
**Acceptance.**
- [x] Partitioned by month; cold partitions archived to object storage
- [x] Retention policy set with a legal/professional-rules floor
- [x] Retention depth exposed as a **plan entitlement**

### DATA-12 — Replace stringly-typed domain fields with enums and reference tables
**Priority:** P2 · **Size:** M
**Why.** `status` tinyint, `lifecycle_status` string and `case_type` string carry meaning the schema does not enforce. `Cases::TRANSITIONS` is the right instinct — generalise it.
**Acceptance.**
- [x] PHP enums + DB constraints
- [x] Invalid states unrepresentable
- [x] `case_type` moved to a catalog table (see TEN-11)

### DATA-13 — Expand/contract migration policy with CI enforcement
**Priority:** P0 · **Size:** M · **Depends on:** TEN-7
**Why.** SaaS deploys continuously while self-host lags by months, so schema changes must be backwards-compatible across at least one release. Separately, migrations that rewrite large tables lock big tenants out — the `2026_08_19_090300` dedup migration is exactly this pattern and is fine only at current scale.
**Acceptance.**
- [x] Documented policy: add → backfill → dual-write → switch reads → drop, across a release boundary
- [x] **Backfills are queued jobs, not migrations**
- [x] CI check flags destructive or rewriting operations in a migration
- [x] Migrations tested against the largest tenant's data volume, not seed data

### DATA-14 — Multi-currency and tax modelling
**Priority:** P2 · **Size:** M
**Why.** Money is correctly `DECIMAL` (DATA-4) but single-currency. Cross-border matters and VAT need first-class modelling, not per-view formatting.
**Acceptance.**
- [x] Currency per invoice with FX rate captured at issue time
- [x] Tax rules per tenant/jurisdiction
- [x] Reporting handles mixed currency explicitly

---

## 5. Epic C — Application architecture (`ENG-*`)

### ENG-7 — Version the API (`/api/v1`) + deprecation policy
**Priority:** P0 · **Size:** M
**Why.** `routes/api.php` is unversioned. Self-hosted customers upgrade on their own schedule, so v1 clients will exist for years. Versioning costs almost nothing before the first external integration and is disruptive afterwards.
**Acceptance.** All routes under `/api/v1`; written deprecation policy (notice period, sunset headers); version negotiation documented.

### ENG-8 — Extract validation into Form Requests
**Priority:** P1 · **Size:** M
**Acceptance.** No inline `$request->validate()` in controllers; rules reusable by jobs and imports.

### ENG-9 — Extract a service/action layer
**Priority:** P1 · **Size:** L · **Depends on:** ENG-8
**Why.** ~25 controllers hold validation, authorization, business rules and response shaping (`CasesController` does status transitions, row formatting *and* activity feeds). A SaaS has many entry points — public API, webhooks, scheduled jobs, integrations, mobile — and none can reuse logic trapped in a controller.
**Acceptance.** Business operations invocable from controller, console and job with identical behaviour; covered by tests at the action level.

### ENG-10 — Replace `formatRow()` with API Resources
**Priority:** P1 · **Size:** M · **Depends on:** ENG-7
**Why.** Hand-rolled formatters are duplicated per controller and are why a `private` visibility slip once broke every list endpoint in the app.
**Acceptance.** One resource class per entity; response shape versioned with the API.

### ENG-11 — Policies + `authorizeResource`; deny-by-default
**Priority:** P0 · **Size:** L
**Why.** Inline `if (! Auth::user()->checkPermissionTo(...)) abort(403)` in every action is structurally the same pattern that produced SEC-3 — forget one line and the endpoint is open. Authorization should fail closed when a developer forgets.
**Acceptance.**
- [x] Policies on every model; controllers use `authorizeResource` *(done as route declarations: `authz:policy` runs the policy before the controller, like `authorizeResource`, and object rules live in the policies)*
- [x] A new endpoint with no policy **denies** rather than allows
- [x] `PermissionMatrixTest` extended to assert the default-deny behaviour

### ENG-12 — OpenAPI spec + generated typed client
**Priority:** P2 · **Size:** M · **Depends on:** ENG-7, ENG-10
**Acceptance.**
- [x] Spec generated in CI and published
- [x] Frontend client generated from it
- [x] Drift between spec and routes fails the build

### ENG-13 — Migrate the build from Laravel Mix to Vite
**Priority:** P2 · **Size:** M
**Why.** Mix/Webpack is the source of the legacy transitive-CVE pressure already seen in the `npm audit` gate, and build times compound across a growing team.
**Acceptance.** Vite builds dev and prod; `mix()` references in Blade replaced; CI and `deploy.sh` updated.

### ENG-14 — Migrate Vuex → Pinia
**Priority:** P3 · **Size:** S
**Note.** The store currently holds layout/theme state only, so this is genuinely small — do it before it grows.

### ENG-15 — Adopt TypeScript on the API boundary
**Priority:** P2 · **Size:** L · **Depends on:** ENG-12
**Why.** `npm run type-check` is currently a placeholder `echo`. For a long-lived product with multiple contributors, types on the API boundary pay for themselves.
**Acceptance.** Generated API types enforced; `type-check` is a real gate; incremental `.vue` migration path documented.

### ENG-16 — Delete the full-table branch of `paginatedOrFull()`
**Priority:** P1 · **Size:** S · **Depends on:** UI-14
**Why.** The no-`?page=` branch still loads whole tables — a deliberate compatibility shim (FUN-1/UI-5) and the single biggest latency/memory cliff in the app.
**Acceptance.** Every list endpoint is server-paginated; the branch and its whitelist fallback are removed.

### ENG-17 — Targeted coverage gates on money and permissions
**Priority:** P1 · **Size:** S
**Why.** A blanket coverage target was correctly rejected in the original plan. But billing, trust accounting and permissions are where a defect is unrecoverable.
**Acceptance.** Per-path coverage floors on `Invoice*`, `Payment*`, `TrustTransaction*`, policies and `ModulePermissions` consumers.

### ENG-18 — Release engineering hygiene
**Priority:** P1 · **Size:** S
**Why.** `git log` currently shows four commits titled "initial commit". Self-hosted customers need to know what changed and when.
**Acceptance.** Conventional commits, semver tags, generated changelog, release notes per version.

---

## 6. Epic D — Scale & operability (`ENG-*`)

### ENG-19 — Replace `LIKE '%term%'` search with a search engine
**Priority:** P1 · **Size:** L
**Why.** `SearchController`'s leading-wildcard `LIKE` cannot use an index and cannot rank. It will not scale and does not answer the question lawyers actually ask.
**Acceptance.** Meilisearch/Typesense with **per-tenant indexes**; relevance ranking; permission filtering applied at query time, not post-hoc.

### ENG-20 — Redis for cache/session/queue + per-tenant queue lanes
**Priority:** P1 · **Size:** M · **Depends on:** TEN-6
**Acceptance.** Horizontally scalable workers; a large tenant's bulk export cannot starve its cell.

### ENG-21 — Make firm-wide export asynchronous
**Priority:** P1 · **Size:** S
**Why.** `ExportController::fullFirmExport` streams (good) but still runs inside a request. At real volume it will time out.
**Acceptance.** Queued job → signed, expiring download link → notification.

### ENG-22 — Direct-to-storage uploads
**Priority:** P1 · **Size:** M · **Depends on:** TEN-12
**Why.** Large files must never proxy through PHP.
**Acceptance.** Signed-URL uploads; server records metadata + checksum only; size limits raised beyond the current PDF/10 MB constraint.

### ENG-23 — Read replicas for dashboard and reporting
**Priority:** P2 · **Size:** M
**Acceptance.** Reporting/dashboard queries routed to a replica; replica lag surfaced in the UI where it matters.

### ENG-24 — Observability baseline
**Priority:** P0 · **Size:** M
**Why.** No APM, structured logging, error tracking, or per-tenant usage metrics exist. You cannot operate a SaaS blind — and usage metrics are also what pricing will eventually be built on.
**Acceptance.**
- [x] APM with tenant tagging on every trace *(OTLP, any OpenTelemetry backend)*
- [x] Structured logs carrying tenant + request correlation ids
- [x] Error tracking with per-tenant grouping *(Sentry protocol: Sentry, GlitchTip, Bugsink)*
- [x] Per-tenant usage metrics (users, matters, storage, API calls)

### ENG-25 — Per-tenant rate limiting
**Priority:** P1 · **Size:** S
**Why.** Current limiting is per-IP (introduced for SEC-5). One tenant's integration must not consume a cell's capacity.

### ENG-26 — CDC/ETL into a warehouse for cross-tenant analytics
**Priority:** P2 · **Size:** L · **Depends on:** TEN-2
**Why.** With separate databases you cannot — and should not — JOIN across tenants. Product and business metrics need a separate path.
**Acceptance.**
- [x] Nightly (or CDC) load into a warehouse with `tenant_id` added *(nightly `warehouse:export`)*
- [x] Treated as a high-sensitivity system: aggregate aggressively, **exclude privileged client content entirely** *(aggregates only, enforced by `WarehouseSchema`)*
- [x] Documented as the *only* place cross-tenant data may coexist *(docs/ops/WAREHOUSE.md)*

### ENG-27 — Load and capacity testing at projected tenant scale
**Priority:** P2 · **Size:** M · **Depends on:** TEN-4
**Acceptance.** Documented per-cell ceilings (tenants, tables, connections, storage) and the scaling signal for each.

---

## 7. Epic E — Platform & dual deployment (`PLAT-*`)

### PLAT-1 — Entitlement service (plan → features)
**Priority:** P0 · **Size:** M · **Depends on:** TEN-2
**Why.** The single mechanism that lets one codebase serve both modes: SaaS reads the subscription, self-host reads a license file, domain code reads neither directly.
**Acceptance.** One entitlement API; no domain code branches on deployment mode.

### PLAT-2 — Signed license file for self-hosted installs
**Priority:** P1 · **Size:** M · **Depends on:** PLAT-1
**Acceptance.** Signed, expiring license; defined grace-period and expiry behaviour (degrade, never destroy data); offline validation.

### PLAT-3 — Feature flags and edition gating
**Priority:** P1 · **Size:** M · **Depends on:** PLAT-1
**Why.** **Never fork per client.** Per-customer forks are the most common way products in this category die.
**Acceptance.** Flags resolvable per tenant and per edition; documented policy that customer-specific behaviour is a flag, never a branch.

### PLAT-4 — Subscription billing and usage metering
**Priority:** P1 · **Size:** L · **Depends on:** PLAT-1, ENG-24
**Acceptance.** Stripe + M-Pesa; plan changes, proration, dunning, invoicing; metered dimensions match ENG-24's metrics.

### PLAT-5 — Self-hosted distribution artifact
**Priority:** P0 · **Size:** L · **Depends on:** TEN-17
**Why.** `deploy/deploy.sh` does `git reset --hard origin/main` over SSH to a PHP-FPM box — no tags, no rollback, no zero-downtime. That is workable for one server you control and impossible to hand to a customer.
**Acceptance.**
- [x] Versioned Docker image + `docker-compose.yml` + Helm chart
- [ ] Semver releases with published artifacts
- [x] Documented minimum system requirements and install guide

### PLAT-6 — `app:upgrade` and zero-downtime deployment
**Priority:** P0 · **Size:** M · **Depends on:** PLAT-5, DATA-13
**Acceptance.** Idempotent upgrade command (migrate + catalog re-sync + cache rebuild); documented rollback; atomic release switching replaces `git reset --hard`.

### PLAT-7 — Tenant admin console for platform staff
**Priority:** P1 · **Size:** M · **Depends on:** TEN-9
**Acceptance.** View/provision/suspend tenants, inspect schema version and usage, trigger migrations and backups.

### PLAT-8 — Support impersonation with mandatory audit trail
**Priority:** P1 · **Size:** M · **Depends on:** PLAT-7
**Why.** Support will need access to firm data, and firms hold privileged material. Every access must be provable.
**Acceptance.**
- [x] Impersonation always recorded in the tenant's own audit log
- [x] Time-boxed, reason-required, and visible to the firm
- [x] Configurable per tenant, including "requires my approval"

### PLAT-9 — Eject-to-self-host and import-from-self-host as product features
**Priority:** P1 · **Size:** L · **Depends on:** TEN-9
**Why.** `migration:export` / `migration:import` / `migration:orphan-scan` / `IdMapper` / `ForeignKeyRegistry` were built as one-off legacy tooling. Promoting them into a supported, versioned capability removes the biggest objection conservative legal buyers have to SaaS — and reuses the same code path as tenant relocation and disaster recovery.
**Acceptance.**
- [x] Both directions tested in CI against a seeded tenant
- [x] Versioned export schema covering data + documents + checksums + audit trail
- [x] Breaking changes to the export format treated as breaking API changes
- [x] Documented as a customer-facing guarantee

### PLAT-10 — Customer changelog and per-version upgrade guides
**Priority:** P1 · **Size:** S · **Depends on:** ENG-18
**Why.** Self-hosted customers upgrading across several versions need to know what breaks.

### PLAT-11 — Public API and webhooks
**Priority:** P2 · **Size:** L · **Depends on:** ENG-7, ENG-12
**Acceptance.** Scoped API keys per tenant, webhook subscriptions with retry/replay, rate limits, published docs.

### PLAT-12 — Health endpoints, status page and SLA instrumentation
**Priority:** P1 · **Size:** S · **Depends on:** ENG-24
**Acceptance.** Per-cell health checks; public status page; SLA measured from real data.

### PLAT-13 — Self-service trial signup and onboarding
**Priority:** P2 · **Size:** M · **Depends on:** TEN-9, PLAT-4
**Acceptance.** Signup provisions a tenant with catalog data and a guided first-matter flow; trial→paid conversion tracked.

---

## 8. Epic F — Security & compliance (`SEC-*`)

### SEC-10 — Matter-level access control
**Priority:** P0 · **Size:** L · **Depends on:** ENG-11
**Why.** Today any user with `list-cases` sees **every matter in the firm**. Firm-global permissions are a procurement blocker above roughly 10 fee-earners.
**Acceptance.** Per-matter access lists (assigned team + explicit grants); enforced in list, show, search, export and document access; audit trail on grant/revoke.

### SEC-11 — Ethical walls / conflict screens
**Priority:** P1 · **Size:** L · **Depends on:** SEC-10
**Why.** A regulatory requirement when a firm acts against a former client or holds adverse matters.
**Acceptance.** Screens block access across all surfaces including global search, exports and notifications; attempted access is logged; screens are auditable and reportable.

### SEC-12 — SSO (SAML/OIDC) per tenant + enforced MFA
**Priority:** P2 · **Size:** L · **Depends on:** TEN-5
**Acceptance.** Per-tenant IdP configuration; SCIM provisioning optional; MFA enforceable by firm policy.

### SEC-13 — Retention schedules, legal hold and DSAR workflows
**Priority:** P1 · **Size:** L · **Depends on:** TEN-9
**Why.** Kenya's Data Protection Act and GDPR both apply depending on the client base — and legal hold must be able to override a deletion request, since firms hold other people's privileged material.
**Acceptance.**
- [x] Configurable retention per record class
- [x] Legal hold blocks deletion and is itself audited
- [x] DSAR export/deletion workflow with verification

### SEC-14 — Security posture for enterprise procurement
**Priority:** P2 · **Size:** L
**Why.** Mid-size and corporate-facing firms send security questionnaires; this is a sales gate, not an engineering nicety.
**Acceptance.** Third-party penetration test completed and remediated; SOC 2 / ISO 27001 gap assessment; published security overview and DPA template.

### SEC-15 — Secret management per environment
**Priority:** P1 · **Size:** S · **Depends on:** CARRY-3
**Acceptance.** Secrets in a managed store (not `.env` on the box); rotation procedure documented and rehearsed; per-tenant integration credentials encrypted at rest.

---

## 9. Epic G — Product depth (`FUN-*`)

| ID | Title | P | Size | Why it matters |
|---|---|---|---|---|
| **FUN-8** | Document content search (OCR + full-text) | P1 | L | Lawyers search *inside* documents; filename/metadata search isn't the job. Depends on ENG-19. |
| **FUN-9** | Conflict-of-interest check at client/matter intake | P1 | M | Professional-rules requirement in most jurisdictions; blocks adoption at established firms. |
| **FUN-10** | Client portal | P1 | XL | Matter status, invoices, online payment, secure document exchange. Frequently the deciding demo feature. Needs SEC-10. |
| **FUN-11** | Payment gateway + online invoice payment | P1 | L | M-Pesa **and** card, given the Kenyan context. Directly shortens firms' cash cycle — the easiest ROI story to sell. |
| **FUN-12** | Trust three-way reconciliation + retainer replenishment | P1 | L | Trust accounting exists (FUN-2) but reconciliation is what regulators actually inspect. Needs DATA-10. |
| **FUN-13** | LEDES / UTBMS billing codes + e-billing export | P2 | M | Required to invoice corporate/insurer clients at all. |
| **FUN-14** | Document assembly from templates + e-signature | P2 | XL | Where fee-earners spend their day; strongest differentiator against generic practice tools. |
| **FUN-15** | Email ingestion into matters | P2 | L | Without it the system is always a second, stale copy of reality. |
| **FUN-16** | Calendar sync + court diary / e-filing | P2 | L | Reminder job (FUN-3) is the start; two-way sync is the expected baseline. |
| **FUN-17** | Partner reporting suite | P1 | L | Realization, utilization, WIP, aged receivables, matter profitability — the KPIs a managing partner signs the cheque for. Needs ENG-23. |
| **FUN-18** | Office / department / practice-group hierarchy **within** a tenant | P1 | M | A multi-office firm is **not** multi-tenancy. Conflating them forces cross-tenant queries the architecture deliberately removed. Model this early. |
| **FUN-19** | Mobile (PWA first) | P3 | L | Hearing-day usage is inherently mobile. |

---

## 10. Epic H — Experience (`UI-*`)

| ID | Title | P | Size | Notes |
|---|---|---|---|---|
| **UI-13** | Tenant branding and theming | P1 | M | Firm logo, colours, subdomain, invoice/PDF letterhead. Pairs with the design-token sweep that `scripts/audit-hardcoded-colors.js` was written to scope — flip `AS_CI_GATE` on once done. |
| **UI-14** | Finish the `DataTable` conversion | P1 | M | `invoices/items/index.vue` and the three nested tables in `cases/view-case.vue` were deliberately deferred in FUN-1/UI-4. Blocks ENG-16. |
| **UI-15** | Accessibility pass 2 + automated axe checks in CI | P2 | M | UI-10 fixed contrast and focus issues manually; make it a permanent gate. |
| **UI-16** | Firm switcher for multi-firm users | P2 | S | Needs TEN-5. |
| **UI-17** | Tenant-aware empty/onboarding states | P2 | S | A freshly provisioned tenant currently lands on empty tables. Pairs with PLAT-13. |

---

## 11. Suggested sequencing

**Phase 0 — Decide and de-risk (weeks 1–3).** `TEN-1`, `ENG-7`, `DATA-9`, plus all `CARRY-*`.
*Gate: ADR approved and CI observed green before any tenancy code is written.*

**Phase 1 — Tenancy foundation (weeks 4–16).** `TEN-2` → `TEN-7`, `TEN-10`, `TEN-11`, `TEN-12`, `DATA-8`, `DATA-13`, `ENG-11`, `ENG-24`, `TEN-17`.
*Gate: `TEN-10` green on `main`, two tenants provisioned, self-host mode boots from the same codebase.*

**Phase 2 — Scale hardening (weeks 12–24, overlapping).** `UI-14` → `ENG-16`, `ENG-19` → `ENG-22`, `ENG-25`, `DATA-10`, `DATA-11`, `TEN-14`, `SEC-10`.
*Gate: load test at projected tenant scale passes; restore drill executed.*

**Phase 3 — Platform and commercial (weeks 20–36).** `PLAT-1` → `PLAT-10`, `SEC-13`, `SEC-15`, `TEN-9`, `TEN-15`.
*Gate: a self-hosted customer can install, license and upgrade unaided; a SaaS tenant can be provisioned, billed and ejected.*

**Phase 4 — Product depth (continuous from week 24).** `FUN-9` → `FUN-18`, `SEC-11`, `SEC-12`, `SEC-14`, `UI-13`, `UI-15`–`UI-17`, `PLAT-11`, `PLAT-13`.

**The hard rule:** do not start Phase 4 features before Phase 1 completes. Every feature built before the tenancy decision lands has to be rewritten afterwards.

---

## 12. Flat import table

| ID | Title | Epic | P | Size | Depends on |
|---|---|---|---|---|---|
| CARRY-1 | Green CI run + branch protection | Carry-over | P0 | S | — |
| CARRY-2 | Commit `composer.lock` | Carry-over | P0 | S | — |
| CARRY-3 | Rotate TextSMS key, audit history | Carry-over | P0 | S | — |
| CARRY-4 | Sign off status mapping vs prod data | Carry-over | P1 | S | — |
| CARRY-5 | Rehearse legacy cutover on staging | Carry-over | P1 | M | CARRY-4 |
| CARRY-6 | Burn down PHPStan baseline | Carry-over | P2 | M | — |
| CARRY-7 | Run a real restore drill | Carry-over | P1 | S | — |
| TEN-1 | ADR: database-per-tenant | A | P0 | S | — |
| TEN-2 | Control-plane DB + tenant registry | A | P0 | M | TEN-1 |
| TEN-3 | Tenant resolution middleware (fail-closed) | A | P0 | M | TEN-2 |
| TEN-4 | Connection management + cell placement | A | P0 | M | TEN-3 |
| TEN-5 | Split identity (`identities` / `users`) | A | P0 | L | TEN-2 |
| TEN-6 | Tenant-prefix cache/queue/session/locks | A | P0 | M | — |
| TEN-7 | Tenant-aware migration runner | A | P0 | L | TEN-2 |
| TEN-8 | Schema drift detection | A | P1 | M | TEN-7 |
| TEN-9 | Tenant lifecycle automation | A | P1 | L | TEN-7 |
| TEN-10 | Cross-tenant isolation CI gate | A | P0 | M | TEN-3 |
| TEN-11 | System catalog vs tenant overlay | A | P1 | M | — |
| TEN-12 | Per-tenant object storage partitioning | A | P0 | M | — |
| TEN-13 | Per-tenant encryption + BYOK | A | P2 | L | TEN-12 |
| TEN-14 | Per-tenant backup/PITR + drills | A | P1 | M | TEN-2, CARRY-7 |
| TEN-15 | Region / residency pinning | A | P2 | M | TEN-4 |
| TEN-16 | Tenant relocation between cells | A | P2 | L | TEN-4 |
| TEN-17 | Single-tenant collapse mode | A | P1 | M | TEN-3, TEN-5 |
| DATA-8 | Per-tenant locked sequences | B | P0 | M | — |
| DATA-9 | ULID/UUIDv7 external identifiers | B | P1 | M | — |
| DATA-10 | Append-only ledger enforcement | B | P0 | M | — |
| DATA-11 | Audit partitioning + retention | B | P1 | M | — |
| DATA-12 | Enums / reference tables | B | P2 | M | TEN-11 |
| DATA-13 | Expand/contract policy + CI check | B | P0 | M | TEN-7 |
| DATA-14 | Multi-currency and tax | B | P2 | M | — |
| ENG-7 | API versioning `/api/v1` | C | P0 | M | — |
| ENG-8 | Form Requests | C | P1 | M | — |
| ENG-9 | Service/action layer | C | P1 | L | ENG-8 |
| ENG-10 | API Resources replace `formatRow()` | C | P1 | M | ENG-7 |
| ENG-11 | Policies + deny-by-default | C | P0 | L | — |
| ENG-12 | OpenAPI + generated client | C | P2 | M | ENG-7, ENG-10 |
| ENG-13 | Mix → Vite | C | P2 | M | — |
| ENG-14 | Vuex → Pinia | C | P3 | S | — |
| ENG-15 | TypeScript on the API boundary | C | P2 | L | ENG-12 |
| ENG-16 | Delete `paginatedOrFull` full-load branch | C | P1 | S | UI-14 |
| ENG-17 | Coverage gates on money/permissions | C | P1 | S | — |
| ENG-18 | Release engineering hygiene | C | P1 | S | — |
| ENG-19 | Search engine (per-tenant indexes) | D | P1 | L | — |
| ENG-20 | Redis + per-tenant queue lanes | D | P1 | M | TEN-6 |
| ENG-21 | Async firm-wide export | D | P1 | S | — |
| ENG-22 | Direct-to-storage uploads | D | P1 | M | TEN-12 |
| ENG-23 | Read replicas for reporting | D | P2 | M | — |
| ENG-24 | Observability baseline | D | P0 | M | — |
| ENG-25 | Per-tenant rate limiting | D | P1 | S | TEN-3 |
| ENG-26 | CDC/ETL → warehouse | D | P2 | L | TEN-2 |
| ENG-27 | Load/capacity testing | D | P2 | M | TEN-4 |
| PLAT-1 | Entitlement service | E | P0 | M | TEN-2 |
| PLAT-2 | Signed self-host license | E | P1 | M | PLAT-1 |
| PLAT-3 | Feature flags + edition gating | E | P1 | M | PLAT-1 |
| PLAT-4 | Subscription billing + metering | E | P1 | L | PLAT-1, ENG-24 |
| PLAT-5 | Self-host distribution artifact | E | P0 | L | TEN-17 |
| PLAT-6 | `app:upgrade` + zero-downtime deploy | E | P0 | M | PLAT-5, DATA-13 |
| PLAT-7 | Tenant admin console | E | P1 | M | TEN-9 |
| PLAT-8 | Audited support impersonation | E | P1 | M | PLAT-7 |
| PLAT-9 | Eject / import as product features | E | P1 | L | TEN-9 |
| PLAT-10 | Customer changelog + upgrade guides | E | P1 | S | ENG-18 |
| PLAT-11 | Public API + webhooks | E | P2 | L | ENG-7, ENG-12 |
| PLAT-12 | Health, status page, SLA | E | P1 | S | ENG-24 |
| PLAT-13 | Self-service trial + onboarding | E | P2 | M | TEN-9, PLAT-4 |
| SEC-10 | Matter-level access control | F | P0 | L | ENG-11 |
| SEC-11 | Ethical walls / conflict screens | F | P1 | L | SEC-10 |
| SEC-12 | Per-tenant SSO + MFA | F | P2 | L | TEN-5 |
| SEC-13 | Retention, legal hold, DSAR | F | P1 | L | TEN-9 |
| SEC-14 | Pen test + SOC2/ISO readiness | F | P2 | L | — |
| SEC-15 | Secret management | F | P1 | S | CARRY-3 |
| FUN-8 | Document content search (OCR) | G | P1 | L | ENG-19 |
| FUN-9 | Conflict-of-interest check at intake | G | P1 | M | — |
| FUN-10 | Client portal | G | P1 | XL | SEC-10 |
| FUN-11 | Payment gateway (M-Pesa + card) | G | P1 | L | DATA-14 |
| FUN-12 | Trust three-way reconciliation | G | P1 | L | DATA-10 |
| FUN-13 | LEDES / UTBMS e-billing | G | P2 | M | — |
| FUN-14 | Document assembly + e-signature | G | P2 | XL | — |
| FUN-15 | Email ingestion into matters | G | P2 | L | — |
| FUN-16 | Calendar sync + court diary | G | P2 | L | — |
| FUN-17 | Partner reporting suite | G | P1 | L | ENG-23 |
| FUN-18 | Office/practice-group hierarchy | G | P1 | M | — |
| FUN-19 | Mobile (PWA) | G | P3 | L | — |
| UI-13 | Tenant branding and theming | H | P1 | M | TEN-2 |
| UI-14 | Finish `DataTable` conversion | H | P1 | M | — |
| UI-15 | Accessibility pass 2 + axe in CI | H | P2 | M | — |
| UI-16 | Firm switcher for multi-firm users | H | P2 | S | TEN-5 |
| UI-17 | Tenant-aware onboarding states | H | P2 | S | PLAT-13 |

---

## 13. Open decisions needing an ADR before their dependent work starts

| Decision | Blocks | Recommendation |
|---|---|---|
| Tenancy model | Everything | Database-per-tenant (TEN-1) |
| MySQL vs PostgreSQL | TEN-4, TEN-7 | **Decided 2026-10-08: stay on MySQL/MariaDB** (docs/adr/0002-database-engine-mysql.md). Backups, point-in-time restore, drills, the cutover and the capacity figures are all built and measured on it |
| Where identity lives | TEN-5, SEC-12 | Control-plane `identities` + tenant `users` (forced by the existing `users` FKs) |
| Search engine | ENG-19, FUN-8 | Meilisearch for operational simplicity; Typesense if multi-tenant index isolation proves cleaner |
| Hosting topology | TEN-4, TEN-15 | Cells of 200–500 tenants; region per cell |
| Pricing/metering dimensions | PLAT-4, ENG-24 | Decide before instrumenting — metrics are hard to backfill |

---

## 14. Audit — what is built and what remains (checked against the code, 2026-10-02)

Every item above was checked against the code, tests and docs in this repository (not against the changelog). Ticked boxes in §2–§10 are criteria verified in code or tests; unticked boxes are open. **Done** = every acceptance criterion met; **Partial** = some criteria met; **Open** = nothing built. Items marked *(ops)* can only be closed outside the repository.

**Totals (88 items, updated 2026-10-09): 78 done · 10 partial · 0 open.** Every partial item waits on something outside the repository (a setting, an account, production access or people).

>  **CI (updated).** `.github/workflows/ci.yml` now runs on every pull request: `backend` (SQLite: migration lint, release check, PHPStan, Unit + Feature with coverage floors), `backend-mysql` (the same suites on MySQL 8), `frontend` (ESLint errors, production build) and `e2e` (all 37 Playwright journeys on MySQL). Every job's commands were run locally to green (MariaDB 10.11 standing in for MySQL 8). Still manual: observing the first run on GitHub and making the checks required on `main` (a repository setting).
>
> **ID collision.** Code comments already use `UI-13` … `UI-29` for earlier UI fixes (e.g. `UI-15` = unsaved-changes guard in `crud-modal.vue`). The UI-13–UI-17 items in §10 should be renumbered (e.g. UI-30+) before anyone cites them.

### Carry-over

| ID | Status | Evidence | Remaining |
|---|---|---|---|
| CARRY-1 | Partial | `ci.yml` (backend, backend-mysql, backend-multi, frontend, e2e) green on GitHub runners; coverage floors in `scripts/coverage-floors.php` | Require the checks on `main` *(repository setting: 5 PRs were merged before CI ran)* |
| CARRY-2 | Done | `composer.lock` committed | — |
| CARRY-3 | Partial | key removed from code; history scanned with gitleaks 8.21.2 and by hand: **no secrets** in this repository's 100 commits (its history starts after the key was removed); `secrets-scan.yml` scans every commit on each PR and push; docs/ops/SECRETS.md | Rotate the key at TextSMS and revoke the old one *(needs the provider account; runbook and proof in docs/ops/SMS_KEY_ROTATION.md, `secrets:fingerprint --retired=`)* |
| CARRY-4 | Partial | the mapping is code (`config/legacy_mapping.php`) and matches the upgrade migration that runs (the earlier reconstruction disagreed on statuses 1, 4 and 5); `migration:status-report` counts every legacy status/archive/enum value in a database (found from the schema, soft-deleted rows included), sets it against the mapping and writes the sign-off sheet with the open questions; `app:upgrade` refuses a legacy database holding an unmapped value; `LegacyCutoverTest`; docs/ops/MIGRATION_STATUS_MAPPING.md | Run the report against production and have it signed by someone who knows the old system *(ops: needs production access)* |
| CARRY-5 | Partial | `migration:rehearse-cutover` restores a dump (or copies a database) into a scratch database, preflights (mapping, orphans, duplicates the upgrade renames, line totals, missing files), runs the real `app:upgrade`, verifies (rows, invoice states vs mapping, money to the cent, totals, password hashes, files, app reads, no pending migrations), times every phase and separates downtime from background backfills; `migration:legacy-fixture` builds a practice legacy database; rehearsed on practice data (60 matters with planted problems: all caught; 5,000 matters: passed, 11.4 s of schema migrations); runbook docs/ops/LEGACY_CUTOVER.md; `LegacyCutoverTest` (MySQL CI) | Rehearse on staging with a copy of production and record it *(ops: needs staging and a production dump)* |
| CARRY-6 | Done | Baseline **empty** (146 → 0): every finding fixed, none ignored. 51 empty unrouted resource-controller stubs and an empty `DocumentAccessController` deleted, stale `@param`/`@return` docblocks corrected, 37 unnecessary `?->` before `??` simplified, dead checks removed (`Permission::create`, `Task::create`), `Invoice::refreshTotals` reads its aggregates with `toBase()`; PHPStan level 5 clean with no baseline | Raising the level (6+) is a separate decision |
| CARRY-7 | Partial | `backup:drill` restores the newest `backup:run` dump + storage archive beside the install (scratch database and directory), checks tables, migration history against the code, model reads and every local document file, times each phase, keeps a JSON report, logs critical on failure; scheduled monthly; first drill recorded in docs/ops/BACKUP_STRATEGY.md (260k rows + 20,000 files, 8.6 s); `BackupDrillTest` (real dump and restore on MySQL CI) | Run it once on production backups and record the time *(ops)* |

### Epic A — Tenancy

| ID | Status | Evidence | Remaining |
|---|---|---|---|
| TEN-1 | Done | `docs/adr/0001-database-per-tenant.md` records rejected options and accepted costs; **Accepted** by the product owner on 2026-10-09 | — |
| TEN-2 | Done | `control` connection, `tenants` (region, plan, cell, database, `files_disk`, `queue`, schema_version), `tenant_domains`, `cells` registry (with per-cell `files_disk`/`queue`); the app follows the row (uploads, DSAR, audit archives, job lanes); multi-mode `database` queue and failed jobs on the control plane; rule documented; `TenantLocationTest` | — |
| TEN-3 | Done | `ResolveTenant`, `TenantResolutionTest`, `QueueIsolationTest` | — |
| TEN-4 | Partial | `cells` registry + `cells` command + console view; firm connections built per cell; automatic placement by utilisation and capacity; runbook `docs/ops/CELLS.md` with per-cell ceiling and signals; `cells:latency` measures connect, query and per-request database time per cell, with and without a proxy (`--direct-host`), with a p95 limit; first figures recorded (loopback: a proxy hop adds ~1.3 ms per request); `CellsTest`, `CellLatencyTest` | *(infra)* run `cells:latency` on the real cells and proxy and record the figures |
| TEN-5 | Done | `identities`, `tenant_memberships`, `TenantIdentityTest` | — |
| TEN-6 | Done | `TenantContext` prefixes cache, file cache, permission key, sessions; locks and rate limits via the cache prefix; queued jobs carry their firm (TEN-3); `StateIsolationTest`; `CacheNamespacingRulesTest` fails the build on any way around the prefix | (Per-firm queue *lanes* are ENG-20) |
| TEN-7 | Done | `tenants:migrate [--queue] [--resume] [--retry-failed]` (`MigrateTenant` jobs, unique per firm, platform jobs with no firm), per-firm lock (`busy`, console 409), `tenants:status` and `/fleet` with latest version and behind/pending/running/failed counts, dashboard at `/api/platform/dashboard`; `TenantMigratorTest`, `TenantMigrationQueueTest` | — |
| TEN-8 | Done | `schema:drift` (scheduled daily) vs. a reference built from each firm's own applied migrations; logs an error, records `tenants.schema_drift`, fleet view lists it; `SchemaDriftTest` + verified on MariaDB | (Alerting = the error log, which reaches the error tracker (ENG-24) when one is configured) |
| TEN-9 | Done | `TenantLifecycle`, `tenants:*` commands, platform API; offboarding queues a verified final export (`tenants:final-export`, console download); purge requires it, runs DB → files → `PurgeStep`s (key, warehouse) → sign-in → export deletion and records `purge_report`; daily `tenants:purge-due`; `TenantLifecycleTest`, `TenantDeletionTest`; docs/ops/FIRM_DELETION.md | — (key destruction: TEN-13; warehouse expunge: ENG-26) |
| TEN-10 | Partial | `tests/Isolation` (two provisioned firms: API, files, cache, sessions, queue, commands, exports, search); `isolation.yml` runs it twice: default drivers, and production drivers (`isolation-drivers`: one MySQL 8 server, one Redis for cache/sessions/queue, one S3 bucket) with `DriversIsolationTest` proving the run uses them; that run found and fixed a cross-firm cache flush on Redis (`TenantRedisStore`) | Make `isolation` and `isolation-drivers` **required** checks on `main` (GitHub setting) |
| TEN-11 | Done | `App\Catalog\SystemCatalog`: courts, court types, hearing types, and (with DATA-14) currencies and Kenyan VAT rates, synced into every firm on upgrade/provisioning; the firm keeps which currencies are enabled, its default rate and archiving; system rates cannot be renamed or re-rated through the API (409 `system_catalog`); UTBMS codes stay in code by decision; `SystemCatalogTest`, `CurrencyTaxCatalogTest`; `docs/SYSTEM_CATALOG.md` | Tax rates for jurisdictions other than Kenya (firms add their own) |
| TEN-12 | Done | `storagePrefix()` on every write, checksum on write; `storage:iam-policy` (role confined to `tenants/${aws:PrincipalTag/tenant}/*`) and per-firm STS credentials (`TENANCY_STORAGE_ROLE_ARN`); `documents:relocate` moves older files with SHA-256 verification; imports re-home every file; `FilesIsolationTest`, `StorageIsolationTest`; docs/ops/STORAGE_ISOLATION.md | Not exercised against real AWS here; local disks have no IAM layer |
| TEN-13 | Done | Each firm gets a 256-bit data key at provisioning (`tenants:keys --enable` for existing firms), stored only wrapped in control-plane `tenant_keys`, bound to the firm, by the platform master key (AES-256-GCM, `TENANCY_MASTER_KEY`, rotatable with `--rewrap`) or by the firm's own AWS KMS key (`--customer-key`, BYOK; also SSE-KMS on its S3 files incl. direct uploads); the firm's stored secrets go through `FirmCrypt` and `secrets:rotate` moves them onto the key; tenant backups are encrypted with it (secretstream, `tenants:decrypt-backup`); `EncryptionKeyPurgeStep` destroys it on purge (crypto-shred); `FirmEncryptionTest`; docs/ops/FIRM_ENCRYPTION.md | Live firm database rows rely on server-level encryption at rest (no per-firm DB encryption); BYOK for KMS outside AWS |
| TEN-14 | Done | `tenants:snapshot` (nightly): per-firm dump with binary-log coordinates + local files archive + manifest, encrypted with the firm key; `tenants:restore --to=` restores beside the live database (snapshot, then binlog replay on MySQL/MariaDB), checks every referenced file is present or recoverable (snapshot archive / S3 object versions) and refuses to switch an inconsistent pair; `--switch --confirm` suspends, restores files, switches, keeps the old database, re-snapshots; weekly `tenants:restore-drill` (oldest-drilled firm first) checks tables, rows, files and an app read, logs to `tenant_restores` and at critical on failure; `FirmRestoreTest`, `MysqlPointInTimeRestoreTest` (real binlog replay, MySQL CI job); docs/ops/FIRM_RESTORE.md | Whole-cell disaster recovery onto another server is a separate exercise |
| TEN-15 | Done | a firm with a region is only placed in / moved to cells of that region (`CellsTest`); the cell sets its database server, bucket (`files_disk`) and queue lane, recorded on the firm (`TenantLocationTest`); residency statement and its limits: docs/ops/DATA_RESIDENCY.md | The control plane is global (identities, firm metadata); per-region control planes are not built |
| TEN-16 | Done | `tenants:relocate`: read-only window (503 on writes), PLAT-9 bundle copy, verified import, registry switch, automatic rollback, old database kept or `--drop-old`; `CellsTest` | (Rehearse between two real MySQL servers on staging) |
| TEN-17 | Done | single mode is the default; deployment-mode branching banned in domain code by `EntitlementsTest` (the sign-in page's extras come from `Tenancy\LoginPage`, the plan overlay asks `hasControlPlane()`, demo tooling asks `requireMulti()`), so only infrastructure reads the mode; `backend-multi` CI job runs Unit+Feature inside a provisioned firm (`TEST_TENANCY_MODE=multi`); self-managed tenancy tests opt out (`OwnsTenancy`), self-hosted-only tests listed in `tests/single-mode-baseline.txt` (only shrinks); docs/TESTING_MODES.md | — |

### Epic B — Data model

| ID | Status | Evidence | Remaining |
|---|---|---|---|
| DATA-8 | Done | `SequenceService`, `NumberingTest`, `NumberingConcurrencyTest`, `INVOICE_NUMBER_PER_YEAR` | (Format is per deployment, not per firm) |
| DATA-9 | Done | `HasPublicId` (ULID `public_id`) on clients, matters, hearings; public API and webhooks use only public ids; client page URLs use them; queued `BackfillPublicIds`; `PublicIdTest` | Contract step (make `public_id` NOT NULL) in a later release; give any newly exposed resource `HasPublicId` |
| DATA-10 | Done | DB triggers (`enforce_append_only_ledgers`), `LedgerImmutabilityTest` | — |
| DATA-11 | Done | `audits:archive` (monthly): months older than the plan's `audit_online_months` go to verified gzip JSON-lines archives under the firm prefix (`audit_archives`), removed by dropping the month's partition (`audits:partition --enable`, MySQL/MariaDB) or in batches; archives kept max(archive, floor) years, never during a legal hold; `audits:restore`; `AuditArchiveTest`; docs/ops/AUDIT_RETENTION.md | Partition rebuild of existing large tables not timed (DATA-13 volume tests) |
| DATA-12 | Done | `app/Enums` (`MatterStatus` with its transitions, `InvoiceWorkflow`, `PaymentStatus`, `PaymentMethod`, `TrustEntryType`, `TaskPriority`, `TaskStatus`) used by Form Requests, domain logic and a model guard (`ConstrainedToEnums`) that refuses any save outside the enum; MySQL/PostgreSQL CHECK on `tasks.priority`; matter types are a `case_types` list (case-insensitive, normalised, archivable) with `cases.case_type_id` filled on save and backfilled by the queued `BackfillCaseTypes`; `EnumsAndCaseTypesTest`; docs/DATA_ENUMS.md | Drop the free-text `cases.case_type` column in a later release (contract step); DB CHECKs for the other enum columns |
| DATA-13 | Done | `docs/MIGRATION_POLICY.md`, `migrations:lint` (CI, `allow` with reasons), backfills as queued jobs; `migrations:rehearse` times migrations at volume and fails one over budget: `--largest` on a copy of the biggest firm's latest snapshot before a fleet upgrade (runbook step), `--synthetic=20000 --last=15` on MySQL in CI (`App\Database\VolumeFiller`, ~260k rows); `MigrationRehearsalTest` | — |
| DATA-14 | Done | `currencies`, `exchange_rates`, `tax_rates`; firm base currency (locked once invoices are issued); issuing an invoice fixes the rate on or before its date and its base-currency totals (no rate, no issue); lines charge tax from a rate and keep its percentage; partner reports and the dashboard in the base currency at each invoice's own rate, with unconverted invoices listed apart; `BackfillInvoiceFx`; Settings → Currencies and tax; `MoneyTest`; docs/MONEY.md | Rates are entered by hand (no rate feed); time rates and expenses are base-currency only |

### Epic C — Application architecture

| ID | Status | Evidence | Remaining |
|---|---|---|---|
| ENG-7 | Done | `/api/v1` prefix, `DeprecatedApiVersion`, `docs/API_VERSIONING.md` | — |
| ENG-8 | Done | every controller rule set is a Form Request (`app/Http/Requests/<Controller>/<Action>Request`, 80+ classes); update rules read the record from the route; sign-in endpoints keep their error shape; `FormRequestsTest` forbids inline `validate()`/`Validator::make` in controllers and authorization inside requests (it stays on the route) | — |
| ENG-9 | Done | 22 actions in `app/Actions` (matters, clients, invoices and lines, payments, trust, time and billing, hearings, documents) take the acting person explicitly (`null` = system) and throw `ActionException`; controllers, the client portal, online payments, email filing, templates and signatures call them; payments, trust voids, billing and document versions lock what their rule reads; `ActionsTest` runs them with no request or user, `ActionsArchitectureTest` keeps writes out of the domain controllers and HTTP out of actions; docs/ACTIONS.md | Remaining controllers (settings, admin screens) move as they are next changed |
| ENG-10 | Done | one API Resource per entity in `app/Http/Resources/V1` (14, moved unchanged from the controllers' `formatRow()`); the namespace versions the shape with the API (a v2 shape is a new class); `ApiResourcesTest` keeps formatters out of controllers | — |
| ENG-11 | Done | route-level deny-by-default (`RequireAuthzDeclaration`, `RouteAuthorizationTest`); a `ModulePolicy` per model (`app/Policies`: permission per ability, record abilities checked against `MatterAccess`, unmapped abilities refused); `authz:policy` route mode; the last `authz:inline` routes (invoice delete, bulk delete, search) moved to policies and the mode removed; `PermissionMatrixTest` asserts default deny for every model | — |
| ENG-12 | Done | `php artisan api:openapi` generates `docs/openapi.json` (every staff `/api/v1` route: paths, `x-authz`, request bodies from Form Requests, answers from Resource shapes) and `resources/js/src/api/client.generated.ts` (one typed function per operation); `--check` in CI plus `OpenApiTest` fail on drift; the spec is uploaded as a CI artifact; Form Request rules made database-independent (`App\Rules\FirmCurrency`); finance settings page uses the client; public API keeps `docs/openapi-public.yaml`; docs/OPENAPI.md | Query filters of list endpoints and hand-built answers are described generically; other screens move to the client as they are touched |
| ENG-13 | Done | `vite.config.js` (laravel-vite-plugin, plugin-vue, `@`/`@themeConfig` aliases, Sass load paths) builds dev and prod into `public/build`; Blade uses `@vite()`; Mix, webpack, vue-cli and their loaders removed (`npm audit`: 0 vulnerabilities); Dockerfile, release bundle, docker-compose, service worker and nginx (hashed `/build/` immutable, `sw.js` no-cache) updated; CI keeps `npm run production`; full e2e (38) passes on the Vite build | — |
| ENG-14 | Done | Vuex removed; `stores/app.js` (Pinia `useAppStore`: layout, sidebar, theme, menu and layout style), one `pinia` instance (`stores/index.js`) also used by the router guard and start-up settings outside components; e2e `01-auth-dashboard`, `06-rbac` pass | — |
| ENG-15 | Done | Each v1 Resource declares its `SHAPE`; `php artisan api:types` generates `resources/js/src/api/types.generated.ts`; `ApiShapesTest` renders every Resource for real records against its shape and fails on a stale file; `npm run type-check` (vue-tsc, strict for TS) is a CI gate; `api.ts` typed client; time-entries page migrated as the pattern; ESLint parses TS; docs/TYPESCRIPT.md migration path | Non-Resource endpoints (reports, search) typed at use |
| ENG-16 | Done | The full-table branch is removed: every list endpoint returns one page (`per_page` capped at 100) with or without `?page=`; dropdowns use capped, searchable `/options` endpoints; bounded in-page lists walk pages (`fetchAllPages`); `ListEndpointsFormatRowTest` pins it | — |
| ENG-17 | Done | `scripts/coverage-floors.php` in CI: floors on invoices, payments, trust, sequences, billing, authz middleware, matter access, tenancy; new `InvoiceWorkflowApiTest` lifted the money controllers from 24–75% to 90–95% | — |
| ENG-18 | Done | `CHANGELOG.md`, `release:draft/notes/check`, `release.yml`; conventional commits enforced on every PR commit and title (`commits.yml`, `release:draft --strict`, CONTRIBUTING.md) and every commit since the squashed start of history follows them; first release cut with `scripts/release.sh 1.0.0` (`v1.0.0`, upgrade guide `docs/upgrade/1.0.0.md`) | — |

### Epic D — Scale & operability

| ID | Status | Evidence | Remaining |
|---|---|---|---|
| ENG-19 | Done | `SEARCH_ENGINE=meilisearch`: per-firm indexes (`<prefix><firm>_cases/clients/documents`, dropped on purge), relevance ranking and typo tolerance, document text included; walls and restricted access applied by the engine at query time (`SearchFilter` on `access_users`, grants, walls), rows re-read through scopes so stale entries never show; observers + `IndexForSearch` keep it current, `search:reindex` rebuilds; falls back to SQL if the engine fails; Helm `search.*`; `SearchEngineTest`, `MeilisearchIntegrationTest` (real engine in CI); docs/SEARCH.md | `database` stays the default engine; writes made with raw SQL need `search:reindex` |
| ENG-20 | Done | Interactive and bulk lanes per cell lane (`Lanes`, `BulkWork`: exports, document reading, backfills); `FirmFairShare` caps each firm's concurrent bulk jobs with cache-lock slots (`QUEUE_BULK_PER_FIRM`), waiting does not burn attempts; Redis for cache/session/queue in the image (phpredis), Helm (`redis.host`, scalable `queue.replicaCount`, optional `bulkQueue` workers) and entrypoint (`QUEUE_WORKER_QUEUES`); `retry_after` raised above the longest job; `QueueLanesTest`, `RedisIntegrationTest` (real Redis in CI), Helm render test; docs/ops/QUEUES.md | No Horizon dashboard; queue health still measures the interactive lane only |
| ENG-21 | Done | `POST /export/firm-data` -> `BuildFirmExport` job -> signed, expiring download (requester's session only) + email; `exports:prune` hourly; dashboard uses it; `FirmExportQueueTest` | Remove the deprecated synchronous GET in the next major |
| ENG-22 | Done | `DirectUploads`: `POST /documents/uploads` reserves a key under the firm prefix and answers a signed S3 POST policy (exact key, type and length, 15 min); the browser posts to the bucket; `…/complete` checks presence, size and sniffed type, hashes and files through `StoreDocument`; `documents:prune-uploads` hourly; web app falls back to the API upload without a bucket; up to `DOCUMENTS_DIRECT_MAX_MB` (500); `DirectUploadsTest`; docs/ops/DIRECT_UPLOADS.md | Client-portal uploads still go through the API (25 MB) |
| ENG-23 | Done | `ReadReplica` + `replica` route middleware: partner reports and the dashboard read from the replica (`DB_REPLICA_HOST`, a cell's `replica_host`, or `REPORTS_DB_CONNECTION`); health checked every 30 s, with a fallback to the primary when unreachable, stopped or beyond `DB_REPLICA_MAX_LAG`; `X-Data-Source`/`X-Data-Lag` headers; reports page and dashboard say "figures are about N minutes behind" from 60 s; `ReadReplicaTest`, docs/ops/READ_REPLICA.md | Lag is measured per server (`SHOW REPLICA STATUS`); no per-firm heartbeat |
| ENG-24 | Done | `RequestContext` (request + tenant ids in logs), `UsageMetrics` incl. API calls; traces over OTLP/HTTP (`Tracer`, `TraceRequests`: a trace per request and per queued job, firm and request id on the root span, database statements as child spans without bound values, W3C `traceparent` continued and propagated to jobs, sampling); errors over the Sentry envelope protocol (`ErrorReporter`: tagged and grouped per firm, linked to the trace, no request data); both vendor-neutral and off until an endpoint is set; `TracingAndErrorTrackingTest`; docs/OBSERVABILITY.md | Pick and run the backends (configuration only); no metrics export or browser-side reporting |
| ENG-25 | Done | `api` limiter = per user/IP + per firm by plan (`config/api.php`); `429 firm_rate_limited`; public API keys keep their own per-key limit; `FirmRateLimitTest` | — |
| ENG-26 | Done | `warehouse:export` nightly: per-firm daily aggregates (usage, money per currency, feature events, firm dimension) with `tenant_key`/`tenant_id`; every row checked against `WarehouseSchema` (no free-text type, unknown columns refused) so privileged content cannot leave; sinks: partitioned gzip NDJSON files and/or a separate warehouse database (idempotent per day and firm); `WarehousePurgeStep` expunges a deleted firm; `WarehouseTest` plants client secrets and proves none arrive; docs/ops/WAREHOUSE.md declares it the only cross-firm store | Nightly batch, not CDC; loaders into a specific warehouse product are the operator's |
| ENG-27 | Done | Measured on 4 vCPU / 15 GB / MariaDB 10.11: `capacity:soak` (firms per server: 82 tables per firm; table cache is the first limit, then the buffer pool) and k6 `loadtest/k6/staff-day.js` (about 130 requests/s, 50 active people per 4 vCPU, p95 under 500 ms); ceilings and signals per resource (firms, tables, caches, memory, connections, web, queue, storage) in docs/ops/CAPACITY.md; `capacity:report` shows them per cell and exits 1 at 80 %; manual `loadtest` workflow; `CapacitySignalsTest`, `CapacitySoakTest` (real soak on MySQL) | Figures are from a shared dev box: re-measure per instance class |

### Epic E — Platform & dual deployment

| ID | Status | Evidence | Remaining |
|---|---|---|---|
| PLAT-1 | Done | `app/Entitlements`, `EntitlementsTest` | — |
| PLAT-2 | Done | `app/Licensing`, `docs/LICENSING.md` (offline, grace, read-only) | — |
| PLAT-3 | Done | `config/flags.php`, `docs/FEATURE_FLAGS.md` | (The web app does not read `/flags` yet) |
| PLAT-4 | Partial | `app/Billing`, proration, dunning, metered overage, Stripe/M-Pesa webhooks; outbound with the platform's own accounts (`Collection`): signed pay link opening a Stripe Checkout for the balance (in reminders and the console), M-Pesa STK push from the console with its callback, Daraja C2B URL registration (`billing:mpesa-register`); `BillingTest`, `BillingCollectionTest` (requests checked against the documented formats) ; saved cards and automatic charging: signed card-setup link (Stripe Checkout, setup mode), the setup webhook stores the card, `billing:run` charges open invoices off-session with idempotent, bounded, spaced retries and no retry when the bank wants the holder, staff can remove the card (`BillingAutopayTest`) | Run once against Stripe test mode and the Daraja sandbox with real keys (needs the accounts) |
| PLAT-5 | Partial | `Dockerfile`, `docker-compose.yml`, Helm chart, `INSTALL_SELFHOST.md`, `release.yml`; `v1.0.0` tagged | Publishing the image, chart and bundle: `release.yml` runs on the tag once GitHub Actions minutes are available; until then `scripts/publish-release.sh <registry/owner/repo>` does the same from any machine with Docker and Helm |
| PLAT-6 | Done | `app:upgrade`, `deploy/switch-release.sh`, `rollback.sh` | — |
| PLAT-7 | Done | Host-restricted `/console` staff UI plus `/api/platform/*`: overview, firm lifecycle/details, cells, billing, health/incidents, audit and MFA; role-based controls backed by API authorization; `PlatformConsoleTest`; `docs/PLATFORM_CONSOLE.md` | Platform staff accounts remain CLI-managed |
| PLAT-8 | Done | `docs/SUPPORT_ACCESS.md`, `SupportAccessTest` | — |
| PLAT-9 | Done | `portability:*`, versioned format, frozen fixture; round-trip tests now run in CI (`backend`, `backend-mysql`) | (Firm self-service download not built) |
| PLAT-10 | Done | `docs/upgrade/`, `release:notes`, `release:check` | — |
| PLAT-11 | Done | `docs/PUBLIC_API.md`, `PublicApiTest` | (Read-only API) |
| PLAT-12 | Done | `/health/*`, `/status`, `docs/STATUS.md` | — |
| PLAT-13 | Done | `SignupService`, `ProvisionSignup`, trial expiry → read-only, `converted_at`, `/api/platform/billing/funnel`, getting-started checklist, `SignupTest`, `docs/ops/SIGNUP.md` | No CAPTCHA (edge bot protection); paying for the chosen plan uses the PLAT-4 invoices/webhooks |

### Epic F — Security & compliance

| ID | Status | Evidence | Remaining |
|---|---|---|---|
| SEC-10 | Done | `MatterScope`, `docs/MATTER_ACCESS.md`, `MatterAccessTest` | — |
| SEC-11 | Done | `docs/ETHICAL_WALLS.md`, `EthicalWallTest` | — |
| SEC-12 | Done | OIDC and **SAML 2.0** per firm, **SCIM 2.0** user provisioning, TOTP MFA, firm MFA policy; web app: code step, SSO sign-in, enforced enrolment, Account Settings two-factor, Security settings page (protocol switch, SAML values, provisioning tokens); `SamlValidator` tested against independently signed and forged responses; docs/SSO.md | SAML IdP-initiated sign-in, SCIM groups and bulk are out by decision (docs/SSO.md) |
| SEC-13 | Done | Legal holds (model-level, audited), DSAR with identity verification, access export and erasure (a hold blocks it); retention per record class (`case_documents`, `matter_emails`, `portal_messages`, `inactive_clients`; financial records and the audit log never), each with its own period and auto-purge switch, holds re-checked before every disposal, `retention_disposals` + audit per disposal; Settings → Compliance (holds, retention, requests); `ComplianceTest`, `RetentionClassesTest`, e2e `11-compliance.spec.js`; docs/COMPLIANCE.md | Matter records (hearings, tasks, time) are not a disposable class; names copied into free text are not searched on erasure |
| SEC-14 | Partial | `docs/security/`: security overview (customer-facing, with placeholders for the operator's facts), DPA template (Kenya DPA 2019, GDPR Art. 28-compatible), SOC 2 / ISO 27001 gap assessment (technical controls largely in place; organisational ISMS controls are the gaps, with an order of work), penetration-test scope and rules; remediations done now: Laravel and league/commonmark advisories patched, npm audit clean, weekly `dependency-audit.yml`, password reset as strong as signup; security headers on every response (HSTS on HTTPS, CSP in report-only with a violation-report endpoint, Permissions-Policy) and ClamAV malware scanning of every upload, fail closed (`docs/security/SECURITY_HEADERS.md`, `SecurityHeadersTest`, `MalwareScanTest`); templates for the information security policy set and the incident response plan (`docs/security/POLICIES.md`, `INCIDENT_RESPONSE.md`) | Independent penetration test and its remediation; adopting the organisational controls (approve the policies and plan, risk register, access reviews, tabletop); running clamd and enforcing the CSP in production; legal review of the DPA *(ops / management)* |
| SEC-15 | Done | `FOO_FILE` secrets, `secrets:check/rotate`, encrypted firm credentials, `docs/ops/SECRETS.md` | — |

### Epic G — Product depth

| ID | Status | Evidence | Remaining |
|---|---|---|---|
| FUN-8 | Done | `TextExtractor` (text, docx, xlsx, pdftotext, scans via pdftoppm + tesseract OCR, photos), queued `ExtractDocumentText` on every upload + `BackfillDocumentText` on upgrade, `document_texts` with a MySQL full-text index (boolean mode: all words, prefixes, phrases; scan fallback), matter access applied inside the query, Documents-tab search with highlighted passages and per-document text state, global search by content, poppler + tesseract in the image, `DocumentSearchTest`, `DocumentFullTextTest`, `docs/DOCUMENT_SEARCH.md` | Search engine with typo tolerance (ENG-19); legacy .doc/.xls |
| FUN-9 | Done | parties register (`matter_parties`, opposing party mirrored + backfilled), `ConflictSearch` (firm-wide, normalised/fuzzy names, phone, ID; masked for matters the checker cannot open), `conflict_checks` record with decisions, intake gate in the web app and API, firm policy, Conflicts page, `ConflictCheckTest`, `docs/CONFLICTS.md` | — |
| FUN-10 | Done | `/portal` for clients: sign-in by SMS code (HMAC'd, 10 min, 5 tries, no enumeration), hashed bearer sessions (idle 1 h / max 12 h, ended when access is withdrawn), own matters + hearing dates, documents shared both ways (audited, first-viewed), per-matter messages with SMS nudges, issued invoices with Pay (FUN-11 link); staff matter tab, `manage-client-portal`, plan feature `client_portal`, `ClientPortalTest`, `docs/CLIENT_PORTAL.md` | Email sign-in/notifications; several contacts per client; staff unread badge in the nav |
| FUN-11 | Done | expiring hashed payment links (sent by SMS), public `/pay/{token}` page, M-Pesa STK push (`MpesaGateway`) and Stripe Checkout (`StripeGateway`), per-attempt callback secret / signed webhook, idempotent completion capped at the balance into the normal payments ledger, per-firm encrypted merchant credentials, invoice panel, `OnlinePaymentTest`, `docs/ONLINE_PAYMENTS.md` | Refunds and payment plans; client portal entry point is FUN-10 |
| FUN-12 | Done | `App\Trust\Reconciliation` (bank / ledger / clients in cents, voids counted until voided), clearing without touching the append-only ledger, sign-off freezes figures + client snapshot (exceptions need a reason), auditor CSV, evergreen retainer policies + SMS replenishment requests, Trust Accounting page, `TrustReconciliationTest`, `docs/TRUST_RECONCILIATION.md` | One trust bank account per firm |
| FUN-13 | Done | ABA litigation UTBMS task/activity/expense codes (`App\EBilling\Utbms`) on time entries, invoice lines and expenses (copied to the bill), client e-billing ID, client matter reference, timekeeper classification, firm LEDES ID; `Ledes1998B` export with a pre-flight check (errors block, warnings advise), tax as an IF adjustment, reserved characters escaped; pickers in the SPA, `EBillingTest`, `docs/EBILLING.md` | LEDES 1998BI / XML 2.x; non-litigation code sets; portal submission |
| FUN-14 | Done | Word templates with `{{ fields }}` (matter, client, advocate, firm, dates) and `{{ ask: … }}` questions, run-splitting handled, headers/footers, drawings left intact; documents created on the matter; e-signature by personal email/SMS links (any order or in sequence), typed name + drawn signature + intent, SHA-256 checked at signing, decline/remind/cancel/expiry, PDF signature certificate filed; Templates page, `manage-document-templates`, `DocumentAssemblyTest`, `docs/DOCUMENT_ASSEMBLY.md` | Signatures stamped into the PDF; advanced e-signatures via a certification provider; Word→PDF |
| FUN-15 | Done | per-matter filing address + inbound webhook (raw MIME from Mailgun/SendGrid/any MTA; URL secret, optional Mailgun signature), own MIME parser (multipart, QP/base64, charsets, RFC 2047/2231, forwarded messages), sender policy (staff / firm domains / anyone), dedupe by Message-ID, conversation linking, original .eml kept with checksum, attachments become searchable matter documents, `.eml` upload, Emails tab, `EmailFilingTest`, `docs/EMAIL_FILING.md` | Outlook .msg; IMAP/Graph mailbox pull |
| FUN-16 | Done | hearings gain time, length, courtroom, judicial officer, e-filing reference; printable court diary (matter access applied); secret iCalendar feeds (mine / court diary, RFC 5545, notes never included); Google Calendar + Microsoft 365 OAuth sync (tokens encrypted, state bound to person and firm host): push on every change + every 15 min, tasks moved in the calendar move here, hearings moved there are put back; `CalendarTest`, `docs/CALENDAR.md` | Filing through the judiciary e-filing portal (no public API; the reference is recorded); shared/team calendars |
| FUN-17 | Done | `App\Reports\PartnerReports`: utilisation, billing/collection realisation, aged WIP, aged receivables, matter profitability (roll-up by lawyer/office/practice group), limited to the viewer's matters, `reports.connection` ready for a replica, Partner Reports page + CSV, `PartnerReportsTest`, `docs/PARTNER_REPORTS.md` | No cost rates (contribution, not margin) |
| FUN-18 | Done | `offices`, `practice_groups`, people and matters belong to one of each (matter defaults to its lawyer's), filters, move people with their open matters, Settings → Offices & Groups, `OrganisationTest`, `docs/ORGANISATION.md` | Office-scoped visibility is matter access's job (SEC-10), not built |
| FUN-19 | Done | per-firm web app manifest + icons, service worker (versioned assets cache-first, pages network-first with offline shell, only the hearing-day data kept offline and wiped on sign-in/out, client-facing pages untouched), *Today* view for the phone (today/tomorrow hearings, call client, tasks due), outcome + next date in one step, `MobileTodayTest`, `docs/MOBILE.md` | Push notifications; offline write queue; store apps |

### Epic H — Experience

| ID | Status | Evidence | Remaining |
|---|---|---|---|
| UI-13 | Done | Settings → Branding (`manage-branding`): name, primary colour, logo (PNG/JPEG/WebP checked by bytes; SVG refused), letterhead lines and invoice footer, stored with the firm; the web app derives its `--primary-*` scale from the colour at runtime (`branding.ts`) and shows name and logo in sidebar, header, sign-in, tab title and manifest; invoice PDFs and signature certificates carry the letterhead; the hard-coded firm name removed from six places; 68 primary literals moved onto tokens and `audit-hardcoded-colors.js` is now a ratcheting CI gate (692); `BrandingTest`; docs/BRANDING.md | 692 other hard-coded colours remain (greys, status colours): sweep them down under the ratchet |
| UI-14 | Done | Every list page uses the server-paginated `DataTable` (29 views). The two plain tables left are not lists: `cases/view-case.vue`'s are the print-only case summary, and `invoices/items/index.vue` is one invoice's line-item editor (lines are never paginated) | — |
| UI-15 | Done | axe-core (WCAG 2.1 A/AA) over the sign-in pages, 28 signed-in screens, a dialog and a light brand colour in the Playwright suite (`10-accessibility.spec.js`), failing CI on any violation; pass 2 fixed 284 findings: zoom allowed, `--primary-600` made readable for any firm colour, secondary text on a `--text-muted` token (dark mode unchanged), badge/button/code contrast, sortable headers keep `aria-sort` with a real button inside, sidebar list markup, account-settings labels; docs/ACCESSIBILITY.md | Screens not yet covered (matter and invoice detail, portal, platform console); manual keyboard/screen-reader review of new flows |
| UI-16 | Done | Account menu lists the person's firms (`GET /firms`); `POST /firms/{slug}/switch` issues a single-use, 60-second token (hash only, control plane) redeemed at `/switch/{token}` on the target firm's host, which signs the person in there with a fresh session; the target firm's SSO enforcement and two-factor are never skipped (sent to its sign-in instead); audited on both sides; `FirmSwitchTest`, `FirmListSingleModeTest`; docs/FIRM_SWITCHER.md | — |
| UI-17 | Done | Getting-started checklist on the dashboard (PLAT-13); list empty states with an "add" action (DataTable `empty-action`); trial/read-only banner | — |
