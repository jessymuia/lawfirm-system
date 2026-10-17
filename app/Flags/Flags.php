<?php

namespace App\Flags;

use App\Entitlements\Entitlements;
use App\Tenancy\TenantContext;

/**
 * PLAT-3: THE flag API.   Flags::enabled('name')   Flags::explain('name')   Flags::all()
 * An unregistered flag is always false (and the test suite refuses to let one be used).
 * Memoised per tenant key, so a worker that switches tenants never reuses another tenant's answer.
 */
class Flags
{
    /** @var array<string,array<string,array{value:bool,source:string}>> */
    private array $memo = [];

    public function __construct(private FlagOverrides $overrides, private Entitlements $entitlements, private TenantContext $tenant) {}

    public function forget(): void
    {
        $this->memo = [];
    }

    public static function registry(): array
    {
        return (array) config('flags.flags');
    }

    public function enabled(string $flag): bool
    {
        return $this->explain($flag)['value'];
    }

    /** @return array{value:bool,source:string} source = killed|tenant|deployment|edition|rollout|default|unregistered */
    public function explain(string $flag): array
    {
        $key = $this->tenant->key();

        return $this->memo[$key][$flag] ??= $this->resolve($flag, $key);
    }

    /** @return array<string,array{value:bool,source:string}> */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::registry()) as $name) {
            $out[$name] = $this->explain($name);
        }

        return $out;
    }

    private function resolve(string $flag, string $tenantKey): array
    {
        $def = self::registry()[$flag] ?? null;
        if ($def === null) {
            return ['value' => false, 'source' => 'unregistered'];
        }

        if (in_array($flag, self::list((string) config('flags.killed')), true)) {
            return ['value' => false, 'source' => 'killed'];
        }

        $tenant = $this->overrides->forTenant();
        if (array_key_exists($flag, $tenant)) {
            return ['value' => (bool) $tenant[$flag], 'source' => 'tenant'];
        }

        $deployment = self::parse((string) config('flags.overrides'));
        if (array_key_exists($flag, $deployment)) {
            return ['value' => $deployment[$flag], 'source' => 'deployment'];
        }

        $plan = $this->entitlements->set()->plan;
        if (isset($def['editions']) && array_key_exists($plan, $def['editions'])) {
            return ['value' => (bool) $def['editions'][$plan], 'source' => 'edition'];
        }

        if (isset($def['rollout'])) {
            $pct = max(0, min(100, (int) $def['rollout']));

            return ['value' => (crc32($flag.'|'.$tenantKey) % 100) < $pct, 'source' => 'rollout'];
        }

        return ['value' => (bool) ($def['default'] ?? false), 'source' => 'default'];
    }

    /** @return list<string> */
    private static function list(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }

    /** "a=on,b=off" => ['a'=>true,'b'=>false]; anything else is ignored. */
    public static function parse(string $csv): array
    {
        $out = [];
        foreach (self::list($csv) as $pair) {
            [$name, $val] = array_pad(explode('=', $pair, 2), 2, '');
            $val = strtolower(trim($val));
            if (in_array($val, ['on', 'true', '1'], true)) {
                $out[trim($name)] = true;
            } elseif (in_array($val, ['off', 'false', '0'], true)) {
                $out[trim($name)] = false;
            }
        }

        return $out;
    }
}
