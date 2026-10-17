# Matter-level access control (SEC-10)

Before this, any user holding `list-cases` saw **every matter in the firm**. Matter access narrows that to the matters a user is actually on.

## Switch
`MATTER_ACCESS` (config `matters.access`):

| Value | Behaviour |
|-------|-----------|
| `firm` (default) | Historical behaviour: module permissions decide, every matter is visible. Chosen as the default so an upgrade never silently hides data from an existing firm. |
| `restricted` | A user sees a matter only through one of the paths below. |

Set `MATTER_ACCESS=restricted` in `.env` (compose: add it to the app environment list; Helm: `extraEnv`). It is deployment-wide today; a per-tenant setting is a follow-up (SaaS tenants would read it from the tenant record).

## Who can see a matter (restricted mode)
1. the user it is **assigned to** (`cases.assigned_to`),
2. the user who **created** it,
3. anyone on its **team** (`attorneys` rows: case, advocate, role),
4. anyone with an unexpired, unrevoked **explicit grant** (`matter_access_grants`),
5. anyone holding the **`access-all-matters`** permission (granted to `admin` by `app:upgrade`/`app:install`; give it to partners or records staff deliberately).

Module permissions still apply on top: matter access answers *which* matters, `list-cases`/`update-hearings`/… answer *what you may do*.

## What is enforced
One Eloquent global scope (`App\Matters\MatterScope`, applied by the `MatterScoped` trait) on `Cases`, `Hearing`, `Document`, `Expense`, `TimeEntry`, `TrustTransaction`, `Invoice` and, through their invoice, `Payment` and `InvoiceItem`. It therefore covers list, show, update, delete, search, exports (`/export/cases|expenses|invoices`), dashboard counts, relations and document access without per-controller code.

- A hidden matter answers **404**, never 403, so its existence is not revealed.
- **Writes** are guarded too: creating a record against, or moving it into, a matter the user cannot access throws (403). Request validators only check that a case exists.
- The **full-firm export** is refused (403) to anyone who is restricted; it is a complete extraction.
- Contexts with no logged-in user (console commands, queue workers, scheduler) are not restricted.

## Granting access
`manage-matter-access` permission, and the manager must themselves be able to see the matter (a manager cannot manage, or discover, a matter they cannot see).

```
GET    /api/v1/cases/{case}/access          assigned_to, created_by, team, active grants
POST   /api/v1/cases/{case}/access          {user_id, reason?, expires_at?}  (re-granting updates, never stacks)
DELETE /api/v1/cases/{case}/access/{grant}  revoke (soft delete)
```
Grants and revocations are audited (owen-it `audits`: who, when, old/new values). `expires_at` gives time-boxed access, enforced on every query.

## Known limits (be aware before relying on it)
- `Client` records are not matter-scoped: a client is shared across matters. Hiding clients from a specific user is what a client-level ethical wall does (docs/ETHICAL_WALLS.md).
- `Attorney` (team) and `DocumentAccess` (access log) rows are unscoped bookkeeping.
- Rows are hidden by `case_id`; raw `DB::table()` queries bypass the scope (the application has none outside tenancy/bookkeeping code; keep it so).
- Files already shared by an absolute storage URL are governed by storage configuration, not by this scope.
- Tasks have no matter link today, so they are not matter-scoped.
