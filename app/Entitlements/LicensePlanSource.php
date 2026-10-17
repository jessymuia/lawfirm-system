<?php

namespace App\Entitlements;

use App\Licensing\LicenseManager;
use App\Licensing\LicenseStatus;

/**
 * PLAT-2: self-hosted entitlements from the signed license.
 *  valid / grace  -> the licensed plan (+ the license's own feature/limit overrides); grace carries a warning
 *  anything else  -> the `unlicensed` plan, READ-ONLY: data stays viewable and exportable, nothing is written or deleted
 * When LICENSE_REQUIRED is false this behaves like ConfiguredPlanSource.
 */
class LicensePlanSource implements EntitlementSource
{
    public function __construct(private LicenseManager $licenses, private PlanCatalog $catalog, private ConfiguredPlanSource $configured)
    {
    }

    public function resolve(): EntitlementSet
    {
        if (! config('entitlements.license.required')) {
            return $this->configured->resolve();
        }

        $status = $this->licenses->status();
        $payload = $status->payload ?? [];

        // An expired (but genuine) license still names the plan whose data the customer owns.
        $base = $status->payload ? $this->catalog->set($payload['plan']) : EntitlementSet::none();
        if (! $status->payload || $base->plan === 'unknown') {
            $base = $this->catalog->set('unlicensed');
        }

        $set = $base->withOverrides($status->payload ? $payload : []);

        if ($status->allowsWrites()) {
            return new EntitlementSet($set->plan, $set->features, $set->limits, false, $status->notice());
        }

        // Degraded: what they paid for stays readable, but never writable, and access paths (sso, api) switch off.
        $readable = $this->catalog->set('unlicensed')->features;
        $features = $status->payload
            ? array_intersect_key($set->features, $readable)
            : $readable;

        return new EntitlementSet($status->payload ? $set->plan : 'unlicensed', $features, [], true, $status->notice());
    }
}
