<?php

/** PLAT-12: health checks, the public status page and SLA measurement (docs/STATUS.md). */
return [
    // A "cell" is one deployment (app servers + its databases). Reported on every health response so a fleet of cells can be told apart.
    'cell' => env('CELL_ID', 'default'),

    'probe_interval_minutes' => 1,          // must match the schedule of health:probe
    'stale_after_minutes' => 3,             // no probe for this long => the scheduler (and so every measurement) is treated as down/unknown
    'keep_days' => 120,                     // probe history kept; SLA windows are capped below this
    'max_window_days' => 90,

    // What a request path cannot live without: a failing one makes /health/ready answer 503. The rest only degrade the service.
    'critical' => ['database', 'cache', 'storage'],

    'tenant_sample' => 25,                  // multi mode: tenant databases pinged per probe run (random sample)
    'queue_backlog_degraded' => 1000,       // pending jobs above which the queue is "degraded"
    'storage_disk' => env('STATUS_STORAGE_DISK'),   // null = the default disk

    // Optional, e.g. 99.9. Shown next to measured availability; the figure is a commitment only if you publish it as one.
    'sla_target' => env('STATUS_SLA_TARGET') !== null ? (float) env('STATUS_SLA_TARGET') : null,

    'labels' => [
        'database' => 'Database', 'cache' => 'Cache', 'storage' => 'File storage', 'queue' => 'Background jobs',
        'tenant_databases' => 'Firm databases', 'scheduler' => 'Scheduler',
    ],
];
