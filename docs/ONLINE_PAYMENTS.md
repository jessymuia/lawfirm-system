# Online invoice payment: M-Pesa and card (FUN-11)

A client pays a submitted invoice from a link, by M-Pesa (STK push to their phone) or by card (Stripe Checkout).
A successful payment is recorded as an ordinary receipt against the invoice, so balances, reports and the
append-only payment ledger (DATA-10) behave exactly as for a payment taken at the counter.

## Payment links

- **Create:** on a submitted invoice, *Take payment online* creates a link (`POST /api/v1/invoices/{invoice}/payment-links`,
  permission `create-payments`) and can text it to the client's phone. The link is `https://<firm-host>/pay/<token>`.
- **Secret:** the 40-character token is shown once; only its SHA-256 is stored. Links expire (default 30 days) and can be
  revoked (`DELETE …/payment-links/{link}`). An invoice can have several links; the list shows who sent each one.
- **What it reveals:** the payment page shows only what the client already received: invoice number, amount due,
  currency, due date and the firm's name.

## Paying

- **M-Pesa:** the client enters a Safaricom number; an STK push asks them to confirm on the phone. The page polls the
  attempt until Safaricom reports the result. Amounts are whole shillings (KES only).
- **Card:** the client is redirected to Stripe Checkout and back; the Stripe webhook confirms the payment.
- **Limits:** a payment never exceeds what is outstanding. A callback that arrives twice is recorded once (attempts are
  completed idempotently inside a transaction, and a late payment is capped at the balance still due).

## Callbacks

| Provider | URL | Authentication |
|---|---|---|
| M-Pesa | `https://<firm-host>/api/v1/pay/callback/mpesa/<secret>` (set per attempt automatically) | per-attempt secret in the path, stored hashed |
| Stripe | `https://<firm-host>/api/v1/pay/callback/stripe` (configure once in the Stripe dashboard, events `checkout.session.completed` and `checkout.session.expired`) | `Stripe-Signature`, 5-minute tolerance |

Both answer 200 to anything they do not recognise so providers stop retrying. Callbacks are accepted even while the
firm is read-only (an expired licence must not lose a client's money).

## Credentials

Each firm uses its own merchant accounts. The platform operator enters them per firm in the operator console (Payments & messaging tab); they are stored encrypted (SEC-15):

- `mpesa`: consumer key, consumer secret, shortcode, passkey, environment (`sandbox` or `production`).
- `stripe`: secret key, webhook signing secret.

Self-hosted installs can set them in `.env` instead (`MPESA_COLLECT_*`, `STRIPE_COLLECT_*`); firm credentials win.
A method without credentials is simply not offered.

## Not covered

- The client pays the whole outstanding balance; partial amounts and payment plans are not offered.
- Refunds are made in the provider's dashboard and recorded by hand.
- Card payments in a currency the firm's Stripe account does not support fail at Checkout.
