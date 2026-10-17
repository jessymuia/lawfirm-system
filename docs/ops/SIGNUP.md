# Self-service signup and trials (PLAT-13)

Multi-tenant (SaaS) only. A law firm signs itself up, confirms its email, and gets its own firm on a free trial. It
then chooses a paid plan or lets the trial lapse. Staff watch the funnel from the platform console.

## Turning it on

| Setting | Meaning |
|---|---|
| `TENANCY_SIGNUP_HOST` | The host that serves signup, e.g. `join.example.com`. Unset means signup is off (404 everywhere). It must not be a firm's domain or the console host. |
| `TENANCY_BASE_DOMAIN` | Required. A new firm gets `<address>.<base domain>`, so DNS and TLS need a wildcard for it. |
| `BILLING_TRIAL_DAYS` (14), `BILLING_TRIAL_CURRENCY` (KES) | Trial length and the currency of its subscription. |
| `BILLING_TRIAL_OFFBOARD_AFTER_DAYS` (60) | Days after a trial lapsed before the firm is offboarded. 0 = never. |
| Mail | The verification link is emailed, so a working mailer is required. |
| Queue | Provisioning runs as a queued platform job (`ProvisionSignup`). With the default `sync` queue it runs inside the "confirm" request. |
| Scheduler | `billing:run` (daily) ends lapsed trials and offboards long-lapsed ones. |

Reserved addresses (`www`, `admin`, `api`, `app`, `signup` and others) are listed in `config/tenancy.php`
(`reserved_slugs`).

## The journey

1. **`GET /signup`**: the page on the signup host. It needs no JavaScript bundle and has a strict CSP.
2. **`POST /api/signup`** with firm name, address (slug), name, email, password (10+ characters, letters and numbers),
   terms accepted. Limited to 5 an hour per IP.
   - The signup host never touches a firm's data.
   - Nothing is created yet. A `signups` row keeps only the SHA-256 of the emailed token and the bcrypt hash of the
     password.
   - The person's unverified request holds the address against other people for 24 hours.
   - A form field hidden from people turns away bots that fill every field.
3. **The emailed link (`/signup/verify?token=…`)** posts the token and queues `ProvisionSignup`. That job creates:
   - the firm: database, schema, catalog and roles;
   - its administrator: the person who signed up, with the password they chose (the stored hash is cleared afterwards);
   - a `trial` subscription.

   The page polls `GET /api/signup/status` and then sends the person to `https://<address>.<base domain>/login`.
   - A failed run is recorded on the signup. Opening the link again resumes it, because provisioning is resumable (TEN-9).
   - An address taken in the meantime is reported (409), never overwritten.
   - Someone who already has a login (another firm) keeps their existing password: one person, one login.

## The trial

- The firm is on the `trial` plan's entitlements (`config/entitlements.php`), with `tenants.trial_ends_at` set.
- **In the last 3 days**, every API response carries a notice (`X-License-Notice`), and the web app shows it as a
  banner.
- **When it ends**, the firm is read-only: reads and exports work; writes answer `403 {code: trial_expired}`. Choosing a
  plan, signing in and signing out stay open. `billing:run` marks the subscription `expired`, and does not roll it into
  another free month.
- **Choosing a plan** (Settings → Subscription, permission `manage-subscription`, `POST /api/v1/subscription/plan`):
  - during the trial, the plan changes at once and is first invoiced when the trial ends;
  - after a lapsed trial, it starts and is invoiced at once, and the firm is writable again.

  Either way `subscriptions.converted_at` records the conversion.
- **Never converted**: after `BILLING_TRIAL_OFFBOARD_AFTER_DAYS`, `billing:run` offboards the firm. The usual retention
  window, final export and purge follow (docs/ops/FIRM_DELETION.md), and a legal hold still blocks the purge.

## The funnel

`GET /api/platform/billing/funnel?days=30` (platform staff):

- signups submitted, verified, ready and failed;
- trials started, running, converted and lapsed;
- `conversion_rate`: converted ÷ (converted + lapsed), so running trials do not count against it;
- `median_days_to_convert`.

## Getting started in the app

A new firm's dashboard shows a checklist, worked out from the firm's own data:

1. add a client;
2. open a matter for them (opens the new-matter form with that client chosen);
3. invite a colleague;
4. turn on two-factor.

Steps the person may not do are left out. It disappears when everything is done, or when the person hides it
(`users.onboarding_dismissed_at`).

## Not included

- **No CAPTCHA.** Abuse control is the per-IP limit, the hidden field and email verification. Put the signup host behind
  your edge's bot protection if signups are abused.
- **No outbound payment flow.** Choosing a plan issues invoices that are paid through the existing M-Pesa and Stripe
  webhooks or recorded by staff (PLAT-4).
