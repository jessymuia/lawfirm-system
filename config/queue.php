<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for every one. Here you may define a default connection.
    |
    */

    // ENG-3: default changed from 'sync' to 'database' — SMS/mail jobs
    // (SendSmsJob, AssignedTask/ReassignedTask) now actually run
    // asynchronously via `php artisan queue:work` instead of blocking the
    // HTTP request that dispatched them. Requires a queue worker process
    // running in production (see ENG-6 / deployment docs) — without one,
    // jobs sit in the `jobs` table until a worker processes them.
    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            // TEN-2: null = the default connection; multi-tenant mode puts it on the control plane (TenantManager::useControlPlaneQueue)
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => 'jobs',
            'queue' => 'default',
            // ENG-20: longer than the longest job (an export may run an hour), or a running job is handed out twice.
            // Only a job whose worker died waits this long; a failing job is released at once.
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 3800),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => 'localhost',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 3800),   // ENG-20: see the database connection
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    /*
    | ENG-20: heavy work (exports, backfills, reading documents) runs on each lane's "-bulk" twin ("bulk" next to
    | "default"), and each firm runs at most `per_firm` such jobs at once, whatever it queued (App\Queue\FirmFairShare).
    | A job waiting for its firm's slot is put back for `retry_after` seconds and keeps trying for `retry_hours`.
    | `slot_seconds` must exceed the longest bulk job's timeout (an export: 3600). See docs/ops/QUEUES.md.
    */
    'bulk' => [
        'per_firm' => (int) env('QUEUE_BULK_PER_FIRM', 2),
        'retry_after' => (int) env('QUEUE_BULK_RETRY_AFTER', 30),
        'retry_hours' => (int) env('QUEUE_BULK_RETRY_HOURS', 24),
        'slot_seconds' => 3900,
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
