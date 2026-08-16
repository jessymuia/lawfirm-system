# System Review, Gap Analysis & Migration Plan

**Project:** Law Firm Management System
**Reviewed:** 2026-08-16
**Current stack:** Laravel 9 (PHP 8.0) · Vue 3 SPA (Laravel Mix / Webpack) · MySQL · Sanctum · Spatie Permissions · Laravel Auditing · Cork/VRISTO admin template

This document records every gap found in the current system, the proposed target stack, UI improvements, the testing & CI strategy for the new system, a data-migration plan that guarantees users can carry all their data from the current system to the new one, and a validation checklist to sign off each gap as resolved.

---

## 1. Gap Analysis

Each gap has a stable ID (`SEC-*`, `DATA-*`, `FUN-*`, `UI-*`, `ENG-*`) referenced by the validation checklist in §7.

### 1.1 Security

| ID | Gap | Location |
|----|-----|----------|
| SEC-1 | SMS gateway API key, partner ID and a session cookie are hardcoded and committed to the repository (also present in git history — key must be **rotated**, not just removed) | `app/Helper.php` |
| SEC-2 | A file named `password` is committed at the repository root | `/password` |
| SEC-3 | Permission checks are wrong or missing: Cases & Hearings check the *case documents* permission; Expense checks are commented out; Client `update`/`clientsDropDown` and Document `show` have no checks; document preview gates on `create-cases` instead of a view permission | `CasesController`, `HearingController`, `ExpenseController:32-33`, `ClientController`, `DocumentController` |
| SEC-4 | No logout route registered — `APIController::logout` exists but is absent from `routes/api.php`, so tokens can never be revoked server-side | `routes/api.php` |
| SEC-5 | Failed login returns HTTP 200 with `"400"` in the body; no rate limiting or lockout on login / password recovery | `APIController::login` |
| SEC-6 | Password-reset token written to the application log in plaintext; reset tokens never expire | `APIController::passwordRecovery` |
| SEC-7 | Almost no request validation on store/update endpoints; models mark `id`, `created_by`, `status` as `$fillable` (mass-assignment exposure) | all controllers / models |
| SEC-8 | Bearer token stored in `localStorage` (exfiltratable via XSS); `.env.example` ships `APP_DEBUG=true` alongside `APP_ENV=production` | `resources/js/src/api.js`, `.env.example` |
| SEC-9 | Document preview logs the access **before** checking permission; welcome SMS fired synchronously inside the request cycle | `CasesController::preview`, `ClientController::store` |

### 1.2 Data integrity / schema (migration-blocking)

| ID | Gap | Location |
|----|-----|----------|
| DATA-1 | `courts.type` is a `foreignId` with no FK constraint to `court_types` — dangling values possible | `create_courts_table` migration |
| DATA-2 | `hearings.hearing_type` is a plain `integer`, not an FK to `hearing_types` | `create_hearings_table` migration |
| DATA-3 | Invoice status uses undocumented magic numbers (1=active, 3=draft, 4=deleted-by-admin, 5=deleted-by-user) crammed into the generic `status` column; no invoice number, totals, payment tracking or currency | `InvoiceController::index` |
| DATA-4 | Money stored as `double` (`invoice_items.rate/tax/total_amount`, `expenses.amount`) — floating-point rounding on financial data; must be `DECIMAL` | migrations |
| DATA-5 | No DB-level unique constraint on `cases.case_number` or client phone; app-level uniqueness only checked on create, not update | migrations / `CasesController` |
| DATA-6 | `User::find(...)->name` calls crash on soft-deleted users — evidence dangling references already exist in production data | `CasesController::index`, `ClientController::index` |
| DATA-7 | `destroy()` is an empty stub for Clients, Cases, Hearings and Tasks — UI delete buttons silently do nothing; only Documents and Invoices actually delete | controllers |

### 1.3 Functional

