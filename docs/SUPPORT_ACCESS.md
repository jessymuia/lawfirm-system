# Platform support access ("impersonation") (PLAT-8)

Firms hold privileged material, so platform staff never get standing access. Access exists only as a **session** the firm can see, approve and cut off, and every step is provable from the firm's own audit log.

## The flow
1. **Request** (platform, role `support` or `admin`): `POST /api/platform/tenants/{slug}/support-sessions {reason (10+ chars), minutes, mode: read_only|full}`. Refused if the firm has switched support off, if the request exceeds the firm's limits, or if the tenant is not active. One open session per person per firm.
2. **Approval** (firm): by default the firm must approve (`POST /api/v1/support-access/{id}/approve`, or `/deny`). The firm's administrators get an email when a request or a session starts. A firm can instead set a standing approval (`require_approval=false`). Unapproved requests lapse after 24 hours.
3. **Start** (the same staff member): `POST …/support-sessions/{id}/start` creates an **ephemeral support user** inside the firm (`Support: <name> (platform)`, `…@support.invalid`, random password nobody knows, flagged `platform_staff_id`) and returns a **one-time code** valid for 5 minutes. The code is never written to any log.
4. **Enter**: `POST https://<firm-host>/api/v1/support/session {code}` on the firm's own host trades the code for a normal session (cookie) exactly like a login. It works once and only for that firm.
5. **End**: automatically at `expires_at` (never longer than the firm's `max_minutes`), or by staff (`…/end`), or by the firm at any moment (`POST /api/v1/support-access/{id}/revoke`). Ending **deletes the principal**, so every cookie and token it holds dies at once. `support:expire` (scheduled every minute) tidies up; per-request checks refuse expired sessions even before it runs.

## What the firm controls (per tenant, `GET|PUT /api/v1/support-access/policy`, permission `manage-support-access`)
| Setting | Default | Meaning |
|---------|---------|---------|
| `enabled` | true | false refuses new requests and immediately ends everything in flight |
| `require_approval` | true | "requires my approval" |
| `allow_write` | false | permits `full` sessions at all |
| `max_minutes` | 60 | cap (5 to 480) on any session |

Only the firm can change the policy; platform staff can read it, never change it. A policy change is itself recorded in the firm's audit log (old and new values, who).

## What a support session can do
- `read_only` (default): role `support-read` = every `list-*` / `view-*` permission, **and** the guard rejects every non-GET request regardless of permissions.
- `full` (only if the firm allows): role `support-full` = everything except `manage-*` (so no license, matter-access, ethical-wall or support-access control), `export-firm-data`, `access-all-matters`, and role/permission editing. The roles are rebuilt on every start, so permissions added by later releases are included or excluded by those rules.
- It is an ordinary user as far as matter access and ethical walls go: with `MATTER_ACCESS=restricted` it sees only what the firm has granted it; a wall applies to it like to anyone.
- It does not use a licensed seat.

## What the firm can prove afterwards
- **Audit log (`audits`)**: the session record (who, why, mode, minutes, approval, start, end, how it ended), tagged `support-access`; every create/update/delete the principal makes, attributed to the principal user (whose name says it is platform support); and policy changes.
- **Request log**: `support_access_log` has one row per request the principal made (method, path, status, IP, time); `GET /api/v1/support-access/{id}/log`, including refused attempts.
- **Platform side**: `platform_audit_log` (PLAT-7) records `support.request|start|end` with the staff member, reason and outcome (failures included).
All of it lives in the firm's own database, so a tenant export carries the proof with it.

## Limits
- The support user is subject to the firm's matter access; in `restricted` mode a firm must grant it access to see matters (by design: the firm decides).
- Write-capable sessions are logged, not previewed: the audit shows what changed, not "would change".
- No MFA for platform staff yet (SEC-12); the platform host should sit behind a VPN / IP allow-list.
- Email goes through the application's normal mail configuration; a mail outage never blocks or hides the access record.
- Permission catalog sync for existing multi-tenant firms: `TenantMigrator` now syncs permissions into each tenant's admin role after migrating (it did not before; new permissions from earlier releases reach existing firms on their next `tenants:migrate`).
