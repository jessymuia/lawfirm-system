# Rotating the TextSMS key (CARRY-3)

The key was removed from the code, but a key that has ever been in a repository or a shared copy of it stays valid until the
provider is told otherwise. Only someone with the provider account can finish this; the steps below make it quick and prove it worked.
(The repository history itself was scanned and holds no secrets: see the audit table in `docs/ops/SECRETS.md`.)

## Steps
1. **Note the old key's fingerprint** on a running deployment, before changing anything:
   `php artisan secrets:fingerprint TEXTSMS_API_KEY` -> e.g. `TEXTSMS_API_KEY fingerprint: 3f9a1c0b7d42` (the key itself is never printed).
2. **Issue a new key** in the TextSMS account (and a new partner id only if the provider says to). Keep the old one active for now.
3. **Put the new key in the secret store** (`TEXTSMS_API_KEY_FILE`, see `docs/ops/SECRETS.md`) and restart the app and the queue workers.
4. **Prove the process is using the new key:**
   `php artisan secrets:fingerprint TEXTSMS_API_KEY --retired=3f9a1c0b7d42` -> must say it is no longer the retired key (exit code 0).
   Send one test message from a firm that uses the deployment default.
5. **Firms with their own TextSMS credentials** are unaffected (they hold their own key); nothing to do.
6. **Revoke the old key** at the provider. Send one more test message to confirm nothing still depends on it.
7. **Record it**: date, who, the two fingerprints (not the keys) in the secrets audit table.

## If a firm's SMS stops after step 6
Something still held the old key (a second deployment, a cron host, a developer's `.env`). Find it by fingerprint on each host
(`secrets:fingerprint`), update it, and re-send. Do not re-activate the old key; issue another new one.

## Repeat
Rotate again whenever a person who had access to the key leaves, or on the schedule in `docs/security/POLICIES.md`.
