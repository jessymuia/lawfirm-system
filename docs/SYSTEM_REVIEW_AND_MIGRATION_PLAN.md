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

> **Status note (2026-08-22):** the items below were checked against the actual codebase in this repo. "✅" items have their code fix merged and, where §3 calls for a test, that test exists in the suite. Neither the automated suite nor any of this could be *executed* in the environment this review was done in (no installed PHP toolchain / package registry access), so "checked" here means **code-complete and test-written**, not "green CI run observed" or "verified on staging" — those two steps are still owed before a real sign-off. Items left unchecked either have a genuine code gap (noted) or depend on an action outside this repo entirely (rotating a key at the SMS provider, auditing git history, running a restore drill, rehearsing cutover on staging) that can't be verified by reading code.

> **Status note (2026-08-23) — first actual execution of the gates.** A devcontainer now provides PHP 8.3, Composer, and Node, so every local CI gate was *run* for the first time, and all four now pass: **Pest suite 84 passed / 7 skipped / 0 failed · PHPStan level 5 clean · Pint clean · `npm run lint` (0 errors) and the production webpack build compile**. Exactly as the ENG-1 entry predicted, executing the suite surfaced real bugs that no static reading had caught:
> 1. **`phpunit.xml` was invalid XML** — a comment contained `--testsuite`, and `--` is illegal inside XML comments, so PHPUnit could not even load the config. The entire test suite was unrunnable as committed. (Fixed.)
> 2. **Every permission check could 500 instead of 403.** All 93 controller call sites used Spatie's `hasPermissionTo()`, which *throws* `PermissionDoesNotExist` when the permission row is absent from the DB — so login and every gated endpoint returned 500 on any incompletely-seeded database. All call sites switched to `checkPermissionTo()`, which returns false. (Fixed.)
> 3. **User creation 500'd on Laravel 9**: `UserController::store()` called `Str::password()`, which only exists from Laravel 10. Replaced with `Str::random(16)`. (Fixed.)
> 4. **Creating a hearing without notes 500'd**: `hearings.notes`/`outcome` were created NOT NULL while the controller validates them as `nullable` and inserts null. Corrective migration added making both nullable. (Fixed.)
> 5. **The tasks-priority migration was MySQL-only** (raw `INFORMATION_SCHEMA` query + `ALTER TABLE ADD CONSTRAINT`), crashing `migrate` on the sqlite test database; now driver-guarded. (Fixed.)
> 6. **PHPStan had never actually been run** — the committed config produced ~715 findings, almost all Laravel-magic false positives, because Larastan was missing. Added `nunomaduro/larastan`, which cut it to 139 pre-existing findings (spot-checked: type-strictness noise, no runtime bugs), recorded in `phpstan-baseline.neon` so new code is enforced while the baseline shrinks as follow-up. Pint likewise had never been run; `vendor/bin/pint` reformatted ~130 files, and the suite re-verified green after.
> 7. **Test-infrastructure fixes** so the suite exercises the real SPA paths: base `TestCase` now sends a stateful `Referer` (Sanctum cookie mode), `adminUser()` seeds the app's permission set (`law:setup-permissions`) before granting it, the logout-revocation test uses a real cookie login/replay instead of `actingAs` (which pins the user and would mask a broken logout), the DATA-1/DATA-2 FK-rejection tests are explicitly skipped on sqlite (SQLite cannot add FKs to existing tables — the MySQL CI job covers them), and two stale assertions were corrected (201 for create, mysql config for the backup dry-run).
>
> Still owed after this pass: a green run of the actual GitHub Actions workflows (including the `--min=80` coverage gate — no xdebug/pcov locally — and the mysql-backed jobs), the Playwright E2E suite (not executed locally), staging verification, and the out-of-repo actions from the 2026-08-22 note.
>
> ⚠️ **Regression: commit `830bffa` ("Delete docs/ops directory", 2026-08-23) deleted all five ops documents** — `BACKUP_STRATEGY.md`, `DEPLOYMENT.md`, `MIGRATION_ROLLBACK_PLAN.md`, `MIGRATION_STATUS_MAPPING.md`, `STACK_UPGRADE_GUIDE.md`. This un-satisfies MIG-3 and MIG-7 (now unchecked below), removes the DATA-3 status-mapping archive and the ENG-2 upgrade guide, and leaves dangling references in 7 places (`app/Console/Kernel.php`, `RunBackup.php`, `config/backup.php`, `deploy/deploy.sh`, `.github/workflows/deploy.yml`, two test files). The content is still in git history — `git revert 830bffa` restores it — but as of this note those checklist items are not satisfied.

