<?php

namespace App\Console\Commands;

use App\Observability\UsageMetrics;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Throwable;

/** ENG-24: measure and store each tenant's gauges (users, matters, storage...). */
class TenantsUsage extends Command
{
    protected $signature = 'tenants:usage {--tenant= : Only this tenant slug}';

    protected $description = 'Record per-tenant usage gauges (users, matters, clients, invoices, documents, storage)';

    public function handle(UsageMetrics $metrics, TenantManager $tenants): int
    {
        if (! $tenants->isMulti()) {
            $this->table(['metric', 'value'], collect($metrics->gauges())->map(fn ($v, $k) => [$k, $v])->values()->all());

            return self::SUCCESS;
        }

        $failed = 0;
        $query = Tenant::where('status', 'active')->orderBy('slug');
        if ($slug = $this->option('tenant')) {
            $query->where('slug', $slug);
        }

        foreach ($query->get() as $tenant) {
            try {
                $g = $metrics->snapshot($tenant);
                $this->line(sprintf('%-24s users=%d matters=%d storage=%d', $tenant->slug, $g['users'], $g['matters'], $g['storage_bytes']));
            } catch (Throwable $e) {
                // One broken tenant must not stop the fleet.
                $failed++;
                $this->error("{$tenant->slug}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
