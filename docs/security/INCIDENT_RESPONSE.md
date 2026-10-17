# Incident response plan

*Template. Fill in the `<…>` placeholders for your deployment, have management approve it, and run a tabletop
exercise against it before relying on it. Review it yearly and after every Sev-1. It covers SOC 2 CC7.3–CC7.5 and
ISO 27001 A.5.24–A.5.28.*

Owner: `<security owner>` · Approved by: `<name, role>` on `<date>` · Next review: `<date>`

## 1. What counts as an incident

Any event that threatens the confidentiality, integrity or availability of firm data or of the service. Examples:
a firm seeing another firm's data; a leaked credential or key; unauthorised access to a server, database, bucket or
the cloud console; malware found in an upload (`malware_detected` in the logs) that was opened before scanning was
on; ransomware; data lost or corrupted; a sustained outage; a supplier reporting a breach that touches our data.

**Anyone** (staff, contractor, customer, researcher) reports to `<security@…>` or `<on-call phone/pager>`. When in
doubt, report: a false alarm costs minutes.

## 2. Severity

| Severity | Meaning | Examples | Response |
|---|---|---|---|
| **Sev-1** | Confirmed or likely exposure of client data, or the whole service down | cross-firm leak; stolen database or backup; master key exposed; all cells down | Immediately, 24/7; incident lead within 15 min; management informed within 1 h |
| **Sev-2** | Possible exposure being investigated, or one firm / one cell down or degraded | suspicious admin sign-in; leaked API token; one cell unreachable; backup failures for > 24 h | Within 1 h in working hours, 4 h outside |
| **Sev-3** | No exposure, limited impact | blocked attack; malware refused at upload; a single failed restore drill | Next working day |

Raise the severity as soon as facts justify it; lower it only when the incident lead agrees.

## 3. Roles

| Role | Who | Does |
|---|---|---|
| Incident lead | `<name / on-call rota>` | owns the incident, decides, keeps the timeline |
| Technical lead | `<name>` | investigation, containment, recovery |
| Communications | `<name>` | customers, status page, regulators (with legal) |
| Legal / DPO | `<name or firm>` | notification duties, privilege, law enforcement |
| Management | `<name>` | approves customer and regulator notices, resources |

One person can hold several roles in a small team; the incident lead is always named.

## 4. Steps

1. **Triage (first 15 min).** Open an incident record (`<tracker>`) with time, reporter, what is known. Set a
   severity. Open a private channel `#inc-<date>-<slug>`.
2. **Preserve evidence before changing anything.** Snapshot affected volumes and databases; copy logs, the firm's
   audit trail (`/api/v1/audit`, archives), traces and error reports to `<evidence bucket, write-once>`; record
   hashes. Do not wipe or rebuild a compromised host before it is imaged.
3. **Contain.** Pick what fits:
   - a firm: suspend it (`tenants:suspend {slug}`) or put it in maintenance;
   - a user: revoke tokens and sessions, disable the account, force MFA re-enrolment;
   - a key or secret: rotate it (`secrets:rotate`, `tenants:keys --rewrap`, provider consoles), see
     [../ops/SECRETS.md](../ops/SECRETS.md);
   - an infrastructure foothold: isolate the host, revoke its cloud credentials;
   - a malicious upload: confirm it is quarantined, and find who downloaded it from the document access audit.
4. **Investigate.** Scope: which firms, which records, which period. Root cause. Keep the timeline in UTC.
5. **Eradicate and recover.** Fix the cause; restore from backup where needed
   ([../ops/FIRM_RESTORE.md](../ops/FIRM_RESTORE.md), [../ops/BACKUP_STRATEGY.md](../ops/BACKUP_STRATEGY.md));
   verify with the isolation suite and health checks before lifting a suspension.
6. **Notify** (§5).
7. **Close** with a post-incident review (§6).

## 5. Notification

| Who | When | How |
|---|---|---|
| Affected firms (they are the data controllers) | Without undue delay, and within `<24/48> h` of confirming their data is affected, as the [DPA](DPA_TEMPLATE.md) requires | email to the firm's admins and named security contact; what happened, what data, what we did, what they should do |
| Office of the Data Protection Commissioner (Kenya) | Within **72 h** of becoming aware, where we are the controller (our own staff or prospect data) or where the firm asks us to assist | ODPC breach notification form; legal signs off |
| Other regulators (e.g. EU supervisory authority under GDPR) | 72 h, where applicable | via the firm or legal |
| Everyone using the service | For outages and degradations | status page (PLAT-12), updated at least every `<60>` min |
| Law enforcement | When management and legal decide | legal |

The firm notifies its own clients and, where relevant, the Law Society; we give it what it needs. Never state a
scope we have not verified; say what is known and when the next update comes.

## 6. Post-incident review

Within 5 working days of closing a Sev-1 or Sev-2: a blameless write-up (`<template link>`) with timeline, impact,
root cause, what went well, what did not, and actions with owners and dates. Actions go into the backlog and the risk
register; the review is filed with the incident record and kept for `<7>` years.

## 7. Readiness

- Contacts (§3) and the on-call rota are current: checked `<quarterly>`.
- `critical` log entries and health-check failures page the on-call person (see GAP_ASSESSMENT §5).
- A **tabletop exercise** each year, e.g. "a firm reports seeing a matter that is not theirs" or "the backup bucket's
  credentials appear on a paste site", recorded like a real incident.

## 8. Contact sheet

| | Name | Phone | Email |
|---|---|---|---|
| Incident lead (primary) | `<…>` | `<…>` | `<…>` |
| Incident lead (backup) | `<…>` | `<…>` | `<…>` |
| Legal / DPO | `<…>` | `<…>` | `<…>` |
| Cloud provider support | `<plan, case URL>` | | |
| Payment providers (Stripe, M-Pesa) | `<…>` | | |
| ODPC | | | `<…>` |
