<?php

namespace App\Console\Commands;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantLifecycle;

class TenantsPurge extends TenantsLifecycleCommand
{
    protected $signature = 'tenants:purge {slug}
        {--without-export= : Purge although there is no final export; give the reason (recorded in the purge report)}';

    protected $description = 'Permanently delete an offboarded tenant (refused under legal hold, inside retention, or without a final export)';

    protected function apply(TenantLifecycle $l, Tenant $t): string
    {
        $report = $l->purge($t, $this->option('without-export'));
        foreach ($report as $step => $outcome) {
            $this->line(str_pad($step, 18).' '.(is_array($outcome) ? json_encode($outcome) : $outcome));
        }

        return "{$t->slug} purged.";
    }
}
