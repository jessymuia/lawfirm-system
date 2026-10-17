# SOC 2 / ISO 27001 gap assessment

*Self-assessment of readiness for a SOC 2 Type I (then Type II) report against the Trust Services Criteria (Security,
Availability, Confidentiality) and for ISO/IEC 27001:2022 certification. It is written from the codebase and its
documentation as of 2026-10-08. An auditor's readiness review replaces it; it does not certify anything.*

**Status:** ✅ in place (evidence exists) · 🟡 partly (works, but is not formalised, not evidenced over time, or not
complete) · ❌ gap. **Owner:** Eng = engineering, Ops = whoever runs production, Mgmt = leadership.

## Summary

The **product's technical controls are strong** for a company this size: tenant isolation that is tested on every
change, deny-by-default authorisation, MFA/SSO, per-firm encryption keys with BYOK, an immutable audit trail and
financial ledgers, automated restore drills, secret and dependency scanning, and a reviewed change process. The
**gaps are mostly organisational**: there is no information security management system yet (policies, risk register,
asset inventory, supplier reviews, training, access reviews, incident response plan), no independent penetration
test, and production monitoring and alerting are not wired to people. SOC 2 Type II and ISO 27001 both need these
running and evidenced for months, so **start the organisational work now**; it, not engineering, sets the timeline.

## 1. Governance and risk (SOC 2 CC1–CC3, CC5 · ISO 27001 clauses 4–10, A.5.1–A.5.4, A.5.31–A.5.37)

| Control | Status | Evidence / gap | Action | Owner |
|---|---|---|---|---|
| Information security policy, approved and communicated | 🟡 | template: [POLICIES.md](POLICIES.md) (security, acceptable use, classification, access, secure development, operations, suppliers, incidents, people) with an acknowledgement form | Fill in, approve, have staff acknowledge it yearly; was: write the policy set (security, acceptable use, access control, cryptography, backup, incident, supplier, secure development, data retention); approve; publish to staff | Mgmt |
| ISMS scope, roles (security owner), management review | ❌ | none | Name a security owner; define scope (the SaaS platform and the company); quarterly management review with minutes | Mgmt |
| Risk assessment and treatment, Statement of Applicability | ❌ | the backlog and ADR record technical risks, not a risk register | Risk register (asset, threat, likelihood, impact, treatment, owner); SoA for ISO | Mgmt |
| Legal and regulatory requirements | 🟡 | Kenya DPA handled in product (DSAR, retention, residency statement) | Register with the ODPC as a data processor/controller as applicable; record obligations (DPA 2019, Advocates Act confidentiality, LSK guidance) | Mgmt |
| Independent review | ❌ | none | Readiness review by the chosen auditor; annual internal audit (ISO) | Mgmt |

## 2. People (CC1.4–1.5 · A.6)

| Control | Status | Evidence / gap | Action | Owner |
|---|---|---|---|---|
| Background checks, confidentiality agreements | ❌ | not documented | Checks proportionate to access; NDAs for everyone with production access | Mgmt |
| Security awareness training | ❌ | none | Onboarding + annual training, recorded | Mgmt |
| Joiner/mover/leaver process | ❌ | platform staff accounts are managed in the console (`platform:staff`) but no process | Checklist: grant on join, review on move, revoke on leave the same day (console, cloud, GitHub, registries) | Ops |

## 3. Access control (CC6.1–6.3 · A.5.15–5.18, A.8.2–8.5)

