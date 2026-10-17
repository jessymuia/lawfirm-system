# Self-hosted licensing (PLAT-2)

A signed, expiring license file that is verified **offline** (no phone-home). It feeds the same
entitlement API as SaaS plans (see ENTITLEMENTS.md); domain code never sees it.

## Vendor workflow (your side; these commands are not for customer installs)
```bash
php artisan license:keygen --out=/secure/place          # once. license-private.key NEVER ships or goes in git
php artisan license:issue --key=/secure/place/license-private.key \
    --licensee="Acme Advocates" --plan=professional --days=365 --grace=30 \
    [--users=40] [--feature=sso=1 --feature=trust_accounting=0] --out=acme.lic
```
Ship the **public** key(s) with the product: `LICENSE_PUBLIC_KEYS=<base64>[,<base64>]` (several keys = rotation:
add the new key, start signing with it, drop the old one after all licenses renew).

## Customer workflow
```bash
php artisan license:install acme.lic     # verifies, then atomically installs to storage/app/license.lic
php artisan license:status
```
or `POST /api/v1/license {"license": "<file contents>"}` (permission `manage-license`), and
`GET /api/v1/license` for the banner. Set `LICENSE_REQUIRED=true` to enforce (default false, so existing
installs, development and tests are unchanged).

## States
| State | When | Behaviour |
|---|---|---|
| `valid` | within term | full plan |
| `grace` | past expiry, within `grace_days` (default 30) | **everything still works**; `X-License-Notice` header + `notice` |
| `expired` | past grace | **read-only** |
| `missing` | none installed (and required) | read-only |
| `invalid` | unreadable / bad signature / unknown key / tampered | read-only |
| `not_yet_valid` | issued in the future (1 day tolerance) | read-only |

**Read-only means:** every API write (POST/PUT/PATCH/DELETE) is refused with 403 `{"code":"license_expired"}`.
Reads and exports (including the full-firm zip) keep working; sign-in, sign-out, password recovery and
license installation stay open. **Nothing is deleted, locked away or encrypted.** SSO and API access switch off
(they are access paths, not data). Background jobs (reminders, backups) are not blocked.

## How it is protected
- Ed25519 over the exact payload string: changing the plan, limits or expiry invalidates the signature.
- A bad file never replaces a working license (install verifies first, writes atomically).
- The clock is `max(now, latest time ever seen)` recorded in `storage/app/license.clock`, so setting the server
  clock back does not revive an expired license.
- SaaS mode ignores the license entirely.

## Known limits (honest)
- Offline licensing cannot stop a determined operator who edits the code or deletes `license.clock` (the file is
  the only memory of time). It stops casual tampering and accidental expiry handling; contractual terms do the rest.
- Licenses are not bound to a machine/install id and cannot be revoked remotely (would need an online check).
- `ext-sodium` is required (bundled with PHP >= 7.2); add it to `composer.json` `require` when next regenerating the lock.
- The vendor commands live in the same codebase; exclude `LicenseKeygen`/`LicenseIssue` from the distribution artifact (PLAT-5).
