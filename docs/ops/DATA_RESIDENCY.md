# Data residency (TEN-15)

A firm can be pinned to a region. This page states what that guarantees and what it does not. Share it with
customers only after the regions below exist in your deployment.

## How it works

- A firm provisioned with a region (`tenants.region`) is only placed in, or moved to, a cell of that region
  (`tenants:provision --region=eu`, `tenants:relocate`). If no cell of the region has room, provisioning fails rather
  than placing the firm elsewhere.
- The cell decides where everything lives (docs/ops/CELLS.md): its database server, its `files_disk` (a bucket in
  that region) and its queue lane (workers running in that region). These are recorded on the firm's row at
  provisioning, so `tenants.region` → cell → database, bucket and queue.

## What stays in the region

For a firm pinned to a region whose cell is configured with an in-region database server, bucket and workers:

- **Stays in the region:** its database (matters, clients, documents' metadata, users, audit trail), its files
  (documents, DSAR exports, audit archives) and the processing of its background jobs.

## What does not, unless you configure it

- **Control plane:** the firm's slug, name, plan, status, domains, and its users' sign-in identities (email and
  password hash). It is one database for the whole platform; put it in the strictest region you serve, or replicate
  per region.
- **The queue itself:** with the `database` queue, job payloads wait in the control-plane database until a worker in
  the region takes them; with Redis or SQS, use one queue service per region.
- **Firm-wide exports:** built on the application server's local disk, and deleted after 24 hours (ENG-21).
- **Backups:** follow your backup disk (docs/ops/BACKUP_STRATEGY.md); use an in-region backup disk per cell.
- **Logs, APM and error tracking:** may contain request metadata; choose in-region vendors or scrub.
- **Email and SMS:** pass through their providers' infrastructure.

## Statement template

> Firms on the *<region>* plan keep their case data, documents and audit trail on servers and storage in
> *<region/country>*. Account administration data (the firm's name, its users' email addresses and sign-in
> credentials) is held in *<control-plane region>*. Backups are stored in *<backup region>*.

Fill in the placeholders from your deployment, and have it reviewed before you publish it: it is a contractual
statement, not a technical default.
