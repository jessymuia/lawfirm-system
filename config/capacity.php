<?php

/*
 * ENG-27: per-cell ceilings and the thresholds `capacity:report` warns at. Measured figures and how to re-measure
 * them for an instance class: docs/ops/CAPACITY.md.
 */
return [
    // Tables on one database server (each firm has ~82). information_schema scans and table-cache needs grow with it.
    'tables_per_server' => (int) env('CAPACITY_TABLES_PER_SERVER', 25000),

    // Tables a request touches in one firm (measured: ~17 on a dashboard). table_open_cache must hold this x active firms.
    'hot_tables_per_firm' => 20,

    // Share of a ceiling at which a signal turns to WARN.
    'warn_at' => 0.8,

    // Tables opened per second (since the server started) above which the table cache is churning.
    'opened_tables_per_second' => 5.0,

    // InnoDB buffer pool: reads served from memory below this ratio mean the working set no longer fits.
    'buffer_pool_hit_ratio' => 0.99,

    // Jobs waiting on the interactive lane before the queue is the bottleneck.
    'queue_backlog' => (int) env('CAPACITY_QUEUE_BACKLOG', 500),
];
