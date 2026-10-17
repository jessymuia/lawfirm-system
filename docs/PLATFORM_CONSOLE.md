# Platform console (PLAT-7)

The platform staff console operates the SaaS control plane: tenant lifecycle, cells, billing, health and incidents, audit, and staff security. The staff UI is served at `/console` on its dedicated admin host; its JSON API is under `/api/platform/*`. It is not part of any firm's application.

## Where it exists
Only when **all** of these hold, otherwise the platform UI and API return 404:
- `TENANCY_MODE=multi`
- `PLATFORM_ADMIN_HOST=admin.example.com` is set (a dedicated host, never a tenant domain)
- the request arrives on that host

The platform host serves nothing but the console; tenant hosts do not serve the console. Self-hosted (single) installs carry the code but it is inert.

## Operator sign-in on the app's own address (local and demo use)

With `PLATFORM_CONSOLE_ON_APP_HOST=true` (and `TENANCY_MODE=multi`, outside production) the console and its API also answer on
the app's own address, on their own paths only (`/console`, `/api/platform/*`). The firm sign-in page then shows a **Firm /
Operator** switch: *Firm* is the normal sign-in, *Operator* signs the platform staff member in to the control plane and opens
`/console`. No separate admin host is needed. The Docker stack turns this on and runs `php artisan law:demo-setup`, which creates the
super admin and one sample Kenyan firm (more via `law:demo-load`) (see `docs/DEMO_LOGINS.md`). In production the switch is ignored: the console stays on
`PLATFORM_ADMIN_HOST` alone, and the sign-in page links to it when that host is configured.

## Browser console and account boundary

- Platform staff open `https://<PLATFORM_ADMIN_HOST>` (redirects to `/console`) or go directly to `/console`. They sign in with a `platform_staff` account and the platform's own two-factor flow. The staff token is kept in the browser's `sessionStorage`, sent as a bearer token to same-host API requests, and discarded on sign-out or expiry.
- When the platform host is configured in multi-tenant mode, the firm sign-in page also shows a **Platform operator sign-in** link to `https://<PLATFORM_ADMIN_HOST>/console`. It is hidden when the operator console is disabled.
- The UI is a separate, responsive console; it does not use the firm's session cookie. The API enforces staff roles on every request; the UI hides actions the current role cannot perform but is not itself an authorization boundary.
- Modules: **Overview** (fleet, schema and service signals); **Firms** (search, provision, tenant detail, lifecycle, flags, billing and support access); **Cells**; **Billing** (signup/trial funnel and firm subscription/invoices); **Health & incidents**; **Audit log**; and **Staff security** (MFA).
- Platform staff accounts remain CLI-managed; the browser console does not create staff or change roles.
- Firms use their own tenant host and the separate `/login` flow. After signing in, firm users reach the firm dashboard and permission-filtered modules for users/roles/security, clients and cases, courts/hearings/diary, tasks and calendars, templates, invoices/expenses/tax, trust accounting, partner reports, conflicts, compliance, offices/groups, and subscription. A platform staff account cannot sign in as a firm user, and a firm user cannot access `/console`.
- `/api/platform/dashboard` remains the small fleet-migration status page. The full browser console is `/console`.

## Staff
Platform staff are **not tenant users**: they live in the control plane (`platform_staff`), have no standing in any firm's data, and authenticate with their own bearer tokens (stored as SHA-256 hashes, 8 h lifetime, `PLATFORM_TOKEN_HOURS`). Manage them from the CLI only:

```
php artisan platform:staff create ops@you.example --name="Ops" --role=operator      # password generated and shown once
php artisan platform:staff role    ops@you.example --role=admin
php artisan platform:staff disable ops@you.example                                   # also ends live sessions
```
Login: `POST /api/platform/login {email, password}` returns a short-lived MFA challenge when a second factor is enrolled; `POST /api/platform/login/mfa {challenge, code}` returns `{token}`. If required MFA has not been enrolled, the returned token is limited to the enrolment endpoints. Send `Authorization: Bearer …` after sign-in. Five password failures lock the account for 15 minutes; one generic error is used for unknown/wrong/locked/disabled accounts.