| ID | Gap |
|----|-----|
| FUN-1 | No pagination anywhere — every index does `Model::all()` plus per-row `find()` (N+1 queries); degrades badly as case volume grows |
| FUN-2 | No time tracking / billable hours, no payments/receipts, no trust (client) accounting — core requirements for a law practice |
| FUN-3 | No hearing-date reminders — SMS helper and calendar UI exist but nothing schedules notifications |
| FUN-4 | No server-side export or reporting — only client-side jsPDF/Excel; no way to extract full firm data from within the app |
| FUN-5 | Document uploads restricted to PDF ≤ 10 MB; no versioning; files on local disk with no backup story (S3 configured but unused) |
| FUN-6 | Cases have no lifecycle status (open / closed / appeal / settled) — only the generic `status` tinyint |
| FUN-7 | Global search absent across cases, clients and documents |

### 1.4 UI / UX

| ID | Gap | Improvement |
|----|-----|-------------|
| UI-1 | Template bloat: demo apps (chat, mailbox, scrumboard, notes, todo), ~40 component demo routes and 3 alternative dashboards (`index1.0.vue`, `index2.vue`) shipped to production | Strip all demo routes/views; ship only the firm's modules — smaller bundle, no dead navigation |
| UI-2 | A 403 (forbidden) response redirects users to the **503 "service unavailable"** error page — misleading | Dedicated 403 "no permission" page with a link back / request-access hint |
| UI-3 | No loading states — list views fetch with bare `axios.get` and render nothing until data lands; errors go to `console.log` | Skeleton loaders on tables/cards, empty-state illustrations with "create your first…" CTAs, toast on failure with retry |
| UI-4 | Monolithic page components (600–950 lines each) duplicating modal + form + toast logic per module | Extract shared `DataTable`, `CrudModal`, `ConfirmDialog`, `Toast` composables/components; one consistent CRUD experience across modules |
| UI-5 | Client-side-only tables load every row into the browser (`v-tables-3`) | Server-side pagination, sorting and column filtering; URL-synced table state (shareable filtered views) |
| UI-6 | Form errors surfaced via generic alerts; no inline field-level validation feedback | Inline per-field validation messages driven by Laravel validation error bags (422 responses) |
| UI-7 | Inconsistent date handling (`d/m/Y` string juggling between UI and API) | ISO dates on the wire; single date-format utility + one date-picker component app-wide |
| UI-8 | Delete buttons that silently do nothing (see DATA-7) erode user trust | Confirm dialog → optimistic update → success/failure toast; disabled state with tooltip where permission is missing |
| UI-9 | No global search, no keyboard shortcuts, weak mobile experience beyond the sidebar overlay | Global search (cases, clients, documents) with `Cmd/Ctrl-K` palette; audit responsive behaviour of tables (card layout on small screens) |
| UI-10 | Accessibility not considered (icon-only buttons without labels, colour-only status indicators, no focus management in modals) | ARIA labels, focus trap in dialogs, status badges with text + colour, WCAG AA contrast pass |
| UI-11 | Dashboard is generic template content rather than firm KPIs | Purpose-built dashboard: upcoming hearings (7-day), tasks due, unbilled work, outstanding invoices, recent case activity |
| UI-12 | i18n scaffolding present but unused; multiple theme/menu-style options add complexity | Either commit to i18n (EN + SW) or remove it; lock a single layout style and delete the settings drawer |

### 1.5 Engineering / operations

| ID | Gap |
|----|-----|
| ENG-1 | Zero real tests — only Laravel's two example tests; no CI pipeline (`.github/workflows` is empty) |
| ENG-2 | EOL stack: Laravel 9 (security fixes ended Feb 2024), PHP 8.0 (EOL Nov 2023), Sanctum 2 |
| ENG-3 | `QUEUE_CONNECTION=sync` — mail and SMS block HTTP requests |
| ENG-4 | Every write endpoint responds with `$this->index()` (full re-fetch of all rows) instead of the affected resource |
| ENG-5 | Heavy duplication (identical `response()` helper pasted in every controller), dead/commented code, mislabelled permission groups in `ModulePermissions` |
| ENG-6 | No database backup strategy, no deployment automation, no environment documentation for the SMS provider |

---

## 2. Proposed Target Stack

