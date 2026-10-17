# Authorization: deny by default (ENG-11)

Every route under `auth:sanctum` in `routes/api.php` goes through `authz.guard`
(`RequireAuthzDeclaration`). A route with **no `authz:*` declaration is refused
with 403 before its controller runs** and logged as critical.

## Declaring a route

| Declaration | Meaning |
|---|---|
| `authz:permission,<perm>` | The route needs one permission. Enforced by `AuthorizeRoute` before the controller runs. |
| `authz:policy,<ability>,<Model>[,<route param>]` | The model's **Policy** decides, before the controller runs. With a route parameter the record is loaded through the model's scopes (by id or public id; one the user cannot reach is a 404) and passed to the policy. Without one the check is class-level (`viewAny`, `create`, `deleteAny`). Use it wherever the rule depends on the record. |
| `authz:authenticated` | Self-service endpoints any signed-in user may call (profile, logout, staff dropdown, search). |

There is no mode that leaves authorization to the controller: the last `authz:inline` routes moved to policies and
the mode was removed, so `authz:inline` now fails like any unknown mode. Unknown modes throw (a typo can never become
"allow"). New public routes must be added to `PUBLIC_ROUTES` in `tests/Feature/RouteAuthorizationTest.php`.

## Policies

`app/Policies` has one policy per model (`PolicyCoverageTest` fails if a model has none). They extend
`ModulePolicy`, which is deny by default:

- each policy maps abilities to permissions; an ability it does not map is refused for everyone, and `forceDelete`
  is never allowed;
- abilities on one record (`view`, `update`, `delete`, `restore`) also require the record to be within the matters
  the user may reach (`MatterAccess`: the SEC-10 access rule and SEC-11 ethical walls). This holds even where a query
  forgot its scope;
- append-only records have no update or delete: trust transactions (`void` instead), payments (no update), documents
  (a change is a new version), and the document-access and wall-attempt logs (read only).

Object-level rules live in the policy, for example `InvoicePolicy::delete`: anyone with `delete-invoice`, otherwise
only one's own draft, and a refusal is a 404. Code that needs a decision uses `Gate::allows(...)` /
`Gate::authorize(...)` (bulk invoice delete checks every row, search includes a result type only if `viewAny` allows
it). Spatie's permission hook runs first on every check. An ability that is not a permission name simply counts as
"not held".

## Found and fixed while building this

- Empty scaffold actions (`show() { // }`, `index() {}` ...) were routed and answered
  200 to users with no permissions. They are no longer routed.
- The SPA catch-all served an HTML 200 for unknown `GET /api/*`; it now returns a JSON 404.
- The deprecated `/api` alias duplicated route names, which broke `php artisan route:cache`
  (run by `deploy/deploy.sh`). Legacy routes are now named `legacy.*`.

## Status (ENG-11)
Every module is authorized at the route: `authz:permission` for permission-only actions, `authz:policy` where the
record matters (invoice delete and bulk delete), `authz:authenticated` for self-service and search. Routes where the
controller authorizes itself: 85 → 0. Policies cover every model, and `PermissionMatrixTest` asserts default deny.
