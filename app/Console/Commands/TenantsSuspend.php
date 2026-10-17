<?php

namespace App\Console\Commands;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantLifecycle;

class TenantsSuspend extends TenantsLifecycleCommand
{
    protected $signature = 'tenants:suspend {slug}';
    protected $description = 'Block a tenant without destroying data';

    protected function apply(TenantLifecycle $l, Tenant $t): string
    {
        $l->suspend($t);

        return "{$t->slug} suspended.";
    }
}