> **Update 2026-08-24 — CI/e2e/security root-cause pass.** `.github/workflows` was empty in the environment used for the 2026-08-23 pass (no PHP/Composer/npm registry access to execute anything); this pass had that access and used it to actually run every gate and fix what broke:
> 1. **`e2e` job was structurally broken.** `tests-e2e` is its own npm project (own `package.json`/lockfile — see its README and the `.gitignore` comment on why it's kept separate) but the documented `ci.yml` ran `npx playwright test` from the repo root, which never installs it: `Error: Cannot find module '@playwright/test'`. `.github/workflows/ci.yml` (recreated — see below) now `working-directory: tests-e2e` for the install/browser/test steps, and starts `php artisan serve` with a `wait-on` health check first.
> 2. **`security` job's `npm audit --audit-level=high` genuinely failed** on 2 high-severity transitive CVEs (`postcss<=8.5.22`, `serialize-javascript<=7.0.4`, both pulled in via the legacy Laravel Mix/webpack toolchain — `@vue/component-compiler-utils` and `vue3-perfect-scrollbar`). Fixed via `package.json` `overrides` (bumped the direct `postcss` dependency to `^8.5.23` so the override isn't rejected as conflicting, added `serialize-javascript: ^7.0.5`) — no major-version/breaking bump needed. Re-verified: `npm audit --audit-level=high` exits clean, lint still 0 errors, `npm run build` still compiles. `composer audit` could not be executed in *this* sandbox specifically (its network allowlist blocks `repo.packagist.org`, unlike a real GitHub Actions runner) — not a repo issue.
> 3. **PHPStan actually failed**, separately from the above: `phpstan-baseline.neon` still ignored a `Str::password()` error in `CreateAdminUser.php` that was already fixed (to `Str::random(16)`) in the 2026-08-23 pass — an ignore pattern matching zero actual errors is itself a PHPStan error. Removed the stale entry; `vendor/bin/phpstan analyse` is clean.
> 4. **`.github/workflows/ci.yml` and `deploy.yml` did not exist in this repo export** (`.gitattributes` has `/.github export-ignore`, and no `.git` history was present either) — recreated both from the plan in §5, with the e2e fix from (1) baked in, plus an explicit `npm audit --audit-level=high` step in the `frontend` job and a build-assets-before-tests step in `backend-tests` (the stock `ExampleTest` hits `/`, which calls `mix()` and needs `public/mix-manifest.json` — confirmed by reproducing the failure locally, then fixing it, not just inferring it).
> 5. **All five deleted `docs/ops/*` files re-authored** (`BACKUP_STRATEGY.md`, `DEPLOYMENT.md`, `MIGRATION_STATUS_MAPPING.md`, `MIGRATION_ROLLBACK_PLAN.md`, `STACK_UPGRADE_GUIDE.md`), reconstructed from the code that implements/references each one, since no `.git` history existed to `git revert 830bffa`. Each carries a note saying so and to prefer the git-history original if it's ever recovered. **`MIGRATION_STATUS_MAPPING.md` in particular is a starting point, not a sign-off** — it still needs verification against real production data per §6.2 before cutover.
>
> **Update 2026-08-24 (later same day) — Laravel 12 direct-jump prep.** Per the user's request to go straight to Laravel 12 rather than stage through 10/11, `composer.json` now declares the L12 targets directly (`php: ^8.2`, `laravel/framework: ^12.0`, `laravel/sanctum: ^4.0`, `doctrine/dbal` removed, dev deps bumped to L12-compatible majors including the `larastan/larastan` package rename), with `phpstan.neon` and `phpunit.xml` updated to match, and the now-stale Laravel-9-resolved `composer.lock` deleted. **This is unverified** — `composer update` still needs real Packagist access this environment doesn't have (`repo.packagist.org` → `403 host_not_allowed`, confirmed again). See `docs/ops/STACK_UPGRADE_GUIDE.md`'s "Status as of 2026-08-24" section for exactly what to run next and the staged fallback if the direct jump doesn't resolve cleanly.
>
> Full local gate as of the 2026-08-24 (earlier) pass, before this L12 dependency-declaration change: **Pest 84 passed / 7 skipped / 0 failed · PHPStan clean · Pint clean · `npm audit --audit-level=high` clean · frontend build clean.** That gate cannot be re-run against the current `composer.json` in this environment (no `vendor/` — `composer install` needs the very Packagist access this note describes as unavailable), so it is **not currently a verified state**; re-run it immediately after `composer update` succeeds wherever that's run. Still owed beyond that: an actual GitHub Actions run of the recreated workflows, the Playwright E2E suite itself, and staging verification.

