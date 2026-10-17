<?php

/** FUN-17: partner reporting. */
return [
    // A named connection the reports read from instead of the replica worked out from DB_REPLICA_HOST or the firm's cell
    // (ENG-23, docs/ops/READ_REPLICA.md). Null = that replica, or the firm's own database when there is none.
    'connection' => env('REPORTS_DB_CONNECTION'),

    // Utilisation: available hours = working days in the period x this. Working days are Monday-Friday.
    'hours_per_day' => (float) env('REPORTS_HOURS_PER_DAY', 7),

    // Longest period one request may cover (days), so a report can never scan unbounded history by accident.
    'max_days' => 731,
];
