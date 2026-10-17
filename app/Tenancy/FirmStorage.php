<?php

namespace App\Tenancy;

/**
 * TEN-2: the disk the active firm's files live on. In multi-tenant mode that is the firm's registry row
 * (`tenants.files_disk`, set while the firm is active); on a single-firm install, S3 when a bucket is configured, else
 * the local disk. Object keys always start with the firm's prefix (TenantContext::storagePrefix()).
 */
class FirmStorage
{
    public static function disk(): string
    {
        return (string) (config('tenancy.active_files_disk') ?: self::defaultDisk());
    }

    /** Where a firm's files go when nothing more specific (its cell) says otherwise. */
    public static function defaultDisk(): string
    {
        return filled(config('filesystems.disks.s3.bucket')) ? 's3' : 'local';
    }

    /** The queue name background work goes to when nothing more specific says otherwise. */
    public static function defaultQueue(): string
    {
        return app(TenantManager::class)->defaultQueue();
    }
}
