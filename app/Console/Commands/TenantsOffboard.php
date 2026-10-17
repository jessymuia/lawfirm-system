<?php

namespace App\Console\Commands;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantLifecycle;

class TenantsOffboard extends TenantsLifecycleCommand
{
    protected $signature = 'tenants:offboard {slug} {--retention-days= : Days before permanent deletion}';
    protected $description = 'Stop service and schedule permanent deletion after the retention window';

    protected function apply(TenantLifecycle $l, Tenant $t): string
    {
        $l->offboard($t, $this->option('retention-days') !== null ? (int) $this->option('retention-days') : null);

        return "{$t->slug} offboarded; purge allowed after ".$t->fresh()->purge_after.'.';
    }
}
