# Trust three-way reconciliation and retainers (FUN-12)

Client money held in trust is what regulators inspect. A firm must show, at every statement date, that three figures
agree to the cent:

1. **The bank:** the statement balance, plus deposits in transit, minus outstanding disbursements, plus or minus bank
   items not yet in the ledger (charges, interest).
2. **The ledger:** every trust entry up to that date (deposits minus disbursements).
3. **The clients:** the sum of each client's own trust balance at that date.

Figures 2 and 3 are computed separately. A difference between them means money is attributed to the wrong client.

## How it works

- **Start:** Trust Accounting → Reconciliations → enter the statement date and closing balance. Every ledger entry up
  to that date that the bank has not yet cleared is listed as outstanding.
- **Tick:** mark the entries that appear on the statement. What stays unticked is in transit (deposits) or outstanding
  (disbursements). Add bank items not yet in the ledger, each with a reason.
- **Sign off:** this freezes the reconciliation. Its figures, the outstanding list and a per-client balance snapshot
  are stored and never recomputed, so later clearings or voids cannot change what was signed. An unbalanced
  reconciliation can be signed off only with a written explanation, and is then marked as having exceptions. Sign-off
  is audited.
- **Report:** the auditor's CSV includes the three figures, the outstanding entries and every client balance.

### Rules

- Reconciliations go forward in time: a new one must end after the last one signed off, and only one draft exists at
  a time. A draft can be discarded; its tick marks go with it.
- Voids: an entry voided after the statement date still counts at that date, so past reconciliations keep adding up.
- Immutability: the trust ledger itself is append-only (DATA-10, enforced by database triggers). Reconciliation never
  updates it. Which entries the bank has cleared lives in `trust_transaction_clearances`.
- Precision: all arithmetic is done in integer cents.

## Retainers (evergreen) and replenishment

- **Policy:** a client can have a retainer policy: a minimum balance and a target to top up to.
- **Due list:** Trust Accounting → Retainers lists clients below their minimum, with the amount needed to reach the
  target and when they were last asked.
- **Request:** "Request by SMS" sends the client a replenishment request (clients are reached by phone) and logs it in
  `trust_replenishment_requests`.

## Permissions and plan

- **Plan:** trust accounting is a plan feature (`trust_accounting`), as before.
- **`reconcile-trust-accounts`:** reconciliations, retainer policies, sending replenishment requests.
- **`list-trust-transactions`:** the list of retainers below their minimum.

## API

| Method | Path |
|---|---|
| GET/POST | `/api/v1/trust/reconciliations` `{period_end, bank_balance, statement_reference?}` |
| GET/DELETE | `/api/v1/trust/reconciliations/{id}` (delete: drafts only) |
| PUT | `/api/v1/trust/reconciliations/{id}/cleared` `{transaction_ids, cleared}` |
| POST/DELETE | `/api/v1/trust/reconciliations/{id}/items[/{item}]` `{amount, description}` |
| POST | `/api/v1/trust/reconciliations/{id}/sign-off` `{exception_note?}` |
| GET | `/api/v1/trust/reconciliations/{id}/export` (CSV) |
| GET/PUT | `/api/v1/trust/retainers` `{client_id, minimum_balance, target_balance}`; DELETE `/trust/retainers/{client}` |
| GET | `/api/v1/trust/replenishment`; POST `/trust/replenishment/{client}/request` |

## Not included

One trust bank account per firm: every ledger entry belongs to it. Firms with several trust accounts reconcile them
together until ledger entries carry an account.
