<?php

namespace App\Entitlements;

/** PLAT-1: where entitlements come from. SaaS: the tenant's subscription. Self-host: config now, a signed license (PLAT-2) later. */
interface EntitlementSource
{
    public function resolve(): EntitlementSet;
}
