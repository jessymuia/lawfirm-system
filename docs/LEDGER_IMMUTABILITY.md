# Append-only ledgers (DATA-10)

Financial history is protected by **database triggers** (migration
`2026_09_30_170000_enforce_append_only_ledgers.php`), so the guarantee holds for
buggy code, ad-hoc scripts and support consoles, and can be shown to an auditor.
Verified on SQLite and on MariaDB 10.11 (MySQL syntax).

| Table | Forbidden | Allowed |
|---|---|---|
| `trust_transactions` | any DELETE; editing client, case, type, amount, balance_after, description, reference, recorded_by, created_*, deleted_* | one-way void marker (`voided_at`/`voided_by`, NULL -> value); `updated_*` |
| `payments` | any hard DELETE; editing invoice, receipt number, amount, date, method, reference, received_by, notes | one-way soft void (`deleted_at`/`deleted_by`) |
| `invoices` | any hard DELETE; changing `invoice_number`; once submitted/void: amounts, client, case, currency, dates; workflow moves other than draft->submitted->void | draft edits; `amount_paid`/`payment_status` (derived from payments) |
| `invoice_items` | adding, editing or deleting items once the parent is submitted/void | draft edits |

Corrections are made by voiding and posting a new entry, never by editing history.
The API mirrors the rule (`InvoiceItemController` returns 422 for issued invoices).

## Design note

The existing "void" pattern (marking the original row) is kept and hardened
rather than replaced by separate reversing rows: all financial columns are
immutable and the marker is one-way. A stricter reversing-entry model is a
possible later step if an auditor requires it.

## Operating notes

- The triggers are part of the tenant schema and run on every tenant via `tenants:migrate`.
- Data fixes on these tables now require a deliberate, logged change
  (drop the `ledger_guard_*` triggers, fix, recreate). That friction is the point.
- The migration refuses to run on drivers other than MySQL/MariaDB/SQLite.
- Running the suite on MySQL: `DB_CONNECTION=mysql DB_HOST=... php artisan test`.