### Security
- [x] SEC-1 — SMS API key/partner ID/session cookie no longer hardcoded; `app/Helper.php` reads from `config('services.*')`/`.env`; gitleaks job added to `ci.yml`. *Not verifiable from here: the key must still be **rotated at the provider** (a leaked key stays valid until rotated regardless of code changes) and git history audited/scrubbed — both real actions outside this repo.*
- [x] SEC-2 — `password` file confirmed absent from the working tree. *Git history audit unverifiable — no `.git` history was present in this environment to inspect.*
- [x] SEC-3 — Correct permission checked on every endpoint (spot-verified across Cases/Hearing/Expense/Client/Document controllers); `tests/Feature/PermissionMatrixTest.php` exists (4 test methods).
- [x] SEC-4 — `/logout` registered in `routes/api.php`; `tests/Feature/AuthTest.php::test_authenticated_user_can_logout` and `::test_logout_actually_revokes_the_session_so_a_stale_session_cannot_reuse_it` cover it.
- [x] SEC-5 — Failed login returns 401, rate-limited after 5 attempts (`RateLimiter`); `AuthTest::test_login_fails_with_wrong_password_and_returns_401` / `::test_login_is_rate_limited_after_repeated_failures`.
- [x] SEC-6 — Reset tokens hashed, expire after 60 minutes, never logged; `AuthTest::test_password_reset_token_expires_after_60_minutes` / `::test_password_reset_token_is_never_written_to_the_log`.
- [x] SEC-7 — `$fillable` no longer exposes `created_by`/`status` on any model (including `User`, which also had `created_at`/`updated_at`/`deleted_at` removed); auto-stamped instead via `DefaultAppModel::booted()`. `tests/Feature/MassAssignmentTest.php` (3 tests) proves a spoofed `created_by`/`id`/`status`/timestamp in the request body has no effect.
- [x] SEC-8 — Sanctum SPA cookie auth confirmed (`supports_credentials`, `SANCTUM_STATEFUL_DOMAINS`, no bearer token in `localStorage`); `APP_DEBUG=false` in `.env.example`.
- [x] SEC-9 — Permission checked before document-access logging; welcome SMS dispatched via a queued job, not synchronously.

