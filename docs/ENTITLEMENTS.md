# Entitlements (PLAT-1)

One API decides "may this firm use X?" for **both** deployments. Domain code never reads the deployment
mode, the plan name or a license.

```php
Entitlements::has('trust_accounting')        // bool
Entitlements::require('trust_accounting')    // throws EntitlementException -> 403 {code: entitlement_required, feature}
Entitlements::limit('users')                 // ?int, null = unlimited
Entitlements::requireWithin('users')         // throws when one more would exceed the limit -> 403 {..., limit}
```
Routes: `entitled:<feature>` and `entitled.limit:<limit>` (run after the route's permission check where the
permission middleware is on the route itself). `GET /api/v1/entitlements` returns plan, features, limits and
current usage for the SPA.

## Where answers come from (`EntitlementSource`)
| Deployment | Source | Reads |
|---|---|---|
| SaaS (`TENANCY_MODE=multi`) | `TenantPlanSource` | `tenants.plan` (empty -> `entitlements.default_plan`) + `tenants.entitlements` JSON overrides |
| Self-hosted | `ConfiguredPlanSource` | `ENTITLEMENT_PLAN` (default `self_hosted`: everything on, unlimited). **PLAT-2 replaces this with a signed, expiring license** |

Only `AppServiceProvider::register()` chooses the source. A test fails the build if domain code reads
`isMulti()` / `tenancy.mode`.

## Rules
- **Fail closed.** Unknown plan, unknown feature, unknown limit, or no tenant -> denied / limit 0. Never "everything".
- Plans live only in `config/entitlements.php`. Overrides (`{"features": {...}, "limits": {...}}`) win over the plan and
  cannot introduce unknown keys or non-integer limits.
- Answers are memoised per tenant and cleared before every queued job, so a plan change reaches workers.
- Denials are not reported as errors (they are expected, and the SPA turns `entitlement_required` into an upgrade prompt).

## Gated today
`time_tracking` (time entries), `payments`, `trust_accounting`, `firm_data_export`, `client_portal` (FUN-10), and the `users` seat limit on user creation.
Declared but not yet enforced: `exports`, `document_versioning`, `sso` (SEC-12), `api_access` (PLAT-11), the `storage_bytes` limit (ENG-22, uploads).

## Not built (other backlog items)
Subscription state and trial expiry (PLAT-4/13), license file (PLAT-2), feature flags per tenant and edition policy (PLAT-3),
an admin UI for overrides (PLAT-7).