| Role | Can |
|------|-----|
| `viewer` | read: fleet, tenants, detail, audit |
| `operator` | + provision, suspend, resume, migrate, backup |
| `support` | + request and manage audited support sessions, subject to each firm's policy |
| `admin` | + offboard, purge, legal hold |

## Endpoints
```
GET  /fleet                       counts by status, latest migration, firms behind it, pending/running/failed counts,
                                  schema-version distribution, failed migrations, legal holds, schema drift
GET  /dashboard                   HTML fleet-migration dashboard (no auth on the page itself; it reads /fleet with the token you enter)
GET  /tenants ?status= &q=        slug, name, status, plan, domains, schema_version, migration_status, latest usage gauges
GET  /tenants/{slug}              + entitlements, migration error, 30-day usage history, last 25 platform actions
POST /tenants                     {slug,name,admin_email,admin_name?,plan?,domain?,region?}  -> admin password shown once if generated
POST /tenants/{slug}/suspend      {reason}      (suspended tenants get 403 on their own host immediately)
POST /tenants/{slug}/resume       {reason}
POST /tenants/{slug}/migrate                    runs pending tenant migrations now; 500 + error if it fails; 409 if one is already running
POST /tenants/{slug}/backup                     202; queued job; outcome appears as `tenant.backup.finished` in the audit log
POST /tenants/{slug}/offboard     {confirm: <slug>, reason, retention_days?}
POST /tenants/{slug}/purge        {confirm: <slug>, reason, without_export?}   refused inside the retention window, under legal hold,
                                  or without a verified final export (unless without_export gives the reason); see docs/ops/FIRM_DELETION.md
GET  /tenants/{slug}/final-export                   admin: download the offboarded firm's final export (X-Content-SHA256)
POST /tenants/{slug}/legal-hold   {hold: true|false, reason}
GET  /audit ?tenant_slug= &staff_email= &action= &result= &limit=
```
Destructive actions need the role, the tenant slug typed in `confirm`, and a reason. A refusal by the domain rules (wrong state, retention, hold) is a 422, never a 500.

## Fleet migrations (TEN-7)
```
php artisan tenants:migrate --queue            # one MigrateTenant job per firm; run several queue workers to go wider
php artisan tenants:migrate --resume --queue   # only firms pending, failed, stuck running (>90 min) or behind this release
php artisan tenants:status                     # latest migration, how many firms are behind, failures
```
Each firm is migrated by one run at a time (a lock in the firm's cache namespace; use a shared cache store such as redis or
database when workers run on several machines). Fleet jobs carry no firm: the worker stays fail-closed and the job opens the
firm it works on. Migration jobs are never retried automatically; `--resume` re-queues what needs it.

## Audit
Everything staff do, including **denials** and **failures**, is written to `platform_audit_log` (control DB, append-only in code): who, action, tenant, parameters, result (`ok|denied|failed`, `busy` for a migration already running), detail, IP, time. Secrets (`password`, `admin_password`, `token`, `secret`) are stripped before storage. PLAT-8 (audited support impersonation) builds on this log.

## Scope and limits
- It never reads tenant business data. Usage is counts and sizes (ENG-24 gauges); "latest usage" is the last `tenants:usage` snapshot (schedule it daily).
- Per-tenant backup: `php artisan backup:run --tenant=<slug>` dumps that tenant's database (and `storage/app/tenants/<slug>`) to the backup disk under `tenants/<slug>/…`. MySQL only, like the existing backup.
- Billing mutations and destructive lifecycle actions require the platform API's `operate` or `destructive` ability and are audited; read-only staff cannot retrieve the signed card-setup URL.
- Platform staff provisioning and role changes remain CLI-only. The browser has no separate staff-management module.
- There is no fleet-wide “migrate everything” button (`tenants:migrate --queue` does that from the CLI).
- Provisioning runs inside the request. Fine for tens of seconds; move it to a queue if your database server is slow.

