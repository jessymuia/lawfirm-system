<?php

namespace App\Entitlements;

/** Reads config/entitlements.php. An unknown plan name yields NOTHING (fail closed), never "everything". */
class PlanCatalog
{
    public function set(?string $plan): EntitlementSet
    {
        \App\Billing\PlanStore::sync();
        $plans = (array) config('entitlements.plans');

        if ($plan === null || $plan === '' || ! isset($plans[$plan])) {
            if ($plan) {
                logger()->error("Entitlements: unknown plan [{$plan}]; granting nothing.");
            }

            return EntitlementSet::none((string) ($plan ?: 'unknown'));
        }

        return new EntitlementSet(
            $plan,
            array_map('boolval', (array) ($plans[$plan]['features'] ?? [])),
            (array) ($plans[$plan]['limits'] ?? []),
        );
    }
}
