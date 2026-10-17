# Data processing agreement (template)

> **Template, not legal advice.** Written to fit Kenya's Data Protection Act, 2019 and its General Regulations (2021),
> and to be compatible with the EU/UK GDPR (Art. 28) for firms with clients there. Have it reviewed by counsel before
> use. Text in `<…>` is to be completed; *italic notes* are guidance to delete before signing.

**Between** `<Firm name>`, `<address>` (the **Controller**, "the Firm")
**and** `<Operator company>`, `<address>` (the **Processor**, "the Provider"),
together the Parties. This agreement forms part of the subscription agreement dated `<date>` (the **Agreement**).

## 1. Definitions
Terms such as *personal data*, *processing*, *data subject*, *data controller*, *data processor* and *personal data
breach* have the meaning given in the Data Protection Act, 2019 (the **Act**) and, where it applies, the GDPR.
**Firm Data** means personal data the Provider processes on the Firm's behalf in providing the service.

## 2. Subject matter, duration, nature and purpose
- **Subject matter:** hosting and operating the practice-management service described in the Agreement.
- **Duration:** the term of the Agreement plus the return/deletion period in clause 11.
- **Nature and purpose:** storage, retrieval, organisation, transmission (email, SMS, client portal), backup and
  deletion of Firm Data, solely to provide the service and support requested by the Firm.
- **Categories of data subjects:** the Firm's personnel; its clients and their representatives; opposing and third
  parties named in matters; witnesses; client-portal users.
- **Categories of personal data:** identification and contact data; matter details; documents and their contents;
  communications; billing and payment records; time records; access logs and audit trail.
- **Sensitive personal data** (Act, s. 2) may be contained in matter documents (e.g. health, family, criminal records,
  biometric data). *The Provider does not inspect document contents except as instructed or required to provide
  search (indexing within the Firm's own environment).*

## 3. The Firm's instructions
The Provider processes Firm Data only on the Firm's documented instructions (this agreement, the Agreement and the
Firm's use and configuration of the service), including for transfers outside Kenya, unless required by law, in which
case it informs the Firm first unless the law forbids it. The Provider tells the Firm promptly if it considers an
instruction to infringe the Act.

## 4. Confidentiality
Everyone at the Provider authorised to process Firm Data is bound by confidentiality. Support staff can reach Firm
Data only through a support session the Firm's policy allows, time-limited and recorded in the Firm's audit trail.
The Provider recognises that Firm Data may be subject to **advocate–client privilege** and does nothing to waive it.

## 5. Security
The Provider maintains the technical and organisational measures in **Annex 2** and the
[security overview](SECURITY_OVERVIEW.md), appropriate to the risk (Act, s. 41). It may improve them but not reduce
their overall level.

## 6. Sub-processors
The Firm authorises the sub-processors in **Annex 3**. The Provider gives `<30>` days' notice of a new or replaced
sub-processor (`<by email / on a published page>`); the Firm may object on reasonable data-protection grounds, and if
the Parties cannot resolve it, terminate the affected service without penalty. The Provider imposes obligations on
each sub-processor no less protective than these and remains liable for them.

## 7. Transfers outside Kenya
Firm Data is hosted in `<region>`. A transfer outside Kenya happens only as listed in Annex 3, with the safeguards
required by the Act (s. 48–50) and the Regulations (appropriate safeguards, adequacy, or consent where applicable)
and, for GDPR data, standard contractual clauses. *Firms pinned to a region: see the data-residency statement.*

## 8. Helping the Firm
Taking into account the nature of the processing, the Provider helps the Firm:
- respond to data-subject requests (access, rectification, erasure, objection, portability): the service provides
  access export, erasure with legal-hold checks, and a full firm export; the Provider forwards any request it
  receives directly, without answering it;
- with data protection impact assessments and consultations with the Office of the Data Protection Commissioner;
- meet its security and breach-notification obligations.

## 9. Personal data breaches
The Provider notifies the Firm **without undue delay and within `<48>` hours** of becoming aware of a personal data
breach affecting Firm Data, with what is known (nature, categories and approximate numbers, likely consequences,
measures taken), updating it as more is learned, so the Firm can notify the Data Commissioner within 72 hours and
data subjects where required (Act, s. 43). The Provider does not notify the Firm's clients or regulators itself
unless the Firm asks or the law requires it.

## 10. Audits
The Provider makes available the information needed to demonstrate compliance: this agreement, the security overview,
`<latest penetration test summary / certifications>`, and answers to reasonable security questionnaires (once a year,
or after a breach). If that is not enough, the Firm may audit, with `<30>` days' notice, at its cost, during business
hours, under confidentiality, without access to other customers' data.

## 11. Return and deletion
During the term the Firm can export all of its data at any time (full firm export and portable bundle). On
termination the Provider keeps Firm Data available for export for `<30>` days, then deletes the Firm's database,
files, search indexes and its encryption key; backups encrypted with that key become unreadable at once and expire
from storage within `<35>` days. A **legal hold** set by the Firm postpones deletion until the Firm lifts it. The
Provider confirms deletion in writing on request.

## 12. Liability and precedence
Liability is as set out in the Agreement. If this agreement conflicts with the Agreement on data protection, this
agreement prevails.

---

**Annex 1 — Processing details:** clause 2 above, completed with `<any firm-specific additions>`.

**Annex 2 — Technical and organisational measures** (summary; detail in the security overview):
1. Separate database per firm; per-firm file prefixes and credentials; continuous cross-tenant isolation testing.
2. Role-based, deny-by-default authorisation; optional matter-level access; ethical walls.
3. Two-factor authentication (enforceable per firm); single sign-on; rate-limited sign-in; revocable sessions.
4. TLS in transit; provider encryption at rest; a per-firm data key (optionally the Firm's own KMS key) for stored
   secrets and backups; key destruction on deletion.
5. Audit trail per firm; append-only financial ledgers.
6. Nightly per-firm encrypted snapshots, point-in-time restore, automated weekly and monthly restore drills.
7. Retention per record class, legal holds, data-subject request tooling.
8. Support access only by firm-permitted, time-limited, audited sessions; MFA for platform staff.
9. Reviewed changes, automated security checks (isolation, static analysis, secret and dependency scanning).
10. `<organisational measures: policies, training, background checks, incident response, supplier review>`

**Annex 3 — Sub-processors:** the table in section 11 of the security overview, as completed for this deployment.

Signed for the Firm: `<name, title, date>`  Signed for the Provider: `<name, title, date>`
