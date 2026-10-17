# Retention, legal hold and data-subject requests (SEC-13)

All endpoints are under `/api/v1/compliance` and need the `manage-compliance` permission. Every action is audited.

**In the web app:** Settings → Compliance has three tabs: legal holds, the retention schedule, and data-subject requests. Searching for a client or matter there also needs `list-clients` / `list-cases`.

## Legal hold
- A hold on a **client** covers all of its matters and their documents; a hold on a **matter** covers that matter's documents.
- A held record cannot be deleted: soft delete, hard delete and the bulk-delete endpoints all answer `423 legal_hold`
  (enforced in the model layer, so every code path goes through it). Bulk deletes are all-or-nothing.
- A hold also blocks retention disposal and DSAR erasure. Releasing needs a reason; hold rows are never deleted.
- Placing a hold respects ethical walls: someone walled off from a client or matter cannot place a hold on it.
- Not covered: raw SQL or the query builder, and tenant offboarding (`tenants:purge`), which is a contract-end decision made outside the firm's UI. A platform-level legal hold on the firm blocks it (docs/ops/FIRM_DELETION.md).

## Retention

The firm sets a period, and whether disposal is automatic, for each record class (`PUT /retention/{class} {retain_years, auto_purge}`). Nothing is disposed of in a class until both are set.

| Class | Records | Clock starts | On disposal |
|---|---|---|---|
| `case_documents` | Documents of closed or settled matters | the matter's end date | file and its extracted text (FUN-8) deleted; the row stays as a stub |
| `matter_emails` | Emails filed to closed or settled matters (FUN-15) | the matter's end date | original `.eml` deleted; addresses, subject and body emptied; the row stays as a stub; attachments follow `case_documents` |
| `portal_messages` | Client-portal messages of closed or settled matters (FUN-10) | the matter's end date | deleted |
| `inactive_clients` | Personal data of former clients | the end date of the client's last matter (or when it was added, if it never had one) | anonymised exactly as for an erasure request; invoices, payments and trust entries are kept |
| `financial_records` | Invoices, payments, trust ledger | — | never: append-only (DATA-10) |
| `audit_log` | Audit trail | — | never: kept, with personal data redacted on erasure |

- **Previewing:** `GET /retention/due?class=…` shows what would go (documents by default).
- **The job:** `php artisan retention:run` is a report; `--execute` disposes, class by class, and only for classes where the firm set a period **and** switched `auto_purge` on. The scheduler runs `retention:run --execute` daily at 03:00.
- **Holds:** every class leaves records under legal hold out of what is due, and checks the hold again immediately before each disposal (a hold placed in between wins).
- **Records:** each disposal writes a `retention_disposals` row (`record_type`, `record_id`, class, period) and an audit entry.
- **Minimum period:** `COMPLIANCE_MIN_RETENTION_YEARS` (default 1) only stops accidental tiny values. **The correct period depends on the firm's regulator and jurisdiction; this is not legal advice.**
- **Adding a class:** implement `App\Compliance\RecordClasses\RecordClass` and list it in `config/compliance.php` with `purgeable => true`.

## Data-subject requests (DSAR)
1. `POST /dsar` records the request and starts the clock (`COMPLIANCE_DSAR_DAYS`, default 30).
2. `POST /dsar/{id}/verify` records how identity was checked (method + note + who). Nothing happens before this.
3. **Access**: `POST /dsar/{id}/export` returns a JSON file of the client's profile, matters (without descriptions), invoices, payments, trust entries, hearing dates and a document index. Privileged advice, hearing notes/outcomes, third-party details and document contents are withheld pending lawyer review (listed in the file).
4. **Erasure**: `POST /dsar/{id}/erase {confirm:true}` anonymises the client's personal fields and redacts them inside audit history. It never deletes rows: invoices, payments, the trust ledger and matters/documents (still inside their retention period) are retained and listed in the outcome with the reason. **A legal hold overrides it**: the request becomes `blocked`, nothing is touched, and it can be retried after release.
- Requests about a client the handler is walled off from are invisible to them.

## Limits
- Erasure covers the client record and its audit rows; free-text copies of the name elsewhere (matter descriptions, notes, document contents) are not searched for. Disposal under retention handles documents, filed emails and portal messages.
- Backups taken before an erasure or disposal still contain the data until they age out (see docs/ops/BACKUP_STRATEGY.md).
- Matter records themselves (matters, hearings, tasks, time entries) are not a disposable class. They hold the firm's professional record and the financial trail points at them.
- Eject bundles include holds, policies, disposals and requests.
