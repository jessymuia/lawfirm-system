# Information security policy set

*Template. These are the short policies a SOC 2 / ISO 27001 auditor expects to see approved, communicated and
followed. Replace every `<…>`, delete what does not apply, and have management approve the set. A policy is only
evidence once people have acknowledged it and its controls actually run, so keep it to what you will really do.
Related: [GAP_ASSESSMENT.md](GAP_ASSESSMENT.md) (what is missing), [INCIDENT_RESPONSE.md](INCIDENT_RESPONSE.md).*

Owner: `<security owner>` · Approved by: `<name, role>` on `<date>` · Review: yearly, next `<date>`

Each person with access to production or to customer data acknowledges this document when they join and every year
(`<HR system / signed form>`). Exceptions need the security owner's written approval, with an expiry date, kept in the
risk register.

## 1. Information security

- `<company>` protects the confidentiality, integrity and availability of the data law firms entrust to it. Client
  data held for firms is treated as **privileged and confidential**, the highest class (§3).
- The **security owner** (`<name>`) runs the programme: risk register, policies, access reviews, incidents, suppliers,
  and reports to management `<quarterly>`.
- Risks are assessed yearly and when the system changes significantly; each has an owner, a treatment and a date.

## 2. Acceptable use

- Company devices and accounts are for company work. Customer data stays in company systems: never in personal email,
  personal cloud storage, chat tools not approved here (`<approved list>`), or AI tools that keep or train on input.
- Devices used for work have full-disk encryption, a screen lock of ≤ `<5>` min, supported and patched OS, and
  `<endpoint protection>`. Report a lost device to `<security@…>` the same day.
- No sharing of accounts or passwords; use the company password manager (`<…>`).

## 3. Data classification and handling

| Class | Examples | Handling |
|---|---|---|
| **Restricted** | firms' matters, clients, documents, trust ledgers; encryption keys; backups | only in production systems; access per §4; never in tickets, chat, logs or test data; exports encrypted, with the firm's written instruction |
| **Confidential** | staff and prospect personal data, contracts, invoices, security reports | company systems only, need-to-know |
| **Internal** | runbooks, source code | company systems; source code in the company GitHub organisation |
| **Public** | marketing site, status page, security overview | approved before publication |

Support staff see a firm's data only through support sessions the firm's policy allows, time-limited and recorded in
the firm's audit trail (docs/SUPPORT_ACCESS.md).

## 4. Access control

- Least privilege; access granted on request with the approver recorded, removed on the last working day.
- **MFA** everywhere it is offered, and required on: cloud console, GitHub, the platform admin console, email, the
  password manager. No shared or root accounts for daily use; break-glass credentials sealed and their use reviewed.
- **Quarterly access review** of cloud, GitHub, platform staff accounts and databases, recorded with the reviewer and
  what was removed.

## 5. Secure development and change

- Every change goes through a pull request, reviewed by someone other than the author, with the required CI checks
  green (tests, isolation suite, static analysis, migration lint, dependency audit, secret scanning). Branch protection
  on `main` enforces this; admins do not bypass it except in an incident, recorded.
- New features that handle client data get a short threat model in the PR (what can leak, to whom, what stops it).
- Secrets never go into the repository; they live in `<secret manager>` (docs/ops/SECRETS.md).
- Vulnerabilities: critical fixed within **7 days**, high within **30**, others planned. Dependency audits run weekly.

## 6. Operations

- Production changes are deployed by the pipeline, not by hand; emergency changes are recorded and reviewed after.
- Logs are kept centrally for ≥ `<1 year>`, access-restricted; `critical` entries page the on-call person.
- Backups: nightly, encrypted, in a separate account/region; restores are tested (weekly per-firm drill, monthly
  install drill) and the results kept.
- Hosts and images are patched `<monthly>`, or within the vulnerability windows in §5.

## 7. Suppliers

- Every supplier that processes customer data is listed (security overview §11), with its security report (SOC 2 /
  ISO 27001) and a data processing agreement, reviewed yearly. New ones are approved by the security owner before use,
  and firms are told of new sub-processors as the DPA requires.

## 8. Incidents and continuity

- Incidents follow [INCIDENT_RESPONSE.md](INCIDENT_RESPONSE.md). Everyone reports suspected incidents at once.
- Business continuity: RTO `<…>`, RPO `<…>`; a disaster-recovery exercise (restore a cell in a new region) yearly.

## 9. People

- Background checks for anyone with production access, where the law allows (`<…>`).
- Confidentiality terms in every employment and contractor agreement.
- Security awareness training on joining and yearly (phishing, handling client data, reporting); completion recorded.

## 10. Enforcement

Breaches of these policies may lead to disciplinary action. Questions go to `<security@…>`.

## Acknowledgement

> I have read the information security policy set version `<…>` and will follow it.
>
> Name: ________ · Role: ________ · Date: ________ · Signature: ________