## Plans and billing in the console

**Plans** (sidebar) is the catalogue firms subscribe to: name, monthly price in each currency, included users / storage /
audit history, and features. Until someone edits it, the plans in `config/billing.php` and `config/entitlements.php` apply
unchanged; the first edit copies them into the control-plane `plans` table (migration `2026_10_26_100000_create_plans_table`),
which is then the source of truth (`App\Billing\PlanStore` overlays it onto config, so billing and entitlements need no changes).

| Action | Who | Rule |
|---|---|---|
| Add / edit a plan | operator, admin | reason required and audited; a price cannot be removed while firms are billed in that currency |
| Archive / restore | operator, admin | archived plans stay for firms on them and are hidden from new sign-ups and plan changes |
| Delete | admin | only a plan no firm is (or was) on; `trial` can never be archived or deleted |

Price edits apply to future invoices and plan changes; a period already invoiced is not re-priced.

**Billing** (sidebar) lists every firm's subscription and every subscription invoice (filter open / overdue / paid / void) with
monthly recurring revenue, unpaid and overdue totals. On a firm's **Billing** tab, operators can start a subscription, change
plan (prorated), cancel at period end, record a payment received outside the system, and send an M-Pesa payment request.
Each of these needs a reason and is written to the audit log. Amounts are stored in minor units (cents) and shown in the
currency's normal unit.

Subscription invoices carry no VAT until `BILLING_TAX_RATE_BP` is set (1600 = Kenya's 16%).

## Creating a firm: plan, welcome email and the firm's own accounts

- **Initial plan** is a dropdown of the plan catalogue (archived plans are hidden). Choosing the free trial starts the trial; choosing a paid plan creates the subscription and its first invoice (optionally after some free days). The firm is never left with a plan name and no subscription.
- **Welcome email**: the first administrator is emailed the sign-in page, their email, the initial password (only when one was generated; an existing person keeps their password) and the plan. It goes through the platform's mail settings (`MAIL_*`; in Docker, catch it in MailHog at <http://localhost:8025>). If sending fails the firm is still created and the console tells you, so you can pass the details on yourself.
- **Each firm's own accounts**: after creating a firm, the operator sets them up on that firm's **Payments & messaging** tab (M-Pesa, card, SMS, email; you land there straight after provisioning). Secrets are stored encrypted in the firm's own database, are never shown again, and the audit log records field names only. Firms do not see or edit these: there is no Integrations page on the firm side. A firm's own value wins over the platform's. In the console, a firm's **Payments & messaging** tab shows which channels are set up and whether they use the firm's account or the platform's. Set `FIRM_OWN_CREDENTIALS=true` to stop M-Pesa, card and SMS from ever falling back to the platform's accounts.

## Invoicing and requesting payment (operator)

- **First invoice**: created when a firm is subscribed to a paid plan (at provisioning or *Start subscription*). A trial plan issues none until it converts.
- **Recurring invoices**: generated automatically by `billing:run` (nightly 02:30) for every subscription whose period has ended, including user/storage overage. *Billing → Generate due invoices* runs the same job on demand; a period is only ever invoiced once.
- **One-off invoices**: open a firm → Billing → *Create invoice* (description, amount, quantity). Tax and credit balance apply as for any invoice.
- **Requesting payment**: on any open invoice, *M-Pesa request* sends an STK prompt to the firm's phone (KES invoices); *Record payment* logs bank/cash/paybill receipts; a saved card (autopay) is charged by `billing:run`; the billing emails carry a signed pay link. Overdue invoices are chased by `dun`.
- Every one of these actions needs a reason and is written to the platform audit trail.

## New firms and branding

Provisioning pre-fills the firm's branding with its own name and the colour picked in the form (default green). The sidebar, buttons, the loading screen and PDFs follow it from the first sign-in; the firm can change the colour, name and logo later in its Branding settings.
