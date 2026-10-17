<?php

namespace App\Entitlements;

use App\Observability\UsageMetrics;
use App\Tenancy\TenantContext;

/**
 * PLAT-1: THE entitlement API. Domain code uses only this:
 *
 *   Entitlements::has('trust_accounting')      bool
 *   Entitlements::require('trust_accounting')  throws EntitlementException
 *   Entitlements::limit('users')               ?int (null = unlimited)
 *   Entitlements::requireWithin('users')       throws when the limit is reached
 *
 * Memoised per tenant key, so a queue worker that switches tenants never
 * reuses another tenant's answer.
 */
class Entitlements
{
    /** @var array<string,EntitlementSet> */
    private array $memo = [];

    public function __construct(
        private EntitlementSource $source,
        private TenantContext $tenant,
        private UsageMetrics $usage,
    ) {}

    public function set(): EntitlementSet
    {
        if (isset($this->memo[$key = $this->tenant->key()])) {
            return $this->memo[$key];
        }
        $set = $this->source->resolve();
        // the fail-closed answer for "no firm resolved yet" is never remembered under a firm's key (TEN-17)
        if ($set->plan !== 'no-tenant') {
            $this->memo[$key] = $set;
        }

        return $set;
    }

    public function forget(): void
    {
        $this->memo = [];
    }

    public function has(string $feature): bool
    {
        return $this->set()->has($feature);
    }

    public function require(string $feature): void
    {
        if (! $this->has($feature)) {
            throw EntitlementException::feature($feature);
        }
    }

    public function limit(string $name): ?int
    {
        return $this->set()->limit($name);
    }

    /** True while current usage is below the limit (unlimited always true). $adding = how many more are about to be used. */
    public function within(string $name, int $adding = 1): bool
    {
        $max = $this->limit($name);

        return $max === null || ($this->usage->current($name) + $adding) <= $max;
    }

    public function requireWithin(string $name, int $adding = 1): void
    {
        if (! $this->within($name, $adding)) {
            throw EntitlementException::limit($name, $this->limit($name));
        }
    }
}
