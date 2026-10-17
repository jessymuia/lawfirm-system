<?php

namespace App\Console\Commands;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantManager;
use App\Warehouse\WarehouseExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ENG-26: load yesterday's aggregates of this firm (or every firm) into the warehouse. Scheduled nightly. */
class WarehouseExport extends Command
{
    protected $signature = 'warehouse:export {--date= : the day (Y-m-d); default yesterday} {--days=1 : how many days back from it, to backfill}';

    protected $description = 'Export daily per-firm aggregates to the analytics warehouse';

    public function handle(TenantManager $tenants, WarehouseExporter $exporter): int
    {
        if (! config('warehouse.enabled')) {
            $this->warn('WAREHOUSE_ENABLED is off: nothing exported.');

            return self::SUCCESS;
        }
        $last = $this->option('date') ?: now()->subDay()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $last)) {
            $this->error('--date must be Y-m-d.');

            return self::FAILURE;
        }
        $dates = array_map(fn ($i) => date('Y-m-d', strtotime("{$last} -{$i} day")), range(max(1, (int) $this->option('days')) - 1, 0));

        if (! $tenants->isMulti()) {
            $key = app(TenantContext::class)->key();
            $firm = ['tenant_id' => null, 'status' => 'active', 'plan' => (string) (config('entitlements.single_tenant_plan') ?: 'self-hosted'), 'cell' => 'default',
                'region' => null, 'created_on' => substr((string) (DB::table('users')->min('created_at') ?? now()), 0, 10)];
            foreach ($dates as $date) {
                $this->report($key, $date, $exporter->exportFirm($date, $key, $firm));
            }

            return self::SUCCESS;
        }

        $subscriptions = Schema::connection((string) config('tenancy.control_connection'))->hasTable('subscriptions')
            ? DB::connection((string) config('tenancy.control_connection'))->table('subscriptions')->pluck('plan', 'tenant_id') : collect();
        foreach (Tenant::whereIn('status', ['active', 'suspended'])->orderBy('id')->get() as $tenant) {
            $firm = ['tenant_id' => (int) $tenant->id, 'status' => (string) $tenant->status, 'plan' => (string) ($subscriptions[$tenant->id] ?? $tenant->plan ?? 'none'),
                'cell' => (string) ($tenant->cell ?: 'default'), 'region' => $tenant->region, 'created_on' => $tenant->created_at?->toDateString() ?? $last];
            foreach ($dates as $date) {
                $this->report($tenant->slug, $date, $tenants->runIn($tenant, fn () => $exporter->exportFirm($date, $tenant->slug, $firm), allowInactive: true));
            }
        }

        return self::SUCCESS;
    }

    private function report(string $key, string $date, array $counts): void
    {
        $this->line("{$key} {$date}: ".collect($counts)->map(fn ($n, $t) => "{$t}={$n}")->implode(' '));
    }
}
