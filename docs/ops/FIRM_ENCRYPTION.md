# Per-firm encryption and bring-your-own-key (TEN-13)

This applies to multi-tenant (SaaS) deployments. A self-hosted install has one firm, and its `APP_KEY` protects it as before.

## What each firm's key protects

Each firm has its own random 256-bit **data key**. The data key is never stored as it is: it is kept **wrapped** (encrypted) in the control plane's `tenant_keys` table. One of two keys wraps it:

- the platform's **master key** (`TENANCY_MASTER_KEY`); or
- the firm's **own AWS KMS key**. This is BYOK, also called a customer-managed key (CMK).

The data key encrypts the following.

| What | How |
| --- | --- |
| The firm's stored secrets: SSO client secret, people's two-factor secrets, integration credentials (M-Pesa, Stripe, calendar apps), webhook signing secrets, calendar tokens | `App\Tenancy\Keys\FirmCrypt` (AES-256-CBC + HMAC, Laravel's encrypter) |
| Its backups (`backup:run --tenant=`: database dump and files archive) | `App\Tenancy\Keys\BackupCipher` (XChaCha20-Poly1305 secretstream; files end in `.enc`) |
| Its files on S3, BYOK firms only | S3 server-side encryption with the firm's own KMS key (`SSE-KMS`). This applies to every object written, browser direct uploads included. |

Unwrapped keys live only in the memory of the process that needs them: never in the cache, the database or logs.

**What the data key does not cover.** The rows of the firm's live database are protected by the database server's encryption at rest (one key per server). They are deleted when the firm is purged; backups and copies are covered by the purge (below).

**Keys are bound to their firm.** A wrapped key is tied to the firm's slug: AES-GCM associated data for the master key, the KMS encryption context `tenant=<slug>` for BYOK. A wrapped key copied onto another firm's row does not open there.

## Turning it on

1. Generate a master key, store it as a secret, and set it as `TENANCY_MASTER_KEY` (or `TENANCY_MASTER_KEY_FILE`). It is 32 bytes with a `base64:` prefix, like `APP_KEY`:

       php artisan tenants:keys --generate-master-key

2. From then on, every new firm gets a data key when it is provisioned.
3. Give existing firms one. This also re-encrypts each firm's stored secrets under its new key:

       php artisan tenants:keys --all --enable

4. Check:

       php artisan tenants:keys --all          # acme: active, platform, fingerprint 3f2a…, usable
       php artisan secrets:check               # per firm: everything "current"

Values written before a firm had its key stay readable through the app key until they are re-encrypted. `--enable` re-encrypts them. So does `secrets:rotate --execute`, which is safe to re-run.

**Losing the master key loses every firm's platform-wrapped data key**, so keep it in your secret store with a backup, like `APP_KEY`. A firm whose data key cannot be unwrapped cannot use its stored secrets or decrypt its backups. The rest of the app keeps working.

## Rotating the master key

1. Put the new key in `TENANCY_MASTER_KEY` and the old one in `TENANCY_PREVIOUS_MASTER_KEYS` (comma-separated).
2. Run `php artisan tenants:keys --all --rewrap`. Only the wrapping changes: the data keys, and everything they encrypted, stay as they are. The fingerprint confirms the key is the same one.
3. Remove the old key.

## Bring your own key (BYOK)

The firm creates a symmetric KMS key in its own AWS account. It lets the platform's role use the key for `kms:Encrypt` and `kms:Decrypt`, either in the key policy or with a grant, with the condition `kms:EncryptionContext:tenant = <firm slug>`. Then:

    php artisan tenants:keys acme --customer-key=arn:aws:kms:eu-west-1:123456789012:key/…

**What this does:**

- The firm's data key is rewrapped by the firm's key. The new wrapping is proven to open before it replaces the old one.
- From then on, its files on S3 are written with `SSE-KMS` under the firm's key.
- Nothing has to be re-encrypted, and the firm sees no downtime.

`--platform-key` goes back to the master key.

**The firm stays in control.** If it disables its key, revokes the platform's access, or schedules the key for deletion, the platform can no longer unwrap the firm's data key. From that point:

- its stored secrets cannot be read (anything that needs them fails, rather than falling back to another key);
- its backups cannot be decrypted;
- its S3 files cannot be read.

`tenants:keys acme` shows `NOT USABLE` with KMS's reason. Re-enabling the key restores everything.

Every key change is recorded in the platform audit log (`tenant.key.*`).

## Key destruction is how a firm's data is deleted everywhere

Purging a firm (`tenants:purge`, docs/ops/FIRM_DELETION.md) runs `EncryptionKeyPurgeStep`. The step empties the wrapped key and records `destroyed_at`, keeping the row as evidence. The purge report shows:

    encryption_key: destroyed: data key 3f2a…; its backups and encrypted secrets can no longer be read

After that, the firm's backups cannot be decrypted by anyone, wherever copies of them are: other regions, cold storage, an operator's laptop. This is crypto-shredding, and it does not depend on finding and deleting each copy.

For a BYOK firm, the report adds that its own KMS key is the firm's to disable or delete. Once the firm deletes that key, AWS guarantees that nothing wrapped with it can be opened, the platform included.

## Restoring a firm's backup

    php artisan tenants:decrypt-backup acme database-2026-10-08_020000.sql.gz.enc
    # → database-2026-10-08_020000.sql.gz, then restore as usual (docs/ops/BACKUP_STRATEGY.md)

This works only while the firm's key exists and is usable. A truncated or altered backup is refused, not restored in part.
