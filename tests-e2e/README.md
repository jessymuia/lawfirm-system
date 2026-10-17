# E2E Test Suite (Playwright)

§3.2 of the migration plan. Covers all 8 required journeys against a real,
seeded application — no functionality under test is mocked.

## What's here

```
tests-e2e/
  playwright.config.js   Config: chromium only, no fixed timeouts, retries=1 in CI
  global-setup.js        Standalone seed script (migrate + db:seed)
  package.json           @playwright/test, @axe-core/playwright, mysql2, dotenv
  fixtures/
    base.js              Extends Playwright's test with a `db` fixture
    test-data.js          createClient()/createCase() via the real API
    ui-forms.js           Shared client/case-form-filling helpers
  utils/
    db.js                 Direct MySQL connection for backend/audit assertions
    auth.js                loginAs()/logout() through the real login page
    api.js                  CSRF-aware page.request wrappers for setup calls
    a11y.js               expectAccessible(): axe-core WCAG 2.1 AA check (UI-15)
  specs/
    01-auth-dashboard.spec.js
    02-client-case-document.spec.js
    03-hearing-calendar-reminder.spec.js
    04-invoice-workflow.spec.js
    05-case-expenses.spec.js
    06-rbac.spec.js
    07-delete-audit.spec.js
    08-pagination-sorting-filtering.spec.js
    09-two-factor.spec.js
    10-accessibility.spec.js   axe over every main screen; see docs/ACCESSIBILITY.md
    11-compliance.spec.js      legal hold, retention period and a data-subject request through the Compliance screen
```

## Prerequisites

- PHP 8.2+, Composer, a MySQL 8 instance, Node 18+.
- The app's `.env` pointed at that MySQL instance, with
  `QUEUE_CONNECTION=database` (journey 3 asserts against the `jobs` table)
  and `MAIL_MAILER=array` (no real mail sending during tests).

## Running locally

```bash
# from the repository root
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force      # runs RoleSeeder + E2ETestSeeder first —
                                  # see those files for why that order matters
php artisan serve --port=8000 &

cd tests-e2e
npm ci
npx playwright install --with-deps chromium
npm test
```

`npm run test:headed` runs with a visible browser; `npm run test:ui` opens
Playwright's interactive UI mode; `npm run report` opens the last HTML
report.

## Running in CI

See `.github/workflows/ci.yml`'s `e2e-tests` job — it does exactly the
above against a MySQL service container, uploading the Playwright HTML
report as a build artifact on any failure.

## Test users

Fixed, known-credential users seeded by `database/seeders/E2ETestSeeder.php`
(password for all three: `E2ePassword!123`):

| Email | Role | Notable permissions |
|---|---|---|
| e2e-admin@lawfirm.test | admin | everything |
| e2e-advocate@lawfirm.test | advocate | clients/cases/hearings/invoices/expenses/documents/time-entries |
| e2e-clerk@lawfirm.test | clerk | dashboard + list-only clients/cases — deliberately excludes hearings/invoices/expenses/documents/users/roles, for the RBAC journey |

## Design notes / honest limitations

- **No multi-tenant/firm concept exists in this app** (single law firm,
  by design — no `firm_id` anywhere in the schema). Journey 1's "cannot
  see another firm's data" is substituted with the real, already-built
  equivalent: per-user data scoping on the dashboard for non-admins. See
  the comment at the top of `specs/01-auth-dashboard.spec.js`.
- **Reminders (journey 3) are a scheduled daily command**
  (`hearings:send-reminders`), not something dispatched at hearing-creation
  time — the spec invokes that artisan command directly via
  `child_process.execSync`, the same mechanism cron would use, rather than
  waiting for a real 24-hour cycle or faking the passage of time.
- **Only the Courts module has real server-side sort/filter** (journey 8)
  — every other list page still uses the older client-side v-tables-3
  pattern. Building that out for every module is a larger frontend
  migration (tracked as UI-5 in the broader migration plan), not something
  in scope to retrofit everywhere just for this test suite.
- Every spec cleans up its own fixture data in `afterAll`/inline, using
  `utils/db.js`'s dependency-ordered delete helpers (payments,
  invoice_items, time_entries, hearings, documents/document_accesses,
  invoices, expenses, cases, clients), so re-running the suite repeatedly
  against the same database doesn't accumulate stale rows or fail on
  foreign-key constraints.
- No `page.waitForTimeout()`/fixed sleeps anywhere in the suite — every
  wait is for a specific element, a specific network response (often
  matched by exact query-string parameters, e.g. `?sort=name`), or a URL
  change, per the "deterministic, no arbitrary timeouts" requirement.

## Bugs found and fixed while building this suite

Each is documented in-place (grep for "Bug fix" or "Regression" across
`app/`, `resources/js/src/`, and this test suite for the full list), but
the headline ones:

1. **Sidebar navigation checked the wrong permission** for Hearings,
   Hearing Types, Expenses, and Expense Categories (copy-paste of the
   same mistake SEC-3 already fixed once in the backend).
2. **The entire database seeding pipeline was broken** — `UserSeeder`
   assumed roles already existed; nothing ever created one.
3. **Invoice totals silently excluded tax** — the server trusted a
   client-submitted `total_amount` verbatim instead of deriving it from
   quantity/rate/tax, and the frontend's own calculation excluded tax
   from that figure in the first place.
4. **The "Case Expenses" panel showed every expense in the entire firm**
   on every case's page — the API call had no case filter at all.
5. **`ClientController::show()` was a complete no-op stub** — the "View
   Client" page had never actually loaded real data.
6. **`CasesController::show()` had no permission check whatsoever.**
7. **`RolesManagementController::update()`/`destroy()` had no permission
   check at all** — any authenticated user could grant themselves
   arbitrary permissions or delete any role.

## What this suite cannot do in every environment

Playwright itself was installed and every spec file structurally
validated with `npx playwright test --list` (confirms syntax, module
resolution, and fixture wiring) while writing this suite. Actually
*running* the tests requires a live PHP/MySQL backend, which is an
infrastructure dependency of wherever this repository is checked out and
run — see the CI job for the reference environment this was built against.
