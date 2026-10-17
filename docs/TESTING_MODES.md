# One suite, two deployment modes (TEN-17)

Self-hosting is the same code with one firm, not a second product. To keep it that way, CI runs the Unit and Feature
suites twice:

| Job | Mode | How the app sees it |
|---|---|---|
| `backend` (with coverage), `backend-mysql` | single-firm (self-hosted) | one database, no control plane |
| `backend-multi` | multi-tenant (SaaS) | a control-plane database plus one firm database, each on its own connection; every request's firm is resolved from its host (`localhost`) by the real `ResolveTenant` middleware |

Run the default pass inside the Docker container with (the container itself runs the demo in multi-tenant mode and
points at MySQL, so the test run is told otherwise):

```
docker compose exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: app php artisan test
```

Run the multi-tenant pass locally with:

```
TEST_TENANCY_MODE=multi php artisan test --testsuite=Unit,Feature --exclude-group=single-mode
```

## How the multi-tenant pass works (`tests/TestCase.php`)

- The control plane and the firm database ("testfirm", plan `self_hosted`, hosts `localhost`, `127.0.0.1`,
  `firm.example.com`) are migrated once per process and copied fresh for every test. RefreshDatabase's
  transaction cannot span two connections, so a file copy replaces it.
- The firm is active when the test starts, so factories, models and requests all work inside it.
- Fleet commands and firm-less requests (health, platform console) leave the process fail-closed, as they must in
  production. The test then carries on in its firm, as the next request in production would. The harness restores
  the firm without a query, so tests that count queries are not disturbed.
- Every person in a SaaS firm signs in through a control-plane identity. Factory-made users get one, and a test that
  changes a user's password or email changes the identity too.

Helpers that work in both modes:

- `usePlan('starter')` switches the plan: the install's plan on a single-firm install, the firm's registry row in
  multi mode.
- `firmOption()` returns `['--tenant' => 'testfirm']` in multi mode for console commands that act on one firm.

## Exceptions

- **Tests that build their own tenancy** (multi-tenant tests, the isolation suite) implement `Tests\OwnsTenancy`. They
  run exactly as in the default mode, because they set the mode themselves.
- **Self-hosted-only behaviour** (licenses, `app:install`, the single-install password reset, install-to-install copy,
  the single-firm rate-limit default) carries `#[Group('single-mode')]`. Each is listed with its reason in
  `tests/single-mode-baseline.txt`. `SingleModeTestsTest` fails if a test joins the group without being listed there,
  so the exceptions stay deliberate and can only shrink.

## What the multi-tenant pass found

When this mode was first run, it exposed three bugs that affect only SaaS. All are fixed:

- **Users added by a firm admin could never sign in.** `UserController::store` created the firm user without a
  control-plane identity. `IdentityService::enrol()` now links one; it does nothing on a single-firm install.
- **An inline job left its request without a firm.** A job run by the `sync` queue made the rest of its request
  fail-closed. The queue hooks now save the firm state before a job and restore it after (`TenantManager::pushState()`
  and `popState()`): a worker returns to no firm, a request keeps its own.
- **A request could lose its plan's features.** The fail-closed "no firm" entitlement answer was cached under the
  firm's key, so the next request in the same process had no plan features. That answer is no longer cached.
