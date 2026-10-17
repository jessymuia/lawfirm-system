<?php

namespace App\Billing;

use App\Tenancy\TenantManager;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PLAT-4: the plan catalogue the operator manages (control-plane `plans` table).
 *
 * Billing and entitlements still read config('billing.plans') and config('entitlements.plans'); sync() overlays whatever the
 * table holds onto that config (once per process, then every 30 seconds), so nothing else had to change. An empty table, a
 * missing table or single-tenant mode all mean "use the config files as they are".
 */
class PlanStore
{
    /** Plans the platform itself relies on (free trials): they can be edited, never archived or deleted. */
    public const SYSTEM = ['trial'];

    private static function db(): Connection
    {
        return DB::connection(config('tenancy.control_connection'));
    }

    /** Overlay the table onto config. Safe to call anywhere, any number of times. */
    public static function sync(bool $force = false): void
    {
        if (! app(TenantManager::class)->hasControlPlane()) {
            return;
        }
        $at = (int) config('billing.plans_synced_at', 0);
        if (! $force && $at && time() - $at < 30) {
            return;
        }
        config(['billing.plans_synced_at' => time()]);

        try {
            $rows = self::db()->table('plans')->orderBy('sort')->orderBy('id')->get();
        } catch (Throwable) {
            return;   // table not there yet (before the control migrations): the config files apply
        }
        if ($rows->isEmpty()) {
            return;
        }

        // The table is now the whole catalogue of billable plans: a built-in plan deleted from it must not come back from the
        // config files. Plans that are not billed (self_hosted, unlicensed) stay as configured.
        $billing = [];
        $entitlements = array_diff_key((array) config('entitlements.plans'), (array) config('billing.plans'));
        $archived = [];
        $names = [];
        foreach ($rows as $r) {
            $billing[$r->code] = array_map('intval', (array) json_decode((string) $r->prices, true));
            $entitlements[$r->code] = [
                'features' => array_fill_keys(array_keys(array_filter((array) json_decode((string) $r->features, true))), true),
                'limits' => (array) json_decode((string) $r->limits, true),
            ];
            $names[$r->code] = $r->name;
            if ($r->archived_at) {
                $archived[] = $r->code;
            }
        }
        config(['billing.plans' => $billing, 'entitlements.plans' => $entitlements, 'billing.archived_plans' => $archived, 'billing.plan_names' => $names]);
    }

    /** Plan codes that can be chosen for a firm today: every priced plan that is not archived. */
    public static function selectable(): array
    {
        self::sync();

        return array_values(array_diff(array_keys((array) config('billing.plans')), (array) config('billing.archived_plans', [])));
    }

    /** Copy the built-in plans into the table the first time (idempotent). */
    public static function seedIfEmpty(): void
    {
        if (self::db()->table('plans')->exists()) {
            return;
        }
        $sort = 0;
        foreach ((array) config('billing.plans') as $code => $prices) {
            $plan = (array) (config('entitlements.plans')[$code] ?? []);
            self::db()->table('plans')->insert([
                'code' => $code, 'name' => ucfirst(str_replace('_', ' ', $code)), 'description' => null, 'sort' => ++$sort * 10,
                'prices' => json_encode(array_map('intval', (array) $prices)),
                'features' => json_encode((object) array_filter((array) ($plan['features'] ?? []))),
                'limits' => json_encode((object) (array) ($plan['limits'] ?? [])),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        self::sync(true);
    }

    /** @return list<array<string, mixed>> every plan, with how many subscriptions and firms use it */
    public static function all(): array
    {
        $builtin = false;
        try {
            $rows = self::db()->table('plans')->orderBy('sort')->orderBy('id')->get()->all();
        } catch (Throwable) {
            $rows = [];
        }
        if ($rows === []) {
            $builtin = true;
            $sort = 0;
            foreach ((array) config('billing.plans') as $code => $prices) {
                $plan = (array) (config('entitlements.plans')[$code] ?? []);
                $rows[] = (object) ['code' => $code, 'name' => ucfirst(str_replace('_', ' ', $code)), 'description' => null, 'sort' => ++$sort * 10,
                    'prices' => json_encode($prices), 'features' => json_encode((object) array_filter((array) ($plan['features'] ?? []))),
                    'limits' => json_encode((object) (array) ($plan['limits'] ?? [])), 'archived_at' => null];
            }
        }

        $subscribers = [];
        $firms = [];
        try {
            $subscribers = self::db()->table('subscriptions')->where('status', '!=', 'canceled')->selectRaw('plan, count(*) as n')->groupBy('plan')->pluck('n', 'plan')->all();
            $firms = self::db()->table('tenants')->selectRaw('plan, count(*) as n')->groupBy('plan')->pluck('n', 'plan')->all();
        } catch (Throwable) {
        }

        return array_map(fn ($r) => self::present($r, $builtin, (int) ($subscribers[$r->code] ?? 0), (int) ($firms[$r->code] ?? 0)), $rows);
    }

    /** @return array<string, mixed> */
    public static function present(object $r, bool $builtin, int $subscribers, int $firms): array
    {
        $limits = (array) json_decode((string) $r->limits, true);

        return [
            'code' => $r->code, 'name' => $r->name, 'description' => $r->description,
            'archived' => (bool) $r->archived_at, 'system' => in_array($r->code, self::SYSTEM, true), 'builtin' => $builtin,
            'prices' => array_map('intval', (array) json_decode((string) $r->prices, true)),
            'features' => array_keys(array_filter((array) json_decode((string) $r->features, true))),
            'limits' => [
                'users' => $limits['users'] ?? null,
                'storage_gb' => isset($limits['storage_bytes']) ? round($limits['storage_bytes'] / 1024 ** 3, 2) : null,
                'audit_online_months' => $limits['audit_online_months'] ?? null,
            ],
            'subscribers' => $subscribers, 'firms' => $firms,
        ];
    }

    /**
     * @param  array<string, mixed>  $d  validated input: name, description, prices, features, limits
     * @return array<string, string> the JSON columns
     */
    public static function columns(array $d): array
    {
        $prices = [];
        foreach ((array) ($d['prices'] ?? []) as $currency => $amount) {
            if ($amount !== null && $amount !== '') {
                $prices[$currency] = (int) $amount;
            }
        }
        $limits = (array) ($d['limits'] ?? []);

        return [
            'prices' => json_encode((object) $prices),
            'features' => json_encode((object) array_fill_keys(array_values((array) ($d['features'] ?? [])), true)),
            'limits' => json_encode([
                'users' => isset($limits['users']) && $limits['users'] !== '' ? (int) $limits['users'] : null,
                'storage_bytes' => isset($limits['storage_gb']) && $limits['storage_gb'] !== '' ? (int) round((float) $limits['storage_gb'] * 1024 ** 3) : null,
                'audit_online_months' => isset($limits['audit_online_months']) && $limits['audit_online_months'] !== '' ? (int) $limits['audit_online_months'] : null,
            ]),
        ];
    }

    public static function inUse(string $code): bool
    {
        return self::db()->table('subscriptions')->where('plan', $code)->exists() || self::db()->table('tenants')->where('plan', $code)->exists();
    }
}