| Control | Status | Evidence / gap | Action | Owner |
|---|---|---|---|---|
| Tenant isolation | ✅ | database per firm; host-bound resolution, fail-closed; `tests/Isolation` on default and production drivers (docs/ISOLATION_TESTING.md) | Make `isolation` and `isolation-drivers` required checks on `main` | Ops |
| Least privilege inside a firm | ✅ | deny-by-default routes (`RouteAuthorizationTest`), policies per model (`PermissionMatrixTest`), matter-level access, ethical walls | — | — |
| Customer authentication | ✅ | bcrypt; password ≥10 with letters and numbers (signup and reset); TOTP MFA enforceable per firm; OIDC SSO; throttled sign-in, MFA and reset; reset tokens expire in 60 min | Optional: check passwords against breach corpora (`Password::uncompromised()`) | Eng |
| Platform staff authentication | ✅ | separate staff accounts, MFA required by default (`PLATFORM_MFA=required`), roles viewer/operator/… | — | — |
| Privileged access to firm data | ✅ | support sessions only as the firm's policy allows, time-limited, audited in the firm's trail (docs/SUPPORT_ACCESS.md) | — | — |
| Infrastructure access (cloud console, databases, servers, CI secrets) | ❌ | outside the repository | SSO + MFA on the cloud account, no shared root, named admin accounts, break-glass procedure | Ops |
| Periodic access reviews | ❌ | none | Quarterly review of staff, cloud and GitHub access, recorded | Ops |

## 4. Cryptography (CC6.1, CC6.7 · A.8.24)

| Control | Status | Evidence / gap | Action | Owner |
|---|---|---|---|---|
| Encryption in transit | 🟡 | TLS is terminated by the deployment (ingress / load balancer); the app sends **HSTS** (1 year, subdomains) on HTTPS responses ([SECURITY_HEADERS.md](SECURITY_HEADERS.md)) | Enforce TLS 1.2+ at the edge; set `SESSION_SECURE_COOKIE=true` and `TRUSTED_PROXIES` so HSTS is sent | Ops |
| Encryption at rest | 🟡 | per-firm data key (AES-256-GCM wrapping) for stored secrets and backups; BYOK via AWS KMS; live database rows rely on the server's encryption | Turn on storage encryption for every database volume, bucket and backup disk, and record it | Ops |
| Key management | ✅ | `TENANCY_MASTER_KEY` rotatable (`tenants:keys --rewrap`), keys bound to the firm, destroyed on purge (docs/ops/FIRM_ENCRYPTION.md); `APP_KEY` rotation (`secrets:rotate`) | Store master keys in a secret manager; document who can read them | Ops |
| Secrets management | ✅ | `FOO_FILE` secrets, `secrets:check`, encrypted firm credentials, gitleaks over the whole history in CI | Rotate the leaked TextSMS key (CARRY-3) | Ops |

## 5. Operations (CC7.1–7.5, CC8.1 · A.8.6–8.20, A.8.25–8.34)

| Control | Status | Evidence / gap | Action | Owner |
|---|---|---|---|---|
| Change management | ✅ | PR review; CI: tests (two modes + MySQL), isolation, PHPStan (no baseline), migration lint and rehearsal, API contract, conventional commits; release notes and upgrade guides | Require reviews and the checks on `main` (branch protection) and keep it on | Ops |
| Secure development | 🟡 | Form Requests on every input, policies, CSRF, output via JSON resources, file type sniffing, signed webhooks and payment callbacks | Written secure-coding guideline; threat model for new features handling client data | Eng |
| Vulnerability management | 🟡 | `composer audit` / `npm audit` weekly and on dependency changes (`dependency-audit.yml`); both clean on 2026-10-08 after patching Laravel and league/commonmark | Container image scanning; patch SLA (critical 7 days, high 30); OS patching of hosts | Ops |
| Penetration testing | ❌ | none yet | Independent test against [PENTEST_SCOPE.md](PENTEST_SCOPE.md); remediate; retest; yearly | Mgmt |
| Logging | ✅ | JSON logs with firm and request ids; per-firm audit trail with archives; traces and errors tagged per firm (docs/OBSERVABILITY.md) | Centralise logs off the servers with retention ≥ 1 year, access-restricted | Ops |
| Monitoring and alerting | 🟡 | health endpoints, status page, `critical` log entries for failed backups, drills, snapshots and schema drift | Route `critical` logs and health failures to an on-call person (pager/email); configure an error tracker and APM (ENG-24 is ready, needs an endpoint) | Ops |
| Security headers | 🟡 | every response: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS, `Content-Security-Policy` in report-only with violation reports logged ([SECURITY_HEADERS.md](SECURITY_HEADERS.md)) | Review the CSP reports from production, then `SECURITY_CSP_MODE=enforce` | Ops |
| Malware protection for uploads | 🟡 | every document (API, portal, direct-to-bucket) is scanned by ClamAV's clamd before it is filed; infected files refused, fail closed ([SECURITY_HEADERS.md](SECURITY_HEADERS.md)) | Run clamd in production and set `DOCUMENTS_MALWARE_SCAN=clamd`; scan files stored before then once | Ops |
| Capacity | ✅ | measured ceilings and `capacity:report` (docs/ops/CAPACITY.md), `cells:latency` | — | — |

