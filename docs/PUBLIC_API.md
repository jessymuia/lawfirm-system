# Public API and webhooks (PLAT-11)

A read-only API for a firm's own integrations, plus signed webhooks. Needs the `api_access` plan feature (see ENTITLEMENTS.md).
The machine-readable contract is [`openapi-public.yaml`](openapi-public.yaml); a test fails if it and the routes drift apart.

## Keys

A firm administrator (permission `manage-api-access`) creates keys under `POST /api/v1/integrations/api-keys`
with a name, one or more scopes, the user the key acts as, and optionally an expiry and a rate limit.
The key (`lfk_<prefix>_<secret>`) is shown **once**; only a SHA-256 hash is stored. Revoking is immediate and audited.

| Scope | Reads | The key's user also needs |
|---|---|---|
| `clients:read` | clients | `list-clients` |
| `cases:read` | matters | `list-cases` |
| `hearings:read` | hearings | `list-hearings` |

A key acts as its user: matter access and ethical walls apply to it exactly as they do to that person, so a key never sees more
than its user. Removing the user's permission, deleting the user, or dropping the plan's `api_access` stops the key at once.
Support principals cannot own keys. Every authentication failure is the same `401 invalid_api_key`.

```
curl -H "Authorization: Bearer lfk_…" "https://<firm-host>/api/v1/public/clients?per_page=50&updated_since=2026-01-01T00:00:00Z"
```

Pagination: `per_page` (1–100), `page`; `meta` carries paging data. `updated_since` is an ISO‑8601 timestamp.
Only an allow-list of fields is returned (no descriptions, notes, outcomes or opposing parties).

**Identifiers (DATA-9).** Every `id` and every reference (`client_id`, `case_id`) is the record's **public id**, a 26-character
ULID such as `01J9ZQ3V5W8XK2M4N6P8R0T2V4`. Public ids sort by creation time, never change, and survive an export/import to another
installation. Integer database ids are never returned and never accepted (`/clients/42` is a 404). Hearing types are returned as
`{"key": "trial", "name": "Trial"}`: `key` is the stable system catalog key (TEN-11), `null` for a firm's own type. Webhook payloads
use the same ids.

## Rate limits

Per key, default 60 requests/minute (`PUBLIC_API_RATE_LIMIT`, per-key override up to 1200). Responses carry `X-RateLimit-Limit` and
`X-RateLimit-Remaining`; over the limit you get `429 rate_limited` with `Retry-After`.

## Webhooks

Register endpoints under `/api/v1/integrations/webhooks` (HTTPS URL, event list or `*`; at most 10 per firm).
Events: `client|case|hearing` × `created|updated|deleted`, and `ping`. The signing secret (`whsec_…`) is shown once and stored encrypted;
`rotate-secret` replaces it immediately.

Each delivery is a `POST` with headers `X-Webhook-Id` (stable event id), `X-Webhook-Event`, `X-Webhook-Timestamp`, `X-Webhook-Signature: t=<ts>,v1=<hex>`.
The signature is HMAC‑SHA256, keyed by the secret, over `"<t>.<raw request body>"`.

```php
$expected = hash_hmac('sha256', $t.'.'.$rawBody, $secret);
$ok = hash_equals($expected, $v1) && abs(time() - (int) $t) < 300;   // reject old timestamps to stop replays
```
```python
expected = hmac.new(secret.encode(), f"{t}.".encode() + raw_body, hashlib.sha256).hexdigest()
```

Payloads carry the same safe fields as the read API. Respond 2xx within 10 s to acknowledge. Redirects are not followed.

**Retries.** Anything else (non-2xx, timeout, connection error) is retried after 1, 5, 30, 120 and 360 minutes; after 6 attempts the delivery is
`dead`. After 10 consecutive dead deliveries the endpoint is switched off (re-enable with `PUT`). `POST …/deliveries/{id}/replay` resends a finished
delivery with the same event id (409 while one is in progress). Delivery logs (no payloads) are kept 30 days.
`php artisan webhooks:deliver` runs every minute from the scheduler.

## Limits you should know

- **At-least-once, unordered.** De-duplicate on `X-Webhook-Id`; use `updated_since` to reconcile.
- Webhook events are firm-level and **do not apply ethical walls** (an administrator configures them); API keys do.
- Keys are bearer secrets bound to a user; they are not subject to the MFA policy. Treat them like passwords.
- Destination URLs pass the SSRF guard at registration and again at every attempt; DNS rebinding between check and connect is a residual risk.
  `SSO_BLOCK_PRIVATE_HOSTS=false` (self-hosted, internal receivers) relaxes it for webhooks too.
- Read-only: no write scopes. Only clients, matters and hearings.
- Versioning and deprecation follow API_VERSIONING.md.
