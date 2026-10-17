# Analytics warehouse (ENG-26)

**The warehouse is the only place where data of several firms may sit together.** Firm databases are separate by
design (TEN-1): no query ever joins across firms, and nothing else in the platform copies one firm's data next to
another's. Product and business questions about the whole platform are answered here, and only here.

## What it holds: aggregates, never content

The warehouse receives one day per firm, already reduced to counts and sums:

| Table | One row per | Columns |
|---|---|---|
| `dim_firm` | firm, day | firm key, platform id, status, plan, cell, region, date created. From the platform registry, not the firm's data. |
| `firm_daily` | firm, day | users (total, active), matters (total, open, opened), clients (total, added), hearings held, documents (added, bytes added, bytes total), tasks due, hours (logged, billable) |
| `firm_money_daily` | firm, day, currency | invoices issued and amount, payments and amount, value of time logged. Currencies are never mixed. |
| `firm_events_daily` | firm, day, event | how often each audited action happened (`login`, `document_uploaded`, ...): which features are used, never by whom or on what |

**Never exported:** names, addresses, phone numbers, descriptions, notes, titles, file names, document text, the
contents of messages, and ids of clients, matters, people or documents. Privileged client content cannot reach the
warehouse:

- `App\Warehouse\FirmMetrics` reduces everything to counts and sums inside the firm's database.
- Every row is then checked against `App\Warehouse\WarehouseSchema` before anything is written. The schema has no
  free-text column type: dates, firm keys, code identifiers (`^[A-Za-z0-9_.:-]{1,64}$`), ISO currencies, integers
  and sums. An unknown column or a value that looks like text stops the export.
- Adding a column means changing `WarehouseSchema`, which is a reviewed change. Run it past whoever owns client
  confidentiality.
- `WarehouseTest` plants client names, privileged descriptions, notes and file names, and fails if any reaches the
  warehouse.

## Running it

```
WAREHOUSE_ENABLED=true
WAREHOUSE_SINKS=files            # files, database, or files,database
WAREHOUSE_DISK=s3-warehouse      # a disk in config/filesystems.php: its own bucket
WAREHOUSE_DB_CONNECTION=warehouse  # for the database sink: a connection to the warehouse's own server
WAREHOUSE_TIME_CURRENCY=KES      # time entries have no currency: their value is counted in this one
```

- **Schedule:** `warehouse:export` runs nightly at 02:20 for the day before, for every firm.
- **Backfill:** `php artisan warehouse:export --date=2026-10-31 --days=31`. A re-run replaces those days; it never
  duplicates.
- **files sink:** writes `warehouse/<table>/dt=<date>/tenant=<key>.ndjson.gz`. Point BigQuery or Snowflake external
  tables, Redshift `COPY`, or DuckDB at the prefix.
- **database sink:** creates the `wh_*` tables on first use and replaces each (date, firm) on every run. It refuses
  to write to the application's own database or the control plane.

## Treat it as a high-sensitivity system

Aggregates of many firms are still confidential business information of each firm:

- **Access:** a separate bucket or database, separate credentials and role-based access, limited to the people who
  analyse the platform. Never grant firms access to it.
- **Protection:** encryption at rest, and access logging on the bucket or database.
- **Retention:** keep it only as long as the business question needs.

When a firm is deleted (TEN-9), `WarehousePurgeStep` expunges its rows and files, and the deletion report says so.
External copies loaded from the files are the loader's responsibility. Re-load the affected partitions, or delete by
`tenant_key`.
