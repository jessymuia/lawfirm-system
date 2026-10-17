# Eject and import (PLAT-9)

A firm's data is never trapped. One supported, versioned capability moves a firm **out of** the SaaS (to a self-hosted
install), **into** the SaaS (from a self-hosted install), **between** tenants (relocation) and **back from a bundle** (disaster
recovery). It is the same code path in every case.

```
php artisan portability:export [--tenant=<slug>] [--output=<dir|file.zip>] [--zip] [--no-files]
php artisan portability:verify <bundle dir|.zip>
php artisan portability:import <bundle> [--tenant=<slug>] [--replace-bootstrap] [--files-disk=<disk>] [--dry-run]
```

`--tenant` is required in a multi-tenant deployment and refused on a single install. Platform staff with the `admin` role can also
`POST /api/platform/tenants/{slug}/eject` (reason required, queued); the bundle path and sha256 appear in the platform audit log
(`tenant.eject.finished`).

## What is in a bundle

```
manifest.json     format name + version, app version, source (driver, tenant key), the migrations applied,
                  per-table {file, rows, columns, sha256, row_checksum}, document summary, what was excluded
manifest.sha256   seal over manifest.json
data/<table>.ndjson   every row, one JSON object per line, in id order (soft-deleted rows included)
files/index.ndjson    one line per document {document_id, disk, path, size, sha256, bundle_path | missing}
files/<document id>   the document bytes
```

Included: users (with password hashes, so people keep their passwords), roles/permissions, catalog, clients, matters, hearings,
tasks, billing, trust ledger, numbering sequences, documents **and their files**, matter access grants, ethical walls and their attempt
log, support-access sessions and log, and the **audit trail**.
Excluded (config/portability.php lists each with a reason): login tokens, queue tables, password-reset tokens, legacy bookkeeping.
Never exported: platform-staff support principals and their roles, and `users.global_identity_id` (a control-plane id).

Every table in the database must be classified in `config/portability.php` as `include` or `exclude`; an unclassified table makes the
export refuse and the test suite fail, so a new feature cannot silently be left out of (or leaked into) a firm's data.

**Treat a bundle like the database itself**: it contains password hashes and privileged client material. It is not encrypted;
transfer and store it accordingly.

## Import rules

- Restores into an **empty** install and keeps every id, so relationships, audit references and document rows stay true.
  It never merges. (The older `migration:import` remaps ids for the one-off legacy migration.)
- A target fresh from `migrate`/`app:install`/provisioning already holds users, roles and catalog rows (the `bootstrap` tables);
  pass `--replace-bootstrap` to replace them with the bundle's. Anything else non-empty is refused.
- The target must already include every migration the source had (run `app:upgrade` first). A newer target is fine.
- **Verified, all-or-nothing:** the bundle is verified first (seal, per-table and per-document checksums, format version); then
  everything loads in one transaction and each table is re-read and compared with the bundle; document bytes are read back from the
  destination. Any failure rolls the database back and removes the files it wrote.
- Documents are re-homed to the target's own object-store prefix (`tenants/<source>/…`, and files from before per-firm prefixes such as
  `cases/docs/…`, become `tenants/<this tenant>/…`), so
  tenant storage isolation (TEN-12) holds. `--files-disk` stores documents on a different disk than they came from.
- In multi mode each person is then linked to a sign-in identity (created from their existing password hash if they have none).
  If they already belong to another firm they keep that existing login.

## The format is an API

`App\Portability\Format::VERSION` (`MAJOR.MINOR.PATCH`) versions the bundle, separately from the application.

| Change | Version |
|---|---|
| Incompatible (rename/remove a manifest key, table or column; change a file layout or hashing) | **MAJOR**: a breaking API change, listed under "Changed/Breaking" in the CHANGELOG |
| Backward-compatible addition (new optional manifest key, new table) | MINOR |

An importer reads the same major and any minor up to its own, and refuses the rest with a clear message.
`tests/fixtures/export-v1.0.0/` is a frozen bundle: if a change makes `PortabilityTest` fail on it, you changed the format. Don't edit
the fixture; decide deliberately: bump MAJOR, keep reading v1, add a new fixture
(`REGENERATE_EXPORT_FIXTURE=1 php artisan test --filter=regenerate` on MariaDB/MySQL).

## Limits

- Row verification normalises numbers (12.5 = 12.50) so it works across engines, which also means a text value `1.10` vs `1.1`
  would compare equal. Transport integrity is still exact (file sha256).
- Firm self-service (a "download my data" button) is not built; today ejecting is an operator action (CLI or platform console).
- An export is one consistent snapshot on MySQL (single transaction), but writes made to document *files* during the export are not frozen.
- Importing a SQLite-sourced bundle into MySQL can fail verification where SQLite stored out-of-type values (e.g. a datetime in a date column); that is the verification doing its job.
- Very large firms: rows stream in chunks, but the zip step and per-table verification are single-process; not load-tested.