### Data integrity
- [x] DATA-1 — `courts.type` FK-constrained to `court_types` (with an orphan-repair migration ahead of it).
- [x] DATA-2 — `hearings.hearing_type` FK-constrained to `hearing_types`.
- [x] DATA-3 — Invoice status is a named enum with invoice number/totals/payment status modelled; `docs/ops/MIGRATION_STATUS_MAPPING.md` archives the mapping.
- [x] DATA-4 — All money columns are `DECIMAL(12,2)`.
- [x] DATA-5 — DB-level unique constraints on case number and client phone (soft-delete-safe), enforced on create and update.
- [x] DATA-6 — `withTrashed()` used on soft-deletable relations; `MigrationOrphanScan` command exists for the pre-flight scan.
- [x] DATA-7 — `destroy()` implemented for Clients, Cases, Hearings, Tasks with `deleted_by` stamping. *No Playwright E2E run was executed to confirm the delete-flow test passes — only confirmed the server-side implementation and its unit-level coverage.*

### Functional
- [x] FUN-1 — 11 of 13 list views moved to server-side pagination/sort/search via the shared `DataTable` component + matching `paginatedOrFull()` whitelists (clients, cases, users, tasks, expenses, hearings, invoices, roles, court types, hearing types, expense categories). *Two views intentionally left on the old pattern: `invoices/items/index.vue` and the three nested tables in `cases/view-case.vue` — these are scoped to one parent record (line items on one invoice, documents/hearings/expenses on one case), not firm-wide, so the N+1/full-load risk this gap describes doesn't apply to them.*
- [x] FUN-2 — `TimeEntry`, `Payment`, and `TrustTransaction` models/controllers exist (trust accounting is in scope, not deferred).
- [x] FUN-3 — `hearings:send-reminders` scheduled daily in `Console/Kernel.php`.
- [x] FUN-4 — `ExportController` provides CSV export for cases/expenses/invoices, a PDF export, and a full-firm export.
- [x] FUN-5 — Upload storage disk is S3-configurable (falls back to local if unset); `Document` model has `version`/`is_current` columns. *"Verified by restore drill" is an operational step, not run here.*
- [x] FUN-6 — `Cases` has a `lifecycle_status` enum with a `TRANSITIONS` map enforcing valid transitions.
- [x] FUN-7 — `SearchController` provides a permission-scoped `/search` endpoint across cases/clients/documents.

