<?php

namespace App\Entitlements;

/** PLAT-1: what one tenant/install may use. Immutable; unknown features/limits are denied. */
final class EntitlementSet
{
    /**
     * @param  array<string,bool>  $features
     * @param  array<string,int|null>  $limits  null = unlimited; a missing key = denied (limit 0)
     */
    public function __construct(
        public readonly string $plan,
        public readonly array $features,
        public readonly array $limits,
        public readonly bool $readOnly = false,
        public readonly ?string $notice = null,
        // Why it is read-only, as the API reports it (default license_expired; PLAT-13: trial_expired).
        public readonly ?string $code = null,
    ) {}

    /** The same plan with a banner, optionally read-only (PLAT-13: a trial about to end, or ended). */
    public function withState(bool $readOnly, ?string $notice, ?string $code = null): self
    {
        return new self($this->plan, $this->features, $this->limits, $readOnly, $notice, $code);
    }

    public static function none(string $plan = 'unknown'): self
    {
        return new self($plan, [], []);
    }

    public function has(string $feature): bool
    {
        return ($this->features[$feature] ?? false) === true;
    }

    /** @return int|null null = unlimited */
    public function limit(string $name): ?int
    {
        return array_key_exists($name, $this->limits) ? $this->limits[$name] : 0;
    }

    /** Merge per-tenant overrides on top (overrides win; only known keys are honoured). */
    public function withOverrides(array $overrides): self
    {
        $features = $this->features;
        foreach ((array) ($overrides['features'] ?? []) as $k => $v) {
            if (in_array($k, (array) config('entitlements.features'), true)) {
                $features[$k] = (bool) $v;
            }
        }
        $limits = $this->limits;
        foreach ((array) ($overrides['limits'] ?? []) as $k => $v) {
            if (in_array($k, (array) config('entitlements.limits'), true) && ($v === null || is_int($v))) {
                $limits[$k] = $v;
            }
        }

        return new self($this->plan, $features, $limits, $this->readOnly, $this->notice, $this->code);
    }

    public function toArray(): array
    {
        return ['plan' => $this->plan, 'features' => $this->features, 'limits' => $this->limits, 'read_only' => $this->readOnly, 'notice' => $this->notice, 'code' => $this->code];
    }
}
