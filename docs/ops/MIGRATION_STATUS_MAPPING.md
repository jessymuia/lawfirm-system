# Migration status mapping (legacy system → new system)

The legacy database stores states as integers with no names (DATA-3). Before the cutover, every coded value that
appears in **production** must be explained and signed off by someone who knows the old system (MIG-3, CARRY-4).

The mapping is **code**, in `config/legacy_mapping.php`; this page explains it. The upgrade (`app:upgrade`) uses it as a
gate: on a legacy database it refuses to run while any value is missing from the mapping, because an unknown value would
otherwise silently become the wrong state (an unknown invoice status would become a draft).

## The mapping

| Legacy column | Value | Meaning in the old system | After the cutover |
|---|---|---|---|
| `invoices.status` | `1` | Active (sent to the client) | `workflow_status = submitted` |
| `invoices.status` | `3` | Draft | `workflow_status = draft` |
| `invoices.status` | `4` | Deleted by an administrator | `workflow_status = void`, still visible as void |
| `invoices.status` | `5` | Deleted by its creator | `workflow_status = void`, still visible as void |
| `<every other table>.status` | `1` | Active (the column default) | unchanged; the new system ignores the column |
| `<every table>.archive` | `0` / `1` | Not archived / archived | unchanged; only archived hearing types are hidden (from pickers) |
| `tasks.priority` | `high`, `medium`, `low` | as named | the same (any other value is refused by the new system) |
| `tasks.task_status` | `pending`, `in_progress`, `completed`, `overdue` | as named | the same |

This replaces the earlier reconstruction of this page, which disagreed with the upgrade migration that actually runs
(`2026_08_19_090400_refactor_invoice_status_and_add_totals`): it said status `1` became "sent" and `4`/`5` were
soft-deleted. The table above states what the code does. If that is not what the firm wants, change the migration and
the mapping **before** the cutover; the questions below are where to decide.

## Questions the sign-off must answer

1. **Invoices deleted in the old system (`4`, `5`)**: keep them visible as *void* (as now), or hide them (soft-delete)?
2. **Invoice status `1`**: does it mean "sent to the client" or "submitted for approval"? Both become `submitted`.
3. **Rows with a generic `status` other than `1`** (if production has any): the old system hid or disabled them; the new
   one shows them as ordinary records. Confirm, or decide to archive or soft-delete them.
4. **Archived records** (`archive = 1`) other than hearing types show as ordinary records. Confirm.

## How to sign it off

1. Take a **read-only** connection to production (or a fresh copy) as the `source` connection: `DB_SOURCE_HOST`,
   `DB_SOURCE_DATABASE`, `DB_SOURCE_USERNAME`, `DB_SOURCE_PASSWORD`. A user with `SELECT` only is enough.
2. Run
   ```
   php artisan migration:status-report --connection=source --signoff=status-signoff.md
   ```
   It finds every generic `status`/`archive` column from the schema, counts every value (soft-deleted rows included),
   sets each against the mapping and writes the sign-off sheet. Exit code 1 means a value is not mapped.
3. For each **UNMAPPED** value: find out what it meant (old code, the people who used it), add it to
   `config/legacy_mapping.php` with its meaning and target, and if its target is not what the upgrade does, change the
   upgrade migration too. Re-run until it exits 0.
4. Answer the questions on the sheet, have it confirmed and signed, and keep it with the cutover records.
5. Rehearse the cutover with the same data (docs/ops/LEGACY_CUTOVER.md).

## Status

- [x] Mapping as code, with the upgrade refusing unmapped values (`app:upgrade` preflight)
- [x] Report and sign-off sheet (`migration:status-report`), tested on a practice legacy database with planted
      unknown values (`LegacyCutoverTest`)
- [ ] **Run against production and signed** by someone who knows the old system: _name, date_
