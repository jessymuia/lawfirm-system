<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ENG-6: Backup destination
    |--------------------------------------------------------------------------
    |
    | Which filesystem disk `backup:run` writes to. Defaults to the local
    | 'backups' disk (see config/filesystems.php) for development, but
    | docs/ops/BACKUP_STRATEGY.md is explicit that production must not
    | keep backups on the same server as the database — set BACKUP_DISK=s3
    | (or another off-server disk) in production.
    |
    */
    'disk' => env('BACKUP_DISK', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Days to keep a daily backup before `backup:run` prunes it. This is a
    | floor, not the full retention policy in the doc (weekly/monthly tiers
    | for trust/financial records need a firm-specific compliance answer,
    | see BACKUP_STRATEGY.md) — but every backup this command writes is at
    | least this old before it's ever deleted.
    |
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Paths included in the file backup
    |--------------------------------------------------------------------------
    |
    | storage/app holds documents not yet migrated to S3 (FUN-5). Backups
    | themselves live on a *different* disk/path (see 'backups' disk in
    | config/filesystems.php, which is storage/backups, a sibling of
    | storage/app) specifically so this list never has to exclude the
    | backups it just wrote.
    |
    */
    'file_paths' => [
        storage_path('app'),
    ],
];
