# Deleting a firm (TEN-9)

Deletion is one sequence, and every step is recorded on the firm's control-plane row (`purge_report`). The report
holds no firm data.

| Step | When | What happens |
|---|---|---|
| 1. Offboard | `tenants:offboard <slug> [--retention-days=N]` or console `POST /tenants/{slug}/offboard` | Service stops at once. `purge_after` = now + retention (`TENANCY_RETENTION_DAYS`, default 30). A final export is queued. |
| 2. Final export | queued job `ExportOffboardedTenant` (or `tenants:final-export <slug>` to redo it) | The complete PLAT-9 bundle (every row and document, checksummed) is written to `storage/app/portability/offboarded/` and its SHA-256 recorded. Hand it to the firm with console `GET /tenants/{slug}/final-export` (admin only, audited; the response carries `X-Content-SHA256`). A failed export is recorded (`final_export_error`) and blocks the purge. |
| 3. Retention window | until `purge_after` | Nothing is deleted. Legal hold (`tenants:legal-hold`) blocks deletion for as long as it is set, even if the firm itself asked to be deleted. |
| 4. Purge | daily `tenants:purge-due` (05:00), or `tenants:purge <slug>` | Refused under legal hold, inside the window, or without a final export that still matches its SHA-256. Then, in order: database dropped → `tenants/<slug>/` deleted on every disk in `TENANCY_STORAGE_DISKS` (checked empty afterwards) → extra purge steps → sign-in memberships removed, and identities with no other firm deleted → domains removed → the platform's copy of the final export deleted. |

`tenants:purge-due` never purges a firm without a final export. Someone decides: either redo the export
(`tenants:final-export`), or purge with a recorded reason (`tenants:purge <slug> --without-export="<why>"`, console
`without_export`).

## Encryption keys and warehouse rows

The purge report always has an `encryption_key` and a `warehouse` line:

- **Encryption key (TEN-13, docs/ops/FIRM_ENCRYPTION.md).** `App\Tenancy\Keys\EncryptionKeyPurgeStep` destroys the
  firm's data key and reports its fingerprint. Everything encrypted with that key becomes unreadable, wherever a copy
  is: its backups and its stored secrets. This is crypto-shredding. A firm created before per-firm keys were enabled,
  and never given one, reports `none: …`.
- **Warehouse (ENG-26).** `App\Warehouse\WarehousePurgeStep` expunges the firm's rows from the analytics warehouse,
  or reports `none: …` when no warehouse is configured.

Steps are classes implementing `App\Tenancy\PurgeStep`, listed in `config/tenancy.php` → `purge_steps`. They run after
the database and files are gone. One that throws stops the purge and leaves the firm `offboarded` so it can be re-run;
the steps before it are idempotent.

## Backups

Backups taken before the purge still exist until they age out (docs/ops/BACKUP_STRATEGY.md). What happens to them depends on whether the firm had a data key:

- **With a data key:** its `backup:run --tenant=` backups are encrypted with that key. Destroying the key at purge makes them unreadable at once, even before they expire.
- **Without a data key:** its backups, and any whole-server backups, remain readable until they expire. For those, deletion from backups happens by expiry, and the backup retention period is what you tell the firm.
