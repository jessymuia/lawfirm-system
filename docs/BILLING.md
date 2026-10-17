# Subscription billing and metering (PLAT-4)

Control plane, SaaS only (`billing:run` is a no-op on single-tenant installs, which use licensing, PLAT-2).
**All prices in `config/billing.php` are placeholders: replace them with your real price list.**

## Model
- One subscription per firm: plan, currency (KES/USD), provider label (`manual|stripe|mpesa`), billing email, period, trial, credit balance.
- **Billing in advance.** Each period's base price is invoiced when the period starts, together with the *previous* period's
  metered overage. Periods are calendar months from the subscription start.
- **Metered dimensions = ENG-24 metrics**: peak `users` in the period and last `storage_bytes` of the period (rounded up to whole GB),
  billed above the plan's included limit from `config/entitlements.php`. Unlimited plans never incur overage.
  Metering relies on `tenants:usage` running daily; days with no snapshot are not billed.
- **Plan changes** take effect at once (the firm's entitlements follow). Unused time on the old plan is credited and the rest of the period
  charged on the new one, prorated by exact seconds. A net charge becomes an `adjustment` invoice; a net credit goes to the credit balance, which
  the next invoice consumes. During a trial a change is free.
- Money is integer minor units everywhere, rounded half up; tax is `BILLING_TAX_RATE_BP` basis points (default 0: set it from your own tax advice) on the amount after credit.
- Invoice numbers `BI-<year>-<seq>`; one period invoice per subscription per period (unique), so re-running is safe, including after a crash mid-run.

## Payments
- Every payment is unique on (provider, reference): a replayed webhook or a double-submitted form cannot pay twice. Partial payments are kept; overpayment becomes credit.
- **Stripe**: `POST /api/platform/webhooks/stripe`, `Stripe-Signature` verified (HMAC-SHA256 of `t.body`, constant-time, 5-minute tolerance against replay). An unset secret rejects everything.
  Handles `checkout.session.completed` with `payment_status=paid`; `client_reference_id` must be the invoice number.
- **M-Pesa C2B (paybill)**: `POST /api/platform/webhooks/mpesa/{token}`; the customer pays the paybill with the **invoice number as the account reference**.
  Safaricom does not sign callbacks, so the URL carries a secret token (`MPESA_CALLBACK_TOKEN`; keep it out of logs and proxies). KES invoices only.
- Unmatched or invalid notifications are acknowledged and recorded in `billing_events` (`unmatched|rejected|ignored`) for reconciliation, never retried forever.
- Staff can record offline payments (bank transfer) in the console with an idempotency reference.
- Webhooks answer only on the platform host.

## Dunning
`billing:run` (daily 02:30, after `tenants:usage`) first invoices due periods, then dunns: an open invoice past its due date marks the subscription `past_due`
and sends one reminder per step (`reminder_days`, default 3/7/14; a late run sends one notice, not one per missed step). `BILLING_SUSPEND_AFTER_DAYS`
(default 14; 0 = never) suspends the firm that many days past due; paying the last overdue invoice resumes a firm **that billing suspended** (a firm suspended by staff stays suspended).
Data is never touched.

## Console (platform staff)
`GET /tenants/{slug}/billing`, `GET /billing/invoices/{number}` (view); `POST /tenants/{slug}/subscription`, `.../subscription/plan`, `.../subscription/cancel`,
`POST /billing/invoices/{number}/payments` (operate; a reason is required and every action is audited).

## Asking for payment (outbound)
With the platform's own accounts (never a firm's FUN-11 collection keys):
- **Card (Stripe Checkout):** `STRIPE_SECRET_KEY`. Every open invoice has a stable, signed pay link
  `https://<platform host>/api/platform/pay/<number>?sig=…` (HMAC with the app key, so numbers cannot be walked). Each visit
  creates a fresh Checkout session for what is still owed, with the invoice number as `client_reference_id`, so the
  existing signed webhook records the payment. The link is in every payment reminder and in the console's invoice view
  (`pay_url`). Afterwards Stripe sends the payer to `BILLING_PAY_SUCCESS_URL` (default: the status page).