| Layer | Current | Target | Rationale |
|-------|---------|--------|-----------|
| PHP | 8.0 (EOL) | **8.3+** | Supported, faster, readonly/enum features |
| Framework | Laravel 9 (EOL) | **Laravel 12** | Security support, native health routes, per-second rate limiting |
| Auth | Sanctum 2 | **Sanctum 4** (SPA cookie mode) | Removes `localStorage` token exposure (SEC-8) |
| Frontend build | Laravel Mix / Webpack | **Vite** | Order-of-magnitude faster builds, first-class Laravel integration |
| State | Vuex 4 | **Pinia** | Vue 3 standard, typed stores |
| Language | JS | **TypeScript** (incremental) | Safety across 9.5k lines of view code |
| Tables/forms | v-tables-3 + hand-rolled | Headless table + `vee-validate`/zod | Server-side pagination (UI-5), inline validation (UI-6) |
| DB | MySQL (doubles for money, missing FKs) | MySQL 8 with strict schema: `DECIMAL(12,2)`, enforced FKs, named enums, unique constraints | Closes DATA-1…5 by design |
| Queue | sync | **Redis + Horizon** (or database driver at minimum) | Async mail/SMS (ENG-3), scheduled hearing reminders (FUN-3) |
| Storage | local disk | Disk + **S3-compatible off-site backup** | FUN-5, ENG-6 |
| API tests | none | **Pest** (unit + feature) | ENG-1 |
| E2E tests | none | **Playwright** (or Laravel Dusk) | Functional coverage of critical user journeys |
| CI/CD | none | **GitHub Actions** (see §5) | Gate every merge and deployment on green tests |

Backend conventions for the rebuild: FormRequest validation on every write endpoint, API Resources for responses (no `$this->index()` echoes), Policies for authorization (replacing scattered `hasPermissionTo` calls), enums for all status fields, eager loading (`with()`) everywhere a relation is rendered.

---

## 3. Testing Strategy

Tests are a **deployment gate**, not an afterthought: nothing deploys unless all suites pass (enforced by CI, §5).

### 3.1 Unit & feature tests (Pest/PHPUnit) — target ≥ 80% line coverage on `app/`

- **Auth:** login success/failure status codes, rate limiting/lockout, logout revokes tokens, password-reset token expiry, no secrets in logs (regression tests for SEC-4/5/6).
- **Authorization:** a permission matrix test per module — every route × every role asserts the exact expected 200/403 (regression for SEC-3). This single suite prevents the wrong-constant class of bug from ever recurring.
- **Validation:** every store/update endpoint rejects invalid payloads with 422 and correct error bags (SEC-7); uniqueness enforced on create *and* update (DATA-5).
- **Domain:** invoice totals computed with decimal precision (DATA-4), status transitions (draft → sent → paid; case open → closed), soft-delete behaviour and `deleted_by` stamping (DATA-7).
- **Data integrity:** FK constraint tests — inserting a hearing with a nonexistent hearing type must fail (DATA-1/2).

### 3.2 Functional / E2E tests (Playwright)

Critical user journeys, run against a seeded application in CI and against staging before production deploys:

1. Login → dashboard renders firm KPIs.
2. Create client → create case for client → upload document → preview document (access logged).
3. Schedule hearing → hearing appears on calendar → reminder job queued.
4. Create invoice with items → totals correct → send to admin → admin approves/deletes → status reflected for creator.
5. Record expense against a case → appears in case financials.
6. Role-restricted user: forbidden module hidden in nav **and** direct URL yields the 403 page (UI-2).
7. Delete flows actually delete (confirm dialog → row removed → audit row written) — regression for DATA-7/UI-8.
8. Table pagination/sort/filter round-trips through the server (UI-5).

### 3.3 Migration verification tests

A dedicated suite (run during the migration project, §6) asserting: row counts per table match, zero orphaned FKs, invoice sums match to the cent, document checksums match, sampled users can authenticate.

---

## 4. UI Improvement Plan (summary of §1.4 targets)

1. **Purge the template** (UI-1, UI-12): keep only firm modules; single layout; remove demo routes, alt dashboards, settings drawer.
2. **Shared component kit** (UI-3, UI-4, UI-8): `DataTable` (server-driven), `CrudModal`, `ConfirmDialog`, `EmptyState`, `SkeletonLoader`, toast service — every module composed from these.
3. **Honest feedback** (UI-2, UI-3, UI-6, UI-8): correct error pages, inline validation, loading/empty/error states on every screen, no silent failures.
4. **Law-firm-first dashboard** (UI-11) and **global search** (UI-9).
5. **Consistency & access** (UI-7, UI-10): one date format utility, WCAG AA pass, keyboard/focus behaviour in dialogs.

