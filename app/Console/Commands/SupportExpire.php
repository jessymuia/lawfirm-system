<?php

namespace App\Console\Commands;

use App\SupportAccess\SupportAccessService;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;

/** PLAT-8: nothing outlives its window. Per-request checks already refuse expired sessions; this also removes the principals. */
class SupportExpire extends Command
{
    protected $signature = 'support:expire';

    protected $description = 'End expired platform-support sessions and remove their ephemeral users (all tenants)';

    public function handle(TenantManager $tenants, SupportAccessService $service): int
    {
        if (! $tenants->isMulti()) {
            return self::SUCCESS;
        }
        $total = 0;
        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            try {
                $total += $tenants->runIn($tenant, fn () => $service->expireDue());
            } catch (\Throwable $e) {
                $this->error("{$tenant->slug}: {$e->getMessage()}");   // one broken tenant must not stop the sweep
            }
        }
        $this->info("Ended {$total} expired support session(s).");

        return self::SUCCESS;
    }
}
