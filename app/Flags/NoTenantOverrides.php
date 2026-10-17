<?php

namespace App\Flags;

/** Single-tenant installs have no registry; deployment-level FLAGS apply instead. */
class NoTenantOverrides implements FlagOverrides
{
    public function forTenant(): array
    {
        return [];
    }
}
