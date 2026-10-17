# System reference catalog (TEN-11)

Courts, court types, hearing types, currencies and tax rates are **platform-owned reference data**. Every firm gets the same entries, and an upgrade
keeps them current in every firm. Firms add their own rows beside them and give system rows a local name. They cannot rename
or delete a system row.

| | System rows | Firm rows |
|---|---|---|
| Who creates them | the platform (`SystemCatalog::sync()`) | the firm (UI / API) |
| Rename | no; the firm sets an **alias** (local display name) | yes |
| Delete | no (409 `system_catalog`) | yes |
| Changed by upgrades | yes, to match the catalog | never |
| API fields | `is_system: true`, `official_name`, `alias` | `is_system: false` |

Expense categories are **not** in the catalog: they are each firm's own chart of accounts. A new firm gets a starter set
(`ExpenseCategorySeeder`) once, and upgrades never touch it.

## Where it lives

- Definition: `app/Catalog/SystemCatalog.php` (`COURT_TYPES`, `COURTS`, `HEARING_TYPES`), keyed by a stable `catalog_key`.
- Columns on `court_types`, `courts`, `hearing_types`: `catalog_key` (unique, null for firm rows), `is_system`, `alias`.
- Guard: `App\Catalog\CatalogRow` (model trait). Outside the sync, a system row accepts only `alias` changes and cannot be deleted. Nothing can turn a firm row into a system row or create one flagged as system. Because it runs on the model, the API, console, jobs and imports all get it.

## When it syncs

`SystemCatalog::sync()` is idempotent and runs:

- in `app:upgrade` (single-firm installs), after migrations and the permission catalog;
- in `tenants:migrate` for every firm (so also `app:upgrade` in multi-tenant mode), after that firm's migrations;
- when a firm is provisioned;
- on demand: `php artisan catalog:sync [--dry-run] [--tenant=slug]`.

For each entry it finds the row by `catalog_key`. If there is none, it **adopts** a firm row with the same name (case-insensitive), so a firm seeded before TEN-11 keeps its ids and everything that points at them. Otherwise it creates the row. It then sets the official name, the court's type, and `archive` for retired entries. A soft-deleted system row is restored. The firm's `alias` is never touched. A run with nothing to change writes nothing, so it adds no audit rows.

If the catalog columns do not exist yet (new code running before its migration on a lagging install), the sync does nothing and returns `null`. `catalog:sync` reports that and exits non-zero.

## Changing the catalog (platform developers)

- **Add** an entry: add a new key. The next upgrade creates it in every firm, or adopts a same-named firm row.
- **Rename** an entry: change its `name`. Every firm's row is renamed, and aliases still win for display.
- **Retire** an entry: set `'retired' => true`. The row stays, so matters and hearings that point at it keep resolving. It is archived and the API reports `retired: true`.
- **Never** remove or reuse a key. A key is the row's identity in every firm, in exports, and on every self-hosted install, whatever version it runs.
- Catalog names are public legal reference data. The initial Kenyan court list (superior courts and the main Nairobi stations) should be reviewed by someone who practises there before it is extended.

## Behaviour changes for existing firms

- After upgrading, a firm row whose name matches a catalog entry (for example the seeded "High Court" court type) becomes a system row. The firm can no longer rename or delete it, but can set an alias. Its id does not change.
- Wherever a court is displayed (matters, hearings, dashboard, exports, hearing-reminder SMS), the firm's alias is shown if it set one.
- `courts` joins the portability bootstrap tables: a fresh install now holds the system courts, so `portability:import --replace-bootstrap` replaces them with the bundle's rows. The next `app:upgrade` or `catalog:sync` adopts the system rows in a bundle exported before TEN-11.

## Currencies and tax rates (DATA-14)

Defined in `SystemCatalog::CURRENCIES` (keyed by ISO 4217 code) and `SystemCatalog::TAX_RATES` (keyed by `catalog_key`, e.g. `ke_vat_standard`). Both tables have `is_system`. The rules differ a little from courts, because part of each row is the firm's own choice.

| | The platform decides (kept current by the sync) | The firm decides (never touched by the sync) |
|---|---|---|
| Currency | name, minor units, symbol | whether it is enabled for invoicing |
| Tax rate | name, percentage, jurisdiction | whether it is the default; whether it is archived |

- **New currencies** arrive disabled. The firm's base currency is always available.
- **A new tax rate** marked `default` becomes the firm's default only if the firm has none yet.
- **Adoption:** a firm's own tax rate with the same name (case-insensitive) and jurisdiction is adopted, like a same-named court, so invoice lines that use it keep their link.
- **Changing a system rate through the API:** changing its name, percentage or jurisdiction answers 409 `system_catalog`. The firm can still make it the default or archive it. Changing a system currency through the API only turns it on or off.
- **When the law changes** (say VAT moves to 18%), change the entry's `rate`. The next upgrade updates every firm. Invoice lines charged before keep the percentage they were charged at (`invoice_items.tax_rate_percent`), so no issued invoice changes.

UTBMS codes (FUN-13) stay in code (`App\EBilling\Utbms`, docs/EBILLING.md), not in a table, by decision. They are a fixed public standard that firms can neither extend, rename nor turn off. Every firm and every install version therefore already has exactly the same set, and they ship and update with the release, which is what the catalog would give them.

## Not covered yet

- Bulk query-builder writes (`DB::table('courts')->update(...)`) bypass the model guard. The sync repairs a tampered system row on its next run, but application code must not write these tables that way.
- A firm that renamed a seeded row before TEN-11 (for example "High Court" to "High Court of Kenya") is not matched by name. That firm gets the system row beside its own.
