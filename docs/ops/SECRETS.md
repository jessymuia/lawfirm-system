# Secrets management (SEC-15)

## Principle
Secrets live in a managed store and are mounted into the process; they are not written into a `.env` on the box.
The application supports this with the standard file convention: `FOO_FILE=/run/secrets/foo` fills `FOO` at boot, before
configuration is read (`app/Support/SecretFiles.php`). Docker/Swarm secrets, Kubernetes secret volumes and Vault / AWS / GCP
agents all produce such files. Rules: absolute path only, file must be readable and non-empty (otherwise boot **fails**, it never
runs without the secret), `FOO` and `FOO_FILE` together is an error, one trailing newline is stripped.

Only an allow-list of variables can be filled this way: `APP_KEY, APP_PREVIOUS_KEYS, DB_PASSWORD, DB_REPLICA_PASSWORD, CONTROL_DB_PASSWORD,
DB_SOURCE_PASSWORD, REDIS_PASSWORD, MEILISEARCH_KEY, MAIL_PASSWORD, MAILGUN_SECRET, POSTMARK_TOKEN, AWS_SECRET_ACCESS_KEY, TEXTSMS_API_KEY,
TEXTSMS_PARTNER_ID, LICENSE_PUBLIC_KEYS`. Add more per deployment with `SECRET_FILE_ALLOW=NAME1,NAME2`.
(An allow-list because variables like `PIP_CONFIG_FILE` also end in `_FILE` and must never be read.)

What the repository verifies is the loader; **it cannot verify your secret store** (Vault, Kubernetes, cloud KMS) — that is a deployment task.

## Inventory
| Secret | Where it lives | Rotation |
|---|---|---|
| `APP_KEY` | secret store -> `APP_KEY_FILE` | procedure below (`secrets:rotate`) |
| DB / control-DB / Redis / mail passwords | secret store | change at the provider, update the store, restart; no data re-encryption |
| TextSMS key (deployment default) | `TEXTSMS_API_KEY_FILE` | issue new key at the provider, update the store, restart, revoke the old one |
| A firm's own integration credentials | `integration_credentials`, encrypted at rest, write-only API (`PUT /api/v1/security/integrations/{provider}`) | the firm sets a new value; covered by APP_KEY rotation |
| MFA secrets, SSO client secrets, platform-staff MFA | encrypted columns | covered by APP_KEY rotation |
| License public keys | `LICENSE_PUBLIC_KEYS` (public; not secret) | see docs/LICENSING.md |

The encrypted columns are registered in `config/secrets.php`. A test fails if code starts encrypting something that is not
registered there, so rotation cannot silently miss data.

## APP_KEY rotation runbook
1. Generate a new key: `php artisan key:generate --show`.
2. Deploy with `APP_KEY=<new>` and `APP_PREVIOUS_KEYS=<old>` (comma-separated for several). Everything keeps working: values written
   under the old key are still readable.
3. `php artisan secrets:check` — shows, per firm, how many values still need a previous key (and fails on unreadable ones).
4. `php artisan secrets:rotate` (report) then `php artisan secrets:rotate --execute` — re-encrypts under the new key
   (compare-and-set; safe to re-run; unreadable values are reported and never touched). Use `--tenant=<slug>` to do one firm.
5. `php artisan secrets:check --strict` must pass. Only then remove `APP_PREVIOUS_KEYS`.
6. Keep the old key in the store for the length of your backup retention: **database backups contain values encrypted under the key
   that was current when they were taken**.
Sessions and `encrypt()`ed cookies signed with the old key are invalidated when APP_PREVIOUS_KEYS is removed; users sign in again.
`sso_states` (10-minute login state) is not rotated; it expires by itself.

The end-to-end rehearsal (old key -> new key + previous -> rotate -> drop old key -> nothing breaks; old key alone is rejected) is
automated in `tests/Feature/SecretsTest.php::test_app_key_rotation_end_to_end`. Run it, and `secrets:check` on staging, before doing
it for real. A rehearsal on a production-like copy of the data is still your job.

## Not done here
- CARRY-3: the git history is scanned (below); rotating the leaked TextSMS key at the provider happens outside the repository; the runbook and the `secrets:fingerprint` proof are in `docs/ops/SMS_KEY_ROTATION.md`.
- Per-tenant encryption keys (one key per firm): all firms share the deployment `APP_KEY`.
- Only TextSMS has a per-firm override today; add providers in `IntegrationCredentials::PROVIDERS`.

## Git history scan (CARRY-3)

`.github/workflows/secrets-scan.yml` runs gitleaks (8.21.2, checksum pinned) over **every commit** on each pull request
and push to `main`, with `.gitleaks.toml`; a finding fails the check. Run it locally with
`gitleaks git --config .gitleaks.toml --log-opts="--all" --redact .`.

| Date | Scope | Result |
|---|---|---|
| 2026-10-08 | all 100 commits (79 non-merge) of this repository, gitleaks 8.21.2 default rules, plus a manual search for TextSMS/`apikey`/`partner_id` values and common key formats (`sk_live`, `AKIA`, `ghp_`, `xox*`, `AIza`) | **no secrets found** |

This history starts at a single squashed commit (`0fec205`, 2026-10-02). The TextSMS key that was once in the code
predates it, so it is **not** in this repository's history, but it was in earlier copies of the code (old clones,
forks, the previous repository, deployment servers). Scrubbing this history would change nothing. **The key must still
be rotated at TextSMS and the old one revoked**: until then, anyone with an old copy can send SMS as the firm.