---

## 5. CI/CD — GitHub Actions

Two workflows. **Deployment depends on the test workflow succeeding** — functional tests are part of the deployment, not optional.

### 5.1 `ci.yml` — on every push & pull request

```yaml
name: CI
on:
  push:
    branches: [main]
  pull_request:

jobs:
  backend-tests:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_DATABASE: lawfirm_test
          MYSQL_ROOT_PASSWORD: secret
        ports: ['3306:3306']
        options: >-
          --health-cmd="mysqladmin ping" --health-interval=10s
          --health-timeout=5s --health-retries=5
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: xdebug
      - run: composer install --prefer-dist --no-progress
      - run: cp .env.example .env && php artisan key:generate
      - run: php artisan migrate --force
        env: { DB_PASSWORD: secret, DB_DATABASE: lawfirm_test }
      - name: Unit & feature tests (coverage gate 80%)
        run: php artisan test --coverage --min=80
        env: { DB_PASSWORD: secret, DB_DATABASE: lawfirm_test }

  static-analysis:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3' }
      - run: composer install --prefer-dist --no-progress
      - run: vendor/bin/phpstan analyse --memory-limit=1G
      - run: vendor/bin/pint --test

  frontend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 22, cache: npm }
      - run: npm ci
      - run: npm run lint && npm run type-check
      - run: npm run build

  e2e:
    needs: [backend-tests, frontend]
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3' }
      - uses: actions/setup-node@v4
        with: { node-version: 22, cache: npm }
      - run: composer install && npm ci && npm run build
      - run: cp .env.example .env && php artisan key:generate
      - run: php artisan migrate --seed --force   # seeded demo data
      - run: npx playwright install --with-deps chromium
      - name: Functional tests
        run: |
          php artisan serve &
          npx playwright test
      - uses: actions/upload-artifact@v4
        if: failure()
        with: { name: playwright-report, path: playwright-report }

  security:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: composer audit          # known CVEs in PHP deps
      - run: npm audit --audit-level=high
      - name: Secret scan
        uses: gitleaks/gitleaks-action@v2   # prevents SEC-1/SEC-2 recurring
```

### 5.2 `deploy.yml` — on push to `main`, gated on CI

```yaml
name: Deploy
on:
  workflow_run:
    workflows: [CI]
    types: [completed]
    branches: [main]

jobs:
  deploy-staging:
    if: ${{ github.event.workflow_run.conclusion == 'success' }}
    runs-on: ubuntu-latest
    environment: staging
    steps:
      - uses: actions/checkout@v4
      - name: Deploy to staging
        run: ./deploy/deploy.sh staging   # build assets, upload, migrate, restart queue
  smoke-staging:
    needs: deploy-staging
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Functional smoke suite against staging
        run: BASE_URL=${{ vars.STAGING_URL }} npx playwright test --grep @smoke
  deploy-production:
    needs: smoke-staging
    runs-on: ubuntu-latest
    environment: production      # requires manual approval in GitHub environment settings
    steps:
      - uses: actions/checkout@v4
      - run: ./deploy/deploy.sh production
```

Key properties: coverage gate (≥ 80%), static analysis (PHPStan + Pint), secret scanning (so SEC-1/SEC-2 can never merge again), dependency CVE audits, E2E suite on every PR, staging smoke run of functional tests **before** production, and a manual approval gate on the production environment.

---

## 6. Data Migration Plan (current → new system)

The current schema is migration-friendly: integer PKs, timestamps, soft deletes, `created_by/updated_by` provenance, and a full `audits` trail. Users **will** be able to migrate all data provided the DATA-* gaps are closed in the order below.

### 6.1 Inventory

1. **Relational data:** users (+ Spatie role/permission pivot tables), clients, cases, courts, court_types, hearings, hearing_types, tasks, invoices, invoice_items, expenses, expense_categories, attorneys (case↔advocate pivot), document metadata, document_accesses, audits.
2. **Files:** everything under `storage/app/cases/docs/` (paths in `documents.filepath` / `full_path`).
3. **Credentials:** passwords are bcrypt hashes — they migrate as-is into the new Laravel system. Sanctum tokens do **not** migrate; users simply re-authenticate after cutover.

