<?php

namespace App\Console\Commands;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantLifecycle;

class TenantsLegalHold extends TenantsLifecycleCommand
{
    protected $signature = 'tenants:legal-hold {slug} {--release : Lift the hold}';
    protected $description = 'Place or lift a legal hold that blocks permanent deletion';

    protected function apply(TenantLifecycle $l, Tenant $t): string
    {
        $l->setLegalHold($t, ! $this->option('release'));

        return "{$t->slug} legal hold ".($this->option('release') ? 'released.' : 'placed.');
    }
}
