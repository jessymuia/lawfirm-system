# Security overview

*For customers, prospective customers and their security reviewers. It describes how the application protects a law
firm's data. Statements about hosting, people and certifications depend on who operates the service: fill in the
`<…>` placeholders for your deployment, and do not publish a statement that is not true of it. The
[gap assessment](GAP_ASSESSMENT.md) lists what is not yet in place.*

Operator: `<company name>` · Hosting: `<cloud provider, region(s)>` · Contact: `<security@…>` · Last reviewed: `<date>`

## 1. Each firm's data is kept apart

- **A separate database per firm.** Every firm's matters, clients, documents' metadata, time, invoices, trust ledger
  and audit trail live in a database of their own, not in shared tables filtered by a firm id. A request is bound to
  exactly one firm by the host name it arrives on; an unknown host gets nothing (it never falls back to a default firm).
- **Files** are stored under a per-firm prefix, and on S3 each firm's requests can use temporary credentials that only
  reach that prefix. Cache entries, locks, sessions, rate limits and background jobs are all namespaced per firm.
- **Proven continuously.** An isolation test suite provisions two real firms with marker data and attacks the boundary
  on every change: every API route in both directions, files, sessions, cache, queued jobs, exports, search and
  console commands. It runs on the default drivers and again on MySQL, Redis and S3 shared by both firms. A leak fails
  the build.

## 2. Who can see what inside a firm

- **Role-based permissions** on every route, **deny by default**: a route that does not declare its authorisation
  is refused, and a test fails the build if one is added.
- **Matter-level access** (optional, per firm): restricted matters are visible only to the people on them.
- **Ethical walls**: screened people cannot open, search or be shown walled matters; attempts are logged.
- **Client portal**: clients see only their own matters, documents shared with them and their invoices.

## 3. Signing in

- Passwords are stored as bcrypt hashes; minimum 10 characters with letters and numbers.
- **Two-factor authentication** (TOTP authenticator apps, single-use recovery codes stored only as keyed hashes).
  A firm can **require** it for everyone.
- **Single sign-on** with the firm's own identity provider (OpenID Connect: Microsoft Entra ID, Google, Okta…).
- Sessions: HTTP-only cookies, SameSite, CSRF protection, revocable; idle timeout `<SESSION_LIFETIME>` minutes.
  Sign-in, two-factor and password-reset endpoints are rate limited; reset links expire after 60 minutes.
- Client-portal sign-in uses one-time SMS codes (10 minutes, 5 tries, no account enumeration).

## 4. Encryption

- **In transit:** TLS for all traffic to the service (`<TLS termination: load balancer / ingress>`, TLS 1.2+).
- **Each firm has its own 256-bit data key**, stored only wrapped by the platform master key or, for firms that bring
  their own key, by **their own AWS KMS key (BYOK)**. Stored secrets (SSO, integration and calendar credentials,
  webhook secrets) and the firm's **backups** are encrypted with it. Deleting a firm destroys its key, which makes its
  backups unreadable (crypto-shredding).
- **At rest:** databases and object storage are encrypted by the hosting provider (`<e.g. RDS / EBS / S3 SSE-KMS>`);
  a BYOK firm's files on S3 use its KMS key.

## 5. Records that cannot be quietly changed

- **Audit trail** of changes to matters, clients, documents, money and permissions, per firm; archived monthly to
  verified files and kept for the plan's retention, never deleted while a legal hold applies.
- **Trust and payment ledgers are append-only**, enforced by database triggers: entries are reversed, never edited.

## 6. Backups and recovery

- Every firm is snapshotted nightly (database and files together, encrypted with the firm's key). On MySQL with the
  binary log, a firm can be restored **to a point in time**, beside the live copy, without affecting other firms.
- **Restore drills are automated:** one firm a week (`tenants:restore-drill`) and the install's own backup monthly
  (`backup:drill`), each checked (tables, rows, files, the application reading them) and timed.
- Recovery objectives: RPO `<e.g. 5 minutes with binary log / 24 h without>`, RTO `<…>`.

## 7. Retention, legal hold and data-subject requests

Firms set retention per record class (closed matters' documents, filed emails, portal messages, inactive clients) and
can place legal holds that block deletion everywhere, including deletion of the firm itself. Access, export and
erasure requests are handled in the product, with identity verification and an audit record. Financial records and
the audit trail are never disposed of by retention.

## 8. People at the operator

- **No standing access to firm data.** Support staff can see a firm only through a support session the firm's policy
  allows (and, if the firm requires, approves), limited in time, with every action recorded in the firm's own audit
  trail and visible to it.
- Platform staff sign in separately from firms, with two-factor authentication required.
- `<background checks, confidentiality agreements, security training: state what applies>`

## 9. Building and running the software

- Every change goes through review and automated checks: tests in two tenancy modes and on MySQL, cross-tenant
  isolation, static analysis with no exceptions, migration safety rules, API contract checks, secret scanning of the
  full git history, and dependency vulnerability audits (PHP and JavaScript, weekly and on every dependency change).
- Releases are versioned with upgrade notes; database changes follow an expand/contract policy so a release can be
  rolled back.
- Errors and traces are tagged per firm for diagnosis and never contain request bodies, headers or field values.
- **Browser hardening:** every response carries HSTS, a Content Security Policy, anti-framing, no-sniff,
  referrer and permissions policies.
- **Uploaded documents are scanned for malware** (ClamAV) before they are stored; infected files are refused
  (`<on / not yet enabled in this deployment>`).
- Security incidents are handled under a written plan with firm notification without undue delay
  (`<link to or summary of your approved incident response plan>`).

## 10. Data location

A firm can be pinned to a region: its database, files and background processing stay in that region's cell. What
stays outside the region (account administration data, email and SMS delivery) is stated in the data-residency
statement (`<link>`).

## 11. Sub-processors

| Sub-processor | Purpose | Data | Location |
|---|---|---|---|
| `<cloud provider>` | hosting, databases, storage, backups | all firm data | `<region>` |
| `<email provider>` | sign-in and notification email | names, email addresses, message content | `<…>` |
| `<SMS provider, e.g. TextSMS>` | SMS codes and reminders | phone numbers, message content | `<…>` |
| `<Stripe / Safaricom>` | subscription payments (firms that pay this way) | billing contact, amounts | `<…>` |
| `<error tracking / APM, if used>` | diagnostics | error messages, request metadata | `<…>` |

## 12. Assurance and reporting

- Penetration test: `<date, firm, summary of results or "planned for …">` (scope: [PENTEST_SCOPE.md](PENTEST_SCOPE.md)).
- Certifications: `<none yet / SOC 2 Type I in progress / ISO 27001>`; readiness: [GAP_ASSESSMENT.md](GAP_ASSESSMENT.md).
- Data processing agreement: [DPA_TEMPLATE.md](DPA_TEMPLATE.md).
- **Report a vulnerability** to `<security@…>`; we acknowledge within `<2 business days>`.
- Service status and incidents: `<status page URL>`.