### 6.2 Pre-migration gap closures (run in the OLD system before export)

1. **Repair dangling references** (DATA-1, DATA-2, DATA-6): add the missing FK constraints; constraint failures surface every orphaned row — fix or map them. Reassign cases/clients pointing at soft-deleted users.
2. **Decode magic numbers** (DATA-3): produce a signed-off mapping document for invoice `status` ints and the generic `status`/`archive` tinyints; the new schema stores named enums.
3. **Convert money** (DATA-4): `double` → `DECIMAL(12,2)` in the transform; reconcile each `invoice_items.total_amount` against `quantity × rate + tax` and flag mismatches rather than silently copying them.
4. **Deduplicate** (DATA-5): uniqueness scan on `cases.case_number` and client phone numbers before the new DB-level constraints reject rows mid-import.
5. **Normalize formats:** dates are already `DATE` columns (display-layer `d/m/Y` juggling is irrelevant to migration); phones normalized with the canonical rule already in `sanitizePhone()` (last 9 digits prefixed `254`).

### 6.3 Procedure

**Phase 1 — Export.** Artisan export command in the current system producing per-table JSON/CSV, plus a `mysqldump` as belt-and-braces, plus a SHA-256 checksum manifest of `storage/app/cases/docs`.

**Phase 2 — Transform & load.** Import in dependency order:
`users → roles/permissions → court_types → courts → hearing_types → expense_categories → clients → cases → attorneys → hearings → tasks → invoices → invoice_items → expenses → documents → document_accesses → audits`.
Maintain an `old_id → new_id` mapping table per entity; rewrite `auditable_id`/`user_id` in audits from it; preserve original `created_at/updated_at/created_by` (never stamp import-time values).

**Phase 3 — Verify** (automated, see §3.3): row counts per table including soft-deleted rows; zero orphaned FKs; invoice totals reconcile to the cent; document file count + checksums match; sampled users authenticate.

**Phase 4 — Cutover.** Freeze writes on old system → final delta export (`updated_at` > last export) → switch URL/DNS → users re-authenticate → old system kept **read-only** for an agreed retention window as rollback.

### 6.4 Risk register

| Risk | Mitigation |
|------|-----------|
| Orphaned FK rows abort import | Pre-flight orphan scan (§6.2.1) run before cutover day |
| Files on disk missing vs. `documents` rows | Checksum manifest both sides; reconcile before freeze |
| Invoice status semantics lost | Written mapping doc signed off before transform |
| Users locked out post-cutover | bcrypt hashes carry over; fallback = bulk password-reset flow |
| Audit history broken by new IDs | ID-mapping table + audit rewrite (§6.3 Phase 2) |

---

## 7. Gap-Resolution Validation Checklist

Sign-off criteria: check an item only when the fix is merged, covered by a test named in §3 (where applicable), and verified on staging.

### Security
- [ ] SEC-1 — SMS API key **rotated** at the provider; all credentials moved to `.env`/secrets; gitleaks CI job green
- [ ] SEC-2 — `password` file removed from repo and git history audited
- [ ] SEC-3 — Correct permission per endpoint; role × route permission-matrix test suite passing
- [ ] SEC-4 — Logout route registered; test proves token is revoked after logout
- [ ] SEC-5 — Failed login returns 401/422; rate limiting + lockout tested
- [ ] SEC-6 — Reset tokens hashed at rest, expiring, never logged; tests assert log output contains no token
- [ ] SEC-7 — FormRequest validation on every write endpoint; `$fillable` no longer exposes `id`/`created_by`/`status`; mass-assignment test passing
- [ ] SEC-8 — SPA cookie auth (no token in `localStorage`); `APP_DEBUG=false` in production env template
- [ ] SEC-9 — Permission checked before access logging; SMS dispatched via queued job

### Data integrity
- [ ] DATA-1 — `courts.type` FK-constrained to `court_types`; constraint test passing
- [ ] DATA-2 — `hearings.hearing_type` FK-constrained to `hearing_types`; constraint test passing
- [ ] DATA-3 — Invoice status is a named enum; invoice number, totals and payment status modelled; mapping doc archived
- [ ] DATA-4 — All money columns `DECIMAL(12,2)`; totals test exact to the cent
- [ ] DATA-5 — DB-level unique constraints on case number and client phone; enforced on create **and** update
- [ ] DATA-6 — Zero dangling user references (orphan scan report clean); relations eager-loaded with null-safe rendering
- [ ] DATA-7 — Delete implemented for Clients, Cases, Hearings, Tasks with `deleted_by` stamping; E2E delete-flow test passing

