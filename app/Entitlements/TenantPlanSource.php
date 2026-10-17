<?php

namespace App\Entitlements;

use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;

/** SaaS: the active tenant's plan from the control-plane registry, plus its per-tenant overrides. */
class TenantPlanSource implements EntitlementSource
{
    public function __construct(private TenantManager $tenants, private PlanCatalog $catalog) {}

    public function resolve(): EntitlementSet
    {
        $tenant = $this->tenants->current();
        if (! $tenant) {
            return EntitlementSet::none('no-tenant');   // fail closed
        }

        $plan = $tenant->plan ?: config('entitlements.default_plan');
        $set = $this->catalog->set($plan)->withOverrides((array) $tenant->entitlements);

        return self::withTrial($set, $tenant->trial_ends_at);
    }

    /**
     * PLAT-13: a trial firm sees when its trial ends; once it has, it can read and export everything but change nothing
     * until someone chooses a plan (choosing one clears trial_ends_at).
     */
    public static function withTrial(EntitlementSet $set, ?\DateTimeInterface $endsAt, ?\DateTimeInterface $now = null): EntitlementSet
    {
        if (! $endsAt) {
            return $set;
        }
        $now = CarbonImmutable::instance($now ?? now());
        $ends = CarbonImmutable::instance($endsAt);
        $date = $ends->format('j M Y');

        if ($now->gte($ends)) {
            return $set->withState(true, "Your free trial ended on {$date}. Everything is still here and you can read and export it; choose a plan to keep working.", 'trial_expired');
        }
        $daysLeft = (int) ceil($now->diffInSeconds($ends) / 86400);
        if ($daysLeft <= (int) config('billing.trial.warn_days', 3)) {
            return $set->withState(false, "Your free trial ends on {$date} ({$daysLeft} day".($daysLeft === 1 ? '' : 's').' left). Choose a plan to keep working without interruption.');
        }

        return $set;
    }
}
