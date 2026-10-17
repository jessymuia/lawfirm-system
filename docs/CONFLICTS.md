# Conflict-of-interest checks (FUN-9)

Before a firm takes on a client or opens a matter, it must know whether it already acts for or against the same
people. This is a professional-rules requirement in most jurisdictions.

## What is searched

- **Clients:** every client (name and phone numbers), former clients included.
- **Matter parties:** every matter's parties register (`matter_parties`), which records adverse parties, co-parties,
  related parties and opposing counsel, with an optional ID or registration number. Closed and settled matters are
  included.
  - A matter's "opposing party" field is mirrored into the register automatically (dual write).
  - Matters that existed before this release are copied in by the queued `BackfillMatterParties` job, which
    `app:upgrade` and the fleet migrator dispatch.

The search reads **past matter access and ethical walls**: a check that cannot see a matter is worthless. What it
**shows** is limited instead. A hit on a matter the person may not open says that a conflicting record exists, and
nothing else: no matter number, no client, no name. The person takes it to a conflicts administrator.

## How names match

- **Normalising:** names are compared after normalising (case, accents, punctuation, `&` → and), and words that only
  describe the entity are dropped (Ltd, Limited, PLC, Co, Company, Holdings, Mr, Dr, Advocates…). So
  "Wanjiru & Co. Ltd" equals "WANJIRU AND COMPANY LIMITED".
- **Word order and spelling:** names match in any word order ("John Kamau" ~ "Kamau John"), with initials ("John K.
  Kamau"), and with one-letter spelling differences in longer words ("Mohammed"/"Mohamed"). Whole-name spelling
  similarity of 85% or more also matches.
- **Single words:** a single short word never matches on its own ("Kamau" alone does not hit every Kamau).
- **Phone numbers:** compared by their last nine digits (07… = +2547…).
- **IDs and registration numbers:** compared by letters and digits only.

## What a hit means

| Kind | When | Effect |
|---|---|---|
| `conflict` | a new client is an existing adverse party, or a new adverse party is an existing client | outcome **potential** |
| `related` | the name is a co-party, related party or opposing counsel elsewhere | outcome **potential** |
| `existing_client` | the new client is (probably) already a client | shown, outcome stays clear |
| `same_side` | already opposed on another matter | shown, outcome stays clear |

## At intake

- **The web app:** creating a client checks its name and phone; creating a matter checks the opposing party.
  - A clear result proceeds with no dialog.
  - On a possible conflict, a conflicts administrator records a decision on the spot (a reason is required). Anyone
    else is told which check number to take to one.
- **The API:** send `conflict_check_id` with `POST /clients` or `POST /cases`. A check is accepted only when:
  - it was run by the same person in the last 24 hours;
  - it has not opened another record;
  - it covers the name being opened;
  - it is clear, or a decision to proceed was recorded.
- **Firm policy** (Conflicts page, or `PUT /api/v1/conflicts/policy`):
  - **advisory (default):** intake works without a check, but a supplied check is still validated and linked;
  - **required:** intake without a cleared check is refused with `422 conflict_check_required`.

## The record

`conflict_checks` keeps every check: what was searched, what was found, the outcome, the decision with its reason and
who made it, and the record it opened. Decisions are also written to the audit trail (`conflict_proceed`,
`conflict_declined`). The Conflicts page shows the register to conflicts administrators, with a filter for checks
waiting on a decision. Both tables travel with the firm (PLAT-9).

## Permissions

- `run-conflict-checks`: run checks and see your own. The built-in advocate role has it on new installs; grant it to
  custom roles that take on clients or matters.
- `manage-conflicts`: decide on possible conflicts, read the register, set the policy.

## API

| Method | Path | Permission |
|---|---|---|
| POST | `/api/v1/conflicts/check` `{purpose: client\|matter\|search, names: [{name, role}], phone?, identifier?, exclude_case_id?}` | run-conflict-checks |
| GET | `/api/v1/conflicts/{id}` (your own, or any for administrators) | run-conflict-checks |
| PUT | `/api/v1/conflicts/{id}/decision` `{decision: proceed\|declined, note}` | manage-conflicts |
| GET | `/api/v1/conflicts?outcome=&undecided=1` | manage-conflicts |
| GET/PUT | `/api/v1/conflicts/policy` | run-conflict-checks / manage-conflicts |
| GET/POST/DELETE | `/api/v1/cases/{case}/parties[/{party}]` | list-cases / update-cases (matter access applies) |