### Functional
- [ ] FUN-1 — Server-side pagination on every index endpoint; N+1 eliminated (query-count assertions in tests)
- [ ] FUN-2 — Time tracking / billable hours and payments recorded; trust accounting decision documented (in scope or explicitly deferred)
- [ ] FUN-3 — Scheduled hearing reminders (SMS/email) running via queue + scheduler; job test passing
- [ ] FUN-4 — Server-side export (CSV/PDF) for cases, invoices, expenses; full-firm data export available to admin
- [ ] FUN-5 — Broader upload types with size policy; off-site (S3) backup of documents verified by restore drill
- [ ] FUN-6 — Case lifecycle status enum with transition rules and tests
- [ ] FUN-7 — Global search across cases, clients and documents

### UI / UX
- [ ] UI-1 — All demo apps, component demo routes and alternate dashboards removed; bundle size reduction recorded
- [ ] UI-2 — 403 responses land on a dedicated "no permission" page (E2E test #6)
- [ ] UI-3 — Skeleton loaders, empty states and error toasts on every list view
- [ ] UI-4 — Shared `DataTable`/`CrudModal`/`ConfirmDialog`/toast components adopted by every module
- [ ] UI-5 — Server-driven table pagination/sort/filter with URL-synced state (E2E test #8)
- [ ] UI-6 — Inline field-level validation errors from 422 responses on every form
- [ ] UI-7 — ISO dates on the wire; single date utility and picker component app-wide
- [ ] UI-8 — Delete flows: confirm → action → feedback; no silent no-ops (E2E test #7)
- [ ] UI-9 — Global search palette (Ctrl/Cmd-K); responsive table behaviour audited on mobile
- [ ] UI-10 — WCAG AA contrast pass; ARIA labels on icon buttons; focus trap in modals
- [ ] UI-11 — Firm-KPI dashboard (upcoming hearings, tasks due, unbilled work, outstanding invoices)
- [ ] UI-12 — i18n decision executed (implemented for EN+SW or removed); single locked layout

### Engineering / operations
- [ ] ENG-1 — Pest + Playwright suites in place; coverage ≥ 80%; CI green and required on branch protection
- [ ] ENG-2 — Laravel 12 / PHP 8.3+ / Sanctum 4 / Vite / Pinia in production
- [ ] ENG-3 — Queue worker (Redis/database) running; mail + SMS asynchronous
- [ ] ENG-4 — Write endpoints return the affected resource (API Resources), not a full re-list
- [ ] ENG-5 — Shared response/error handling; dead code removed; `ModulePermissions` groups correctly named
- [ ] ENG-6 — Nightly DB backups with tested restore; deployment automated via `deploy.yml`; environment variables documented

### Migration readiness (gate for cutover — all must be checked)
- [ ] MIG-1 — Export command produces per-table dumps + document checksum manifest
- [ ] MIG-2 — Orphan-reference pre-flight scan returns zero findings
- [ ] MIG-3 — Status-mapping document signed off
- [ ] MIG-4 — ID-mapping tables populated; audits re-linked
- [ ] MIG-5 — Verification suite (§3.3) fully green: row counts, FK integrity, invoice sums, document checksums, sample logins
- [ ] MIG-6 — Delta-sync + cutover rehearsed on staging end-to-end
- [ ] MIG-7 — Rollback plan documented; old system retained read-only for the agreed window

---

## 8. Recommended Execution Order

1. **Immediate hotfixes on the current system:** SEC-1 (rotate key), SEC-2, SEC-3, SEC-4 — small patches, deployable now.
2. **Data-quality closure** (§6.2) + build the export command (MIG-1, MIG-2).
3. **Build the new system** on the target stack (§2) with the UI plan (§4), tests (§3) and CI (§5) from day one — DATA/FUN/UI/ENG gaps are closed by design rather than retrofit.
4. **Execute the migration** (§6.3) once every MIG-* item is checked.
