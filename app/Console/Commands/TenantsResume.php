<?php

namespace App\Console\Commands;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantLifecycle;

class TenantsResume extends TenantsLifecycleCommand
{
    protected $signature = 'tenants:resume {slug}';

    protected $description = 'Re-enable a suspended tenant';

    protected function apply(TenantLifecycle $l, Tenant $t): string
    {
        $l->resume($t);

        return "{$t->slug} resumed.";
    }
}
