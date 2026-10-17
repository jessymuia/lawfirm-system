# Migration Rollback Plan

> **Note:** this file was deleted by commit `830bffa` ("Delete docs/ops
> directory") and reconstructed here from §6.3 Phase 4 and §6.4 of
> `docs/SYSTEM_REVIEW_AND_MIGRATION_PLAN.md`, since no `.git` history was
> available to `git revert` the original. If the original differs, prefer
> the original — in particular this reconstruction cannot recover any
> rollback detail that lived only in the deleted file and nowhere else in
> the repo.

## Principle

The old system is **never deleted or made unreachable** at cutover. It is
frozen (read-only) and kept available for an agreed retention window
specifically so cutover can be reversed if the new system turns out to
have a problem only found in production.

## Rollback trigger

Any of:
- A migration-verification check (§3.3 / Phase 3) that should have caught
  a problem before cutover, but didn't, and it's discovered post-cutover
  (e.g. a reconciliation mismatch found by users, not by the automated
  suite).
- A severity-1 production defect in the new system with no fast forward
  fix.
- Data loss or corruption discovered in the new system that the old
  system's frozen copy is needed to recover from.

## Procedure

1. **Stop writes to the new system.** Put it in maintenance mode
   (`php artisan down`) immediately — every write accepted after this
   point is data that has to be manually reconciled or lost if rollback
   proceeds.
2. **Switch URL/DNS back** to the old system.
3. **Unfreeze the old system** (it was read-only since the original
   cutover — see §6.3 Phase 4 of the migration plan). Confirm it's fully
   functional before directing traffic to it.
4. **Communicate to users:** re-authentication may be required again
   (Sanctum tokens don't survive a system switch either direction — see
   §6.1 item 3 of the migration plan); anything entered only in the new
   system during the interval between cutover and rollback needs a plan
   (see below).
5. **Reconcile the gap.** Any record created/edited in the new system
   between cutover and rollback is not in the old system. Export it
   (documents, cases, invoices, whatever was touched) and manually
   re-apply it to the old system before allowing new writes there again.
   This is inherently manual — there is no automated tool for it in this
   repo.
6. **Root-cause the failure** in the new system before attempting cutover
   again. Do not re-attempt cutover until the specific failure that
   triggered rollback has a regression test (unit/feature or a new
   `tests/Migration` check, per §3.3) proving it won't recur.

## What limits how far back rollback can go

The retention window the old system is kept read-only for (§6.3 Phase 4)
is the hard limit — once that window closes and the old system's
infrastructure is decommissioned, this plan no longer applies and any
issue found in the new system has to be fixed forward, not rolled back.
**The specific retention window length is a business decision this repo
does not encode** — agree it and record it here before cutover.

## Related

- `docs/ops/MIGRATION_STATUS_MAPPING.md` — must be signed off before the
  transform this rollback plan protects against even runs.
- §6.4 Risk register in `docs/SYSTEM_REVIEW_AND_MIGRATION_PLAN.md`.
