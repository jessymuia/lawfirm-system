<?php

namespace App\SupportAccess;

use App\Tenancy\Models\Tenant;

/**
 * PLAT-8: what a firm allows the platform team to do. Safe defaults: allowed, but only with the firm's approval, read-only,
 * and for at most an hour. Stored on the control-plane tenant row; edited only through the firm's own admin endpoint.
 */
class SupportPolicy
{
    public const DEFAULTS = ['enabled' => true, 'require_approval' => true, 'allow_write' => false, 'max_minutes' => 60];

    public const MAX_MINUTES_CEILING = 480;

    public static function for(Tenant $tenant): array
    {
        $stored = $tenant->support_policy ? (json_decode($tenant->support_policy, true) ?: []) : [];

        return array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    public static function update(Tenant $tenant, array $changes): array
    {
        $policy = array_merge(self::for($tenant), array_intersect_key($changes, self::DEFAULTS));
        $policy['max_minutes'] = max(5, min(self::MAX_MINUTES_CEILING, (int) $policy['max_minutes']));
        foreach (['enabled', 'require_approval', 'allow_write'] as $k) {
            $policy[$k] = (bool) $policy[$k];
        }
        $tenant->forceFill(['support_policy' => json_encode($policy)])->save();

        return $policy;
    }
}