## 6. Incidents and continuity (CC7.3–7.5, A1.2–A1.3 · A.5.24–5.30)

| Control | Status | Evidence / gap | Action | Owner |
|---|---|---|---|---|
| Incident response plan | 🟡 | template: [INCIDENT_RESPONSE.md](INCIDENT_RESPONSE.md) (severities, roles, evidence, containment commands, firm and ODPC notification within 72 h, post-incident review); status page publishes incidents (PLAT-12) | Fill in names and contacts, approve it, run a tabletop exercise | Mgmt/Ops |
| Backups | ✅ | nightly install backup; nightly per-firm encrypted snapshots; binary-log PITR | Backups on a separate account/region; immutability (object lock) | Ops |
| Restore testing | 🟡 | automated: weekly per-firm drill, monthly install drill with timings (docs/ops/BACKUP_STRATEGY.md, FIRM_RESTORE.md); first drill recorded on a dev box | Record the first production drill (CARRY-7) | Ops |
| Business continuity / disaster recovery | 🟡 | per-firm restore and relocation between cells exist; whole-region loss not exercised | DR plan with RTO/RPO; rehearse restoring a cell onto a new server/region yearly | Ops |

## 7. Suppliers (CC9.2 · A.5.19–5.23)

| Control | Status | Evidence / gap | Action | Owner |
|---|---|---|---|---|
| Supplier inventory and due diligence | ❌ | sub-processors are implied by configuration (cloud, email, SMS, payments, error tracking) | List them (security overview §11); collect their SOC 2/ISO reports and DPAs; review yearly | Mgmt |
| Customer DPA | ✅ | [DPA_TEMPLATE.md](DPA_TEMPLATE.md) | Legal review before first use | Mgmt |

## 8. Confidentiality and privacy (C1, P · A.5.33–5.34, A.8.10–8.12)

| Control | Status | Evidence / gap | Action | Owner |
|---|---|---|---|---|
| Data classification and handling | 🟡 | the warehouse schema refuses free text so privileged content cannot leave (ENG-26); traces and errors carry no request data | Adopt the classification in [POLICIES.md](POLICIES.md) §3 | Mgmt |
| Retention and disposal | ✅ | retention per record class, legal holds, verified firm deletion with crypto-shredding (docs/COMPLIANCE.md, ops/FIRM_DELETION.md) | — | — |
| Data-subject rights | ✅ | DSAR access export and erasure with identity verification | — | — |
| Data residency | 🟡 | per-firm region pinning (docs/ops/DATA_RESIDENCY.md) | Control plane per region if a customer requires it | Eng/Mgmt |

## Suggested order

1. **Now (weeks):** security owner; policy set; risk register; infrastructure access hardening (SSO+MFA, named
   accounts); branch protection with required checks; alert routing; HSTS + secure cookies; rotate the TextSMS key.
2. **Next (1–3 months):** independent penetration test and remediation; incident response plan and tabletop; supplier
   inventory; access reviews; training; upload malware scanning; CSP; first production restore drill.
3. **Then:** SOC 2 Type I readiness review → Type I report; run controls for 3–6 months → Type II; ISO 27001 stage 1/2
   audits if customers ask for it.
