<?php

namespace App\Flags;

/** Where per-tenant flag overrides come from. Chosen once, in the service provider, by deployment mode. */
interface FlagOverrides
{
    /** @return array<string,bool> flag => forced value for the active tenant */
    public function forTenant(): array;
}
