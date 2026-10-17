<?php

namespace App\Console\Commands;

use App\Jobs\ExportOffboardedTenant;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantLifecycle;

/** TEN-9: (re)make an offboarded firm's final export now, e.g. after the queued one failed. */
class TenantsFinalExport extends TenantsLifecycleCommand
{
    protected $signature = 'tenants:final-export {slug}';

    protected $description = "Make an offboarded firm's final export (kept until it is purged)";

    protected function apply(TenantLifecycle $l, Tenant $t): string
    {
        if ($t->status !== 'offboarded') {
            throw new \RuntimeException("Only an offboarded firm has a final export ([{$t->slug}] is {$t->status}).");
        }
        ExportOffboardedTenant::dispatchSync($t->slug);
        $t->refresh();

        return "{$t->slug}: final export {$t->final_export_path} (sha256 {$t->final_export_sha256}).";
    }
}