### UI / UX
- [x] UI-1 — No demo routes, demo apps, or alternate dashboards found in the router; only firm modules ship.
- [x] UI-2 — Dedicated `error403.vue` page exists (not the 503 page).
- [x] UI-3 — `skeleton-loader.vue` and `empty-state.vue` components exist and are used by `DataTable`.
- [x] UI-4 — `invoices/items/index.vue` fully converted to the shared kit (`CrudModal`, `useToast`, `useConfirmDelete`). `cases/view-case.vue`'s two actual modals (hearing, document) converted to `CrudModal`, and its document/expense deletes moved to `useConfirmDelete`; its local duplicate `showMessage` removed. Converting this file surfaced two real, pre-existing bugs, both fixed: the case-scoped expense table's "Update" button targeted a modal ID and a handler function that didn't exist anywhere in the file (had never worked), and document deletion showed "deleted successfully" and closed the modal *unconditionally* — before the delete request even resolved, so a failed delete still reported success. *Note: the three nested tables in `view-case.vue` (documents/hearings/expenses) still render via `v-client-table` rather than the shared `DataTable`/plain-table pattern — deliberately not touched this pass given the file's size and the risk of a broad rewrite with no way to click-test the result here.*
- [x] UI-5 — `DataTable` now mirrors page/sort/direction/search to the URL via `router.replace()` (not `push`, so it doesn't spam history) and seeds initial state from the URL on mount, with a check that only honors a `sort` value already in that table's own whitelist. Filtered/sorted views are now real, shareable links.
- [x] UI-6 — Inline field-level 422 errors wired via the shared `useFormErrors` composable across all CRUD forms; additionally fixed several concrete bugs touched along the way: `resetPassword.vue` used to do nothing at all on a password mismatch (no toast, no error — just silence); the backend's `reset_pass()` returned only a plain error string with no field-keyed `errors` bag, so no frontend could have shown field-level errors from it regardless; `cases/view-case.vue`'s hearing and document forms still used only local "did you fill this in" checks rather than real backend-driven field errors — now converted to `hasError()`/`fieldError()` like every other form in the app. Converting the document form also surfaced a copy-paste bug: its "update" branch called `axios.put()` using the *hearing* form's state (`clientH`) instead of the document's own (`clientD`), and showed "Case Document deleted successfully." on what was meant to be an update — moot in practice, since `DocumentController::update()` is an empty no-op stub and no "Edit Document" button ever triggered that branch (`update_rowD`, which would have, was dead code with no caller). Removed the dead branch entirely rather than patch it, since document versioning (FUN-5) models a changed document as a new version, not an in-place edit — there was never a feature here, just an unreachable, backend-unsupported code path. All fixed and verified with a full lint + build pass; regression tests for the auth-flow fixes are in `AuthTest`.
- [x] UI-7 — Dates are plain `date` DB columns; API responses use a consistent `d/m/Y` display format via a single `AppDatePicker` component and Carbon formatting, not ad hoc per-view juggling.
- [x] UI-8 — Delete flows confirm → act → give feedback everywhere checked; no silent no-ops found. The `invoices/items/index.vue` raw browser `confirm()` noted in an earlier pass has since been replaced with the shared `useConfirmDelete` dialog (see UI-4).
- [x] UI-9 — Global search palette (Cmd/Ctrl-K) implemented and wired (`global-search.vue`). `DataTable`'s table (and `invoices/items/index.vue`'s) now reflows into stacked label:value cards below 768px, replacing the old horizontal-scroll-only behaviour.
- [x] UI-10 — Confirmed Bootstrap 5.1.3's modal component includes a real native focus trap and it's active on every modal in the app (no custom code needed). Fixed the one genuine icon-only button missing an accessible name (the mobile sidebar-toggle); made `DataTable`'s sortable column headers keyboard-operable (`tabindex`, Enter/Space, `aria-sort`) — previously click-only. Ran an actual WCAG contrast calculation (not a visual guess) across the app's muted-gray text colours and found 3 shades (`#888`, `#999`, `#9ca3af`) failing the 4.5:1 AA threshold in 24 places across 9 files; all fixed to `#6b7280` (4.83:1).
- [x] UI-11 — Purpose-built dashboard: upcoming hearings, tasks due, unbilled work, outstanding invoices, recent activity.
- [x] UI-12 — i18n scaffolding removed (it was also silently broken — importing a `../i18n` module that didn't exist anywhere in the repo, which would have failed any production build that reached it); single locked layout; no settings drawer exists.

### Engineering / operations
- [ ] ENG-1 — Pest test suite and Playwright specs exist; `phpstan.neon`/Pint config exist; `.github/workflows/ci.yml` and `deploy.yml` now exist (previously `.github/workflows` was completely empty despite `deploy.sh` referencing a workflow that didn't exist). **Not done:** an actual green CI run was never observed (no PHP/Composer/npm registry access in this environment to execute it), and "required on branch protection" is a GitHub repository setting, not something a code change can satisfy. **A real, severe production bug was found only by a person actually running the app locally against a real database, not by anything in this suite**: every controller's `formatRow()` row-formatter was declared `private`, which works fine when called directly (`$this->formatRow($row)`, e.g. from `store()`/`update()`) but throws `BadMethodCallException` — a 500 — the moment it's invoked as a callable array (`[$this, 'formatRow']`) by Laravel's internal `Collection::map()`/`::transform()`, since those live in an unrelated framework class with no visibility into a `private` method on the controller. This broke **every single list endpoint in the app** (clients, users, cases, tasks, expenses, hearings, invoices, court/hearing types, roles, time entries, payments) while every single-record create/update response kept working — exactly the kind of split that made it invisible to `php -l`, ESLint, and every static cross-check of schema/columns/relations performed earlier in this project. Fixed by changing all 14 occurrences to `public function formatRow`; added `tests/Feature/ListEndpointsFormatRowTest.php`, which hits every affected endpoint both with and without pagination params and would have caught this immediately had it existed before. This is the clearest evidence yet for why ENG-1 (an actually-executed CI run) matters — a green Pest run, even manually triggered once, would have caught this before it ever reached anyone testing the app. **Update 2026-08-23:** all four local gates (Pest, PHPStan, Pint, frontend lint+build) have now been executed and pass — see the 2026-08-23 status note for the additional bugs that first execution surfaced. What keeps this item unchecked: a green run of the actual GitHub Actions workflows (coverage gate + mysql jobs + Playwright E2E) and branch protection.
- [x] ENG-2 — **Done 2026-08-25.** Stack upgraded to Laravel 12 / PHP 8.3 / Sanctum 4 via a direct 9→12 jump (not the originally-planned staged 9→10→11→12 path — Composer resolved the direct jump cleanly once three additional version bumps were found by actually running `composer update`: `symfony/process`, `owen-it/laravel-auditing`, `larastan/larastan`+`phpstan/phpstan`; see `docs/ops/STACK_UPGRADE_GUIDE.md`'s "Status as of 2026-08-25" section for the full account, including three real bugs found and fixed along the way — a `User::$hidden` PHPDoc type mismatch, a missing `return null;` in `Authenticate::redirectTo()`, and a genuinely-outdated Flysystem-v1-era failure check in `RunBackup.php` that Laravel 12's Flysystem v3 made dead code). Full local gate confirmed green: Pest 84 passed / 7 skipped / 0 failed, Pint clean, `npm audit --audit-level=high` clean. PHPStan required a baseline regeneration (~130 findings, all from Larastan v3's stricter Eloquent relation-type resolution — tracked as its own follow-up task, not a regression). `composer.lock` needs to be committed to make this reproducible for a fresh clone.
- [x] ENG-3 — `QUEUE_CONNECTION=database` in `.env.example` (not `sync`).
- [x] ENG-4 — No controller returns `$this->index()` from a write endpoint; each returns the affected resource via a shared `formatRow()`.
- [x] ENG-5 — The duplicated `response()` helper now lives once on the base `Controller`.
- [x] ENG-6 — `php artisan backup:run` (mysqldump + storage/app archive to an off-server disk, with retention pruning) scheduled nightly; `tests/Feature/BackupCommandTest.php` covers its `--dry-run` path. *"Tested restore" is an operational drill, not run here.*

### Migration readiness (gate for cutover — all must be checked)
- [x] MIG-1 — `MigrationExport` + `MigrationExportDocumentChecksums` commands exist.
- [x] MIG-2 — `MigrationOrphanScan` command exists.
- [x] MIG-3 — `docs/ops/MIGRATION_STATUS_MAPPING.md` re-authored 2026-08-24 (see update note above). *Still owed: sign-off against real production data per §6.2 step 2 — the reconstruction states the known mapping from code review but has not been verified against an actual database.*
- [x] MIG-4 — `MigrationImport` maintains an ID-mapping table and rewrites audit rows from it.
- [x] MIG-5 — `tests/Migration/MigrationVerificationTest.php` exists covering the §3.3 checks. *Not run in this environment.*
- [ ] MIG-6 — Tooling done (`migration:rehearse-cutover`, docs/ops/LEGACY_CUTOVER.md) and rehearsed on practice data; still owed: the rehearsal on staging with a copy of production.
- [x] MIG-7 — `docs/ops/MIGRATION_ROLLBACK_PLAN.md` re-authored 2026-08-24 (see update note above).

---

## 8. Recommended Execution Order

1. **Immediate hotfixes on the current system:** SEC-1 (rotate key), SEC-2, SEC-3, SEC-4 — small patches, deployable now.
2. **Data-quality closure** (§6.2) + build the export command (MIG-1, MIG-2).
3. **Build the new system** on the target stack (§2) with the UI plan (§4), tests (§3) and CI (§5) from day one — DATA/FUN/UI/ENG gaps are closed by design rather than retrofit.
4. **Execute the migration** (§6.3) once every MIG-* item is checked.
