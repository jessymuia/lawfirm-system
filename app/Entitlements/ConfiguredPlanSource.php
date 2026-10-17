<?php

namespace App\Entitlements;

/** Self-hosted: the plan named in config (ENTITLEMENT_PLAN). PLAT-2 swaps this for a signed, expiring license. */
class ConfiguredPlanSource implements EntitlementSource
{
    public function __construct(private PlanCatalog $catalog) {}

    public function resolve(): EntitlementSet
    {
        return $this->catalog->set(config('entitlements.single_tenant_plan'));
    }
}
