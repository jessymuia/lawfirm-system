# Firm file isolation in object storage (TEN-12)

Every file a firm stores lives under `tenants/<firm>/` on its disk: documents (`tenants/<firm>/cases/docs/…`), DSAR
exports, firm-wide exports. Each document's SHA-256 is recorded when it is written and checked whenever it is copied
(imports, relocation, migration manifests).

Isolation has two layers:

1. **Application:** every key is built from the active firm (`TenantContext::storagePrefix()`), and imports always
   re-home files under the importing firm.
2. **Storage (S3):** while a firm is active, the app does not hold credentials that can reach any other firm's files.
   With `TENANCY_STORAGE_ROLE_ARN` set, each S3 disk listed in `TENANCY_STORAGE_DISKS` uses short-lived credentials
   from STS `AssumeRole`, with the session tagged `tenant=<firm>`. The role's policy allows only
   `tenants/${aws:PrincipalTag/tenant}/*`. A bug that builds the wrong key gets `AccessDenied`, not another firm's data.

The platform context (no firm active: health probes, backups, purging an offboarded firm) keeps the app's own
credentials.

## Setting it up

1. **Move older documents first.** Files stored before per-firm prefixes (for example `cases/docs/x.pdf`) are outside
   every firm's prefix, so the role cannot reach them.
   ```
   php artisan documents:relocate --dry-run      # what would move
   php artisan documents:relocate                # copy, verify by SHA-256, switch the row, delete the old object
   php artisan documents:relocate --keep-old     # same, but keep the old objects until you have checked
   ```
   It is safe to re-run. A file whose bytes do not match its recorded checksum, a copy that does not verify, or a
   different file already at the target is reported and left exactly as it was. The command exits non-zero in those
   cases. Documents with no recorded checksum (older than checksum-on-write) get one recorded from the verified copy.
   In multi mode it runs firm by firm (`--tenant=<slug>` for one).
2. **Create the policies.** `php artisan storage:iam-policy --bucket=<bucket> --platform-principal=<app role ARN>`
   prints three documents:
   - `tenant-role`: the permissions of the firm storage role.
   - `trust`: who may assume it; only the app, and only with exactly one `tenant` session tag.
   - `platform`: what the app's own role needs (assume and tag the firm role, plus bucket-wide access for fleet
     operations).

   Create the role from the first two documents and attach the third to the app's role.
3. **Turn it on.** Set `TENANCY_STORAGE_ROLE_ARN=<role ARN>` (and optionally `TENANCY_STORAGE_SESSION_SECONDS`, default
   3600), with the S3 disk in `TENANCY_STORAGE_DISKS`. Then run `php artisan config:cache`.

To check it, read another firm's key with a firm's credentials (for example from `php artisan tinker` inside a firm):
the request must fail with `AccessDenied`.

## Limits

- Local and other non-S3 disks have no IAM; there isolation is the application layer only. Production multi-tenant
  deployments should keep firm files on S3 (or an S3-compatible store with STS and session tags).
- The policy is checked in `tests/Feature/StorageIsolationTest.php` by evaluating its statements, and the credential
  switch is exercised against a mocked STS. Neither has been run against real AWS in this repository; verify on staging
  with the cross-firm read above.
