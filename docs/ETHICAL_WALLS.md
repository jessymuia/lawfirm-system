# Ethical walls / conflict screens (SEC-11)

A **screen** bars one user from one matter, or from every matter of one client. It is for conflicts of interest (acting against a former client, adverse matters, a lawyer who used to act for the other side).

## Rules
- A wall **overrides every other access path**: assignment, creator, team, explicit grant (SEC-10) and the `access-all-matters` bypass. It applies in `MATTER_ACCESS=firm` mode as well; walls do not depend on matter-level access being switched on.
- A **client wall** hides the client and all that client's matters, including matters opened after the wall.
- Screens are **lifted, never deleted** (`lifted_at`, `lifted_by`, `lift_reason`), so the history stays reportable. The model refuses deletion.
- A user **cannot screen or unscreen themselves**.
- Users with no active screen are unaffected, and so are console, queue and scheduler contexts that have no logged-in user.

## Surfaces covered
Everything that goes through the matter scope (SEC-10): list, show, update, delete, **global search**, **exports**, dashboard counts, documents, hearings, time, expenses, trust, invoices, payments. A screened user gets 404 for walled material (existence is not revealed), writes against it are refused (403), and client records of a client-walled client are hidden.

**Notifications:** `hearings:send-reminders` no longer sends the screened lawyer an SMS about a matter they are walled from (the client's SMS is unaffected); the skip is logged as an attempt.

## Attempt log
`ethical_screen_attempts` is append-only (model refuses update/delete). One row per request that points at walled material, whatever the outcome (404, an empty filtered list, a refused write): user, screen, matter/client, surface (`api` | `write` | `notification`), method, path, HTTP status, IP, time. It also writes a `ethical_screen.attempt` warning to the application log.

## API (`manage-ethical-screens`)
```
POST /api/v1/ethical-screens              {user_id, case_id | client_id, reason}   idempotent for an identical live screen
POST /api/v1/ethical-screens/{id}/lift    {reason}
GET  /api/v1/ethical-screens              ?status=active|lifted|all &user_id= &case_id= &client_id= &format=csv
GET  /api/v1/ethical-screens/attempts     ?user_id= &screen_id= &case_id= &from= &to= &format=csv
```
Reports read the tables directly: a compliance officer can see **which** matters are walled and who tried to reach them **without** being given access to the matters. Creating and lifting a screen is recorded in the standard audit trail (`audits`).

## Known limits
- A **matter-level** wall leaves the client record visible (the client has other matters); use a client wall to hide the client.
- Attempt logging inspects the ids in the URL and in `case_id`/`client_id`/`invoice_id` inputs. A request that reaches walled material by some other parameter name would be hidden by the scope but not logged. New endpoints that take other identifiers must be added to `LogWallAttempts::TABLES`.
- Notifications other than hearing reminders carry no matter information today (task assignment mails have no case link).
- The attempt table is protected in code, not by database triggers.
- Walls are not yet exposed in the SPA; this release is API and reporting only.