- **M-Pesa prompt (STK push):** `MPESA_CONSUMER_KEY`, `MPESA_CONSUMER_SECRET`, `MPESA_SHORTCODE`, `MPESA_PASSKEY`,
  `MPESA_ENVIRONMENT=sandbox|production`, `MPESA_CALLBACK_TOKEN`. Staff send it from the console:
  `POST /billing/invoices/{number}/mpesa {phone, reason}` (operate role; audited without the phone number). KES invoices
  only; the amount is the balance rounded up to whole shillings (Daraja takes no cents; the excess becomes credit).
  Safaricom's result arrives at `/api/platform/webhooks/stk/<token>/<number>`: a paid result is recorded once (the M-Pesa
  receipt is the payment reference); a cancelled or failed one is logged in `billing_events` as `ignored`.
- **Paybill (C2B):** `php artisan billing:mpesa-register` registers `/api/platform/webhooks/c2b/<token>` as the
  confirmation URL (run once per shortcode and after the platform host changes). Daraja refuses URLs containing "mpesa"
  or "safaricom", hence the neutral path; the old `/webhooks/mpesa/<token>` keeps working. Reminders for KES invoices
  name the paybill and the account number to use.

## Firm-side billing screen

Users with `manage-subscription` can view the firm's current plan, switch paid plans, review the 50 most recent subscription
invoices, and schedule cancellation at the end of the current period. Open invoices show a Stripe Checkout link when card
collection is configured; KES invoices also show the configured M-Pesa paybill and invoice-number account reference when
paybill collection is configured. Cancellation does not end access before the current period closes.

## Saved cards and automatic payment
- **Saving a card:** the console's billing view (`GET /tenants/{slug}/billing`) gives `autopay_url`, a signed link per
  subscription to send the firm. It opens Stripe Checkout in *setup* mode for the firm's Stripe customer (created on
  first use, idempotently). Stripe's signed `checkout.session.completed` webhook then stores the payment method's id
  and a label (`visa •••• 4242, expires 12/2030`) and turns automatic payment on. Saving a new card detaches the old one.
  Card numbers never reach the platform.
- **Charging:** `billing:run` charges every open invoice of those firms off-session for what is still owed, before any
  reminder goes out. At most `BILLING_AUTOPAY_MAX_ATTEMPTS` (3) attempts per invoice, `BILLING_AUTOPAY_RETRY_DAYS` (3)
  apart, each with its own idempotency key and claimed first, so overlapping runs or a retried request cannot charge
  twice. A success is recorded at once (the later `payment_intent.succeeded` webhook is a duplicate); a decline is
  recorded in `billing_events` (type `autopay`, outcome `failed`, with Stripe's decline code) and the usual reminders,
  with their pay link, carry on. A card that needs its holder (`authentication_required`, 3-D Secure) is not retried.
- **Removing:** `DELETE /tenants/{slug}/autopay {reason}` (operator role, audited) turns it off and detaches the card.

## Not done / not verified
- M-Pesa has no automatic charging (no standing-order integration); shilling invoices are paid by prompt, paybill or card.
- A firm cannot yet save or remove its card from its own settings: staff send the link and remove cards on request.
- **The Stripe and Daraja calls and callbacks are written from their public documentation and tested with requests and payloads built to that format; they have not been exercised against the real services.**
  Before going live: Stripe test mode (`sk_test_…`, Stripe CLI `stripe listen --forward-to …/webhooks/stripe`) and the Safaricom sandbox (shortcode 174379 with its test passkey).
- No recurring card charges or retries (dunning here is reminders and optional suspension), no refunds or credit notes, and no customer-facing invoice PDFs or billing portal.
- Billing email is a single address; reminders are plain text mail.
- A subscription's tenant is not auto-created or auto-provisioned from a signup flow (PLAT-12/13 territory).
