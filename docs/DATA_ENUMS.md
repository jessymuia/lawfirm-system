# Enums and the matter-type list (DATA-12)

## Fixed sets of values are PHP enums

The fields below take one value from a fixed set. Each set is one PHP enum in `app/Enums`. The enum is used:

- in validation (`X::rule()` in the Form Requests);
- in domain logic (matter transitions, payment status);
- by the model guard that checks every save.

| Column | Enum |
| --- | --- |
| `cases.lifecycle_status` | `MatterStatus` (also defines the allowed transitions: `MatterStatus::next()`, served as `Cases::transitions()`) |
| `invoices.workflow_status` | `InvoiceWorkflow` |
| `invoices.payment_status` | `PaymentStatus` (`PaymentStatus::for($total, $paid)` derives it) |
| `payments.method` | `PaymentMethod` |
| `trust_transactions.type` | `TrustEntryType` |
| `tasks.priority` | `TaskPriority` |
| `tasks.task_status` | `TaskStatus` |

**Invalid values are refused on every save.**

- Models list their enum columns in `ENUM_COLUMNS` and use `App\Models\Concerns\ConstrainedToEnums`.
- A save that sets one of these columns to a value outside its enum throws `InvalidArgumentException`. This applies whoever saves: a request, an action, a job, a seeder or tinker.
- The exception names the table, the column and the allowed values, so the failure is reported where the bad value comes from.

**The database also refuses unknown priorities.** On MySQL/MariaDB and PostgreSQL the migration adds `tasks_priority_check`. It first counts invalid rows; if there are any, it stops and names them, so they must be fixed before upgrading.

**Adding a value.** Add the case to the enum. If the column has a database CHECK constraint, also write a migration that replaces the constraint. Existing rows keep working.

## Matter types are a list

Matter types used to be free text, so `Civil`, `civil` and ` CIVIL` counted as three types. They are now rows in `case_types`, and `cases.case_type_id` points to one of them.

**Saving a matter.** When a matter is saved with a `case_type` name (`App\Models\Concerns\HasCaseType`, via `App\Matters\CaseTypes::resolve`):

- The name is normalised: trimmed, with repeated spaces collapsed.
- It is matched case-insensitively to an existing type. If none matches, a new type is created.
- The matter gets that type's id and the list's spelling.

Existing API clients and imports that send `case_type` as text keep working.

**Listing types.** `GET /api/v1/case-types/options` lists the types that are not archived, for the matter form's suggestions. An archived type stays on the matters that use it, but is no longer offered.

**Responses.** `MatterResource` returns `case_type_id` alongside `case_type`.

**Existing matters are filled in by a queued job.** `App\Jobs\BackfillCaseTypes`:

- runs on the bulk lane;
- is idempotent: it only touches rows with no `case_type_id`;
- is queued by `app:upgrade` (single firm) and by the fleet migrator (every active firm).

The migration only adds the table and the column, following the expand/contract policy (docs/MIGRATION_POLICY.md). The free-text `cases.case_type` column stays for now; it can be dropped in a later release, once every reader uses the list.
