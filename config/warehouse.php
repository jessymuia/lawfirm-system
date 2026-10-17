<?php

/*
 * ENG-26: the analytics warehouse, the ONLY place data of several firms may sit together (docs/ops/WAREHOUSE.md).
 * It receives daily aggregates per firm and nothing else: counts and sums, never names, text, documents or anything
 * a client told the firm. App\Warehouse\WarehouseSchema enforces that on every row.
 */
return [
    'enabled' => (bool) env('WAREHOUSE_ENABLED', false),

    // files: NDJSON (gzip) per table / day / firm on `disk`, for a loader (BigQuery, Snowflake, Redshift, DuckDB...).
    // database: rows into `connection` (its own server, never a firm database or the control plane).
    'sinks' => array_filter(explode(',', (string) env('WAREHOUSE_SINKS', 'files'))),
    'disk' => env('WAREHOUSE_DISK', 'local'),
    'path' => env('WAREHOUSE_PATH', 'warehouse'),
    'connection' => env('WAREHOUSE_DB_CONNECTION'),
    'table_prefix' => 'wh_',

    // Time entries carry no currency; their value is counted in this one.
    'time_currency' => env('WAREHOUSE_TIME_CURRENCY', 'KES'),
];
