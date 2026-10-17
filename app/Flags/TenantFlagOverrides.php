<?php

namespace App\Flags;

use App\Tenancy\TenantManager;

class TenantFlagOverrides implements FlagOverrides
{
    public function __construct(private TenantManager $tenants) {}

    public function forTenant(): array
    {
        return array_filter((array) $this->tenants->current()?->flags, 'is_bool');
    }
}
