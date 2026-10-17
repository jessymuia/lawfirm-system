# Load and capacity tests (ENG-27)

- **`k6/staff-day.js`:** HTTP load, meaning people working in one firm or spread over several. See its header for the
  options. Run it against staging, never production.
- **`php artisan capacity:soak`:** fills a database server with scratch firm databases and measures how it degrades
  (provisioning, catalogue queries, request latency, table-cache churn).
- **`php artisan capacity:report`:** the live scaling signals of each cell, compared with the ceilings.

The measured ceilings, and how to re-measure them for your instance class, are in docs/ops/CAPACITY.md.
