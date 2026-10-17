<?php

/** PLAT-11: public API (scoped keys) and webhooks. See docs/PUBLIC_API.md. */
return [
    // scope => the firm permission the key's user must ALSO hold (a key can never do more than its user, nor more than its scopes)
    'scopes' => [
        'clients:read' => 'list-clients',
        'cases:read' => 'list-cases',
        'hearings:read' => 'list-hearings',
    ],

    'rate_limit_per_minute' => (int) env('PUBLIC_API_RATE_LIMIT', 60),
    'max_rate_limit' => 1200,
    'max_per_page' => 100,

    'events' => ['client.created', 'client.updated', 'client.deleted', 'case.created', 'case.updated', 'case.deleted', 'hearing.created', 'hearing.updated', 'hearing.deleted', 'ping'],

    'webhooks' => [
        'timeout_seconds' => 10,
        'max_attempts' => 6,
        'backoff_minutes' => [1, 5, 30, 120, 360],   // after attempt 1..5
        'disable_after_dead' => 10,                   // consecutive dead deliveries before an endpoint is switched off
        'lock_seconds' => 120,
        'keep_days' => 30,
        'max_endpoints' => 10,
        'timestamp_tolerance_seconds' => 300,         // documented for receivers
    ],
];
