<?php

namespace App\Console\Commands;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantLifecycle;
use Illuminate\Console\Command;
use Throwable;

/**
 * TEN-9: runs deletion when it is due. Every offboarded firm whose retention window has passed, that is not under legal
 * hold and has a final export, is purged. The others are listed with the reason they wait. A firm without a final export
 * is never purged automatically: someone decides (`tenants:final-export`, or `tenants:purge --without-export=<why>`).
 */
class TenantsPurgeDue extends Command
{
    protected $signature = 'tenants:purge-due {--dry-run : List what would be purged}';

    protected $description = 'Purge offboarded firms whose retention window has passed (skips legal holds and firms without a final export)';

    public function handle(TenantLifecycle $lifecycle): int
    {
        $failed = 0;
        foreach (Tenant::where('status', 'offboarded')->where('purge_after', '<=', now())->orderBy('slug')->get() as $t) {
            $wait = match (true) {
                $t->legal_hold => 'legal hold',
                $t->final_export_at === null => 'no final export'.($t->final_export_error ? ' (failed: '.$t->final_export_error.')' : ''),
                default => null,
            };
            if ($wait !== null) {
                $this->warn("{$t->slug}: waiting, {$wait}");

                continue;
            }
            if ($this->option('dry-run')) {
                $this->line("{$t->slug}: would be purged");

                continue;
            }
            try {
                $lifecycle->purge($t);
                $this->info("{$t->slug}: purged");
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$t->slug}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
