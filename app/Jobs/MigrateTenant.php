<?php

namespace App\Jobs;

use App\Tenancy\Models\Tenant;
use App\Tenancy\PlatformJob;
use App\Tenancy\TenantMigrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * TEN-7: migrate one firm, from the queue. Many workers can work through the fleet in parallel; ShouldBeUnique keeps
 * a firm queued at most once, and TenantMigrator's per-firm lock keeps two runs (queued or `tenants:migrate`) off the
 * same database. Failure is recorded on the tenant row by the migrator and never retried blindly (tries = 1):
 * `tenants:migrate --resume` re-queues what is pending, failed or stuck.
 */
class MigrateTenant implements PlatformJob, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function __construct(public string $slug) {}

    public function uniqueId(): string
    {
        return $this->slug;
    }

    public function handle(TenantMigrator $migrator): void
    {
        $tenant = Tenant::where('slug', $this->slug)->first();
        if ($tenant && ! in_array($tenant->status, ['offboarded', 'purged'], true)) {
            $migrator->migrate($tenant);
        }
    }
}
