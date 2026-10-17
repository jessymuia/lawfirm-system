<?php

namespace App\Observability;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ENG-24: per-tenant usage metrics (users, matters, storage, API calls).
 *
 * - API calls are a counter, bumped after each API response (terminable, so no
 *   added latency) into control-plane `tenant_usage_daily`.
 * - users / matters / clients / invoices / storage are gauges, measured inside
 *   the tenant's own database by `tenants:usage` and stored for the day.
 *
 * Metrics must never break a request: every write swallows and reports errors.
 */
class UsageMetrics
{
    public const API_CALLS = 'api_calls';

    public function __construct(private TenantManager $tenants) {}

    /** Count one API call for the tenant currently active. No-op in single mode. */
    public function countApiCall(): void
    {
        $tenant = $this->tenants->current();
        if (! $this->tenants->isMulti() || ! $tenant) {
            return;
        }

        try {
            DB::connection(config('tenancy.control_connection'))->table('tenant_usage_daily')->upsert(
                [['tenant_id' => $tenant->id, 'day' => now()->toDateString(), 'metric' => self::API_CALLS, 'value' => 1, 'created_at' => now(), 'updated_at' => now()]],
                ['tenant_id', 'day', 'metric'],
                ['value' => DB::raw('value + 1'), 'updated_at' => now()],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Gauges measured on the CURRENT connection (run inside a tenant).
     *
     * @return array<string,int>
     */
    public function gauges(): array
    {
        $count = fn (string $table) => Schema::hasTable($table)
            ? (int) DB::table($table)->when(Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->count()
            : 0;

        return [
            'users' => $this->seats(),
            'matters' => $count('cases'),
            'clients' => $count('clients'),
            'invoices' => $count('invoices'),
            'documents' => $count('documents'),
            'storage_bytes' => Schema::hasTable('documents') ? (int) DB::table('documents')->sum('filesize') : 0,
        ];
    }

    /** Billable seats: real users only. Ephemeral platform-support principals (PLAT-8) never count against a plan. */
    private function seats(): int
    {
        if (! Schema::hasTable('users')) {
            return 0;
        }

        return (int) DB::table('users')->whereNull('deleted_at')
            ->when(Schema::hasColumn('users', 'platform_staff_id'), fn ($q) => $q->whereNull('platform_staff_id'))->count();
    }

    /** One gauge, measured live on the current connection (used by limit checks). */
    public function current(string $name): int
    {
        return match ($name) {
            'users' => $this->seats(),
            'storage_bytes' => Schema::hasTable('documents') ? (int) DB::table('documents')->sum('filesize') : 0,
            default => (int) ($this->gauges()[$name] ?? 0),
        };
    }

    /** Measure one tenant and store today's gauges. @return array<string,int> */
    public function snapshot(Tenant $tenant): array
    {
        $gauges = $this->tenants->runIn($tenant, fn () => $this->gauges());
        $day = now()->toDateString();

        foreach ($gauges as $metric => $value) {
            DB::connection(config('tenancy.control_connection'))->table('tenant_usage_daily')->upsert(
                [['tenant_id' => $tenant->id, 'day' => $day, 'metric' => $metric, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]],
                ['tenant_id', 'day', 'metric'],
                ['value', 'updated_at'],
            );
        }

        return $gauges;
    }
}
