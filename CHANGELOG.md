# Changelog

All notable changes to this product are listed here, newest first. The format follows
[Keep a Changelog](https://keepachangelog.com/) and the versions follow [Semantic Versioning](https://semver.org/).
`php artisan release:check` validates this file; `scripts/release.sh <version>` promotes `[Unreleased]` to a release.
Upgrade notes for each release are in `docs/upgrade/`.

> This file was rebuilt from `docs/upgrade/1.0.0.md` for the exported copy of the project (the repository export leaves
> `CHANGELOG.md` and `.github/` out on purpose, see `.gitattributes`). If your repository already has its own
> `CHANGELOG.md`, keep that one.

## [Unreleased]

### Added
- **Single sign-on with SAML 2.0** next to OpenID Connect (Settings, Security: choose the protocol, copy the entity ID and reply URL to your identity provider, paste its sign-in URL and signing certificate). Responses are verified against your certificate only, bound to the request we sent, and cannot be replayed.
- **User provisioning with SCIM 2.0**: your identity provider can add, rename and deactivate people automatically, with a token you create under Settings, Security. It cannot change email addresses or deactivate your last security administrator.
- Operator console: **Run system updates** on each firm that is behind, and **Update N firms** on the overview to bring them all up to date.
- `php artisan secrets:fingerprint` proves a secret was replaced without printing it; runbook for rotating the SMS key (`docs/ops/SMS_KEY_ROTATION.md`); `scripts/publish-release.sh` publishes the image, chart and bundle by hand when hosted CI is unavailable.
- Firm screens for features that were API-only: **Access** tab on every matter (who can see it), **Settings, Ethical walls**,
  **Settings, API access** (keys and webhooks) and **Settings, Support access** (approve, limit and review platform-staff sessions).
- Floating **Install app** button on every page, with iPhone and iPad instructions.
- "Start a free trial" link on the sign-in page when self-service signup is on; the Docker stack turns signup on at `join.localhost`.
- Operator console: account settings (name, password), QR code for two-factor, plan table, invoice generation by date.
- The web app hides menu items the firm's plan does not include (API access, Trust Accounting).
- **Settings, Currencies and tax** has a Document numbering section: prefix for invoices, receipts and trust ledger, optional year.
- Trust transactions get a ledger number (`TRT-000001`); invoices, payments and documents get a public id (ULID) for use in links and the API.
- Personal access tokens can expire (`expires_at`).
- Demo data now includes trust deposits and disbursements.

### Fixed
- Static analysis (PHPStan) is clean again for SCIM, SAML, `secrets:fingerprint`, the pay page and the plans console; `phpunit.xml` pins `TENANCY_MODE=single` so `php artisan test` no longer fails when run inside the multi-tenant Docker container.
- Front-end checks: the confirmation dialog's options are typed (the type-check failed on the compliance page), and the new screens use design tokens instead of hex colours.
- `php artisan api:openapi --check` no longer fails on Windows line endings (CRLF); the generated API files are pinned to LF in `.gitattributes`.
- SCIM refuses to create a person whose email address is already used by an account it cannot see (platform support), instead of failing.

### Changed
- The sign-in callbacks for single sign-on start their own session (the browser arrives from the identity provider, which the API did not treat as the app's own pages).
- The sign-in page's extras (free-trial link, operator sign-in) come from one place in the tenancy layer, so domain code no longer reads the deployment mode.
- Every platform-console and payment-link endpoint validates through a Form Request.
- The phone menu starts closed, opens from the hamburger and closes when a page is opened.
- Paid invoices show a green "Paid" status everywhere in the firm app and on the PDF.
- The loading screen is removed from the firm app. The Getting started card has a new design.

## [1.0.0] - 2026-10-12

### Added
- Security headers: HSTS, a report-only Content Security Policy and a Permissions-Policy (SEC-14).
- Optional malware scanning of uploads with ClamAV (SEC-14).
- Automatic card payment and asking firms for payment through Stripe and M-Pesa (PLAT-4).
- Traces and error tracking, off until configured (ENG-24).
- Per-firm restore, point-in-time recovery and nightly snapshots (TEN-14).
- Retention classes for emails, portal messages and inactive clients (SEC-13).
- Currencies, exchange rates and tax rates, with Kenyan VAT in the system catalog (DATA-14, TEN-11).
- Firm branding: name, colour, logo and letterhead (UI-13).
- Matter types as a catalog and stricter enums (DATA-12).
- Per-firm encryption keys (TEN-13).
- Firm switcher for people who belong to several firms (UI-16).
- Accessibility pass and automated axe checks (UI-15).
- Monthly restore drill (CARRY-7).

### Changed
- Password resets need at least 10 characters with letters and numbers (SEC-14).
- Laravel moves to 12.69.3.
- Cache flush on Redis is per firm (TEN-10).
- Upgrading from legacy databases needs a signed-off status mapping (CARRY-4).

## [1.0.0-rc.1]

### Added
- First release candidate of the multi-firm platform: database per firm, control plane, platform console, entitlements,
  licensing, support access, trial signup, client portal, online payments, trust reconciliation, document assembly,
  email filing, calendar sync, partner reports and the installable web app.
