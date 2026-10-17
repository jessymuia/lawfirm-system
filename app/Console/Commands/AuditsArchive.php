<?php

namespace App\Console\Commands;

use App\Audit\AuditArchiver;
use App\Audit\AuditPartitions;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * DATA-11 (scheduled monthly): per firm, keep the next audit partitions ready, archive the months older than the
 * firm's online window, and delete archive files past retention.
 */
class AuditsArchive extends Command
{
    protected $signature = 'audits:archive {--tenant= : Only this firm (multi mode)} {--dry-run : List the months that would be archived}';

    protected $description = 'Archive audit months older than the plan\'s online window and prune archives past retention';

    public function handle(TenantManager $tenants): int
    {
        $failed = 0;
        $run = function (string $firm) use (&$failed) {
            if (! Schema::hasTable('audit_archives')) {
                return;
            }
            $archiver = app(AuditArchiver::class);
            app(AuditPartitions::class)->ensure();
            $due = $archiver->dueMonths();
            if ($this->option('dry-run')) {
                $this->line("{$firm}: ".($due ? 'would archive '.implode(', ', $due) : 'nothing due').' (online: '.($archiver->onlineMonths() ?? 'all').' months)');

                return;
            }
            foreach ($due as $month) {
                try {
                    $r = $archiver->archive($month);
                    $this->info("{$firm}: {$month} archived ({$r['rows']} rows, removed by {$r['how']})");
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error("{$firm}: {$month}: {$e->getMessage()}");

                    return;   // months are archived in order; stop this firm at the first failure
                }
            }
            foreach ($archiver->prune() as $month) {
                $this->line("{$firm}: archive of {$month} deleted (past {$archiver->retentionYears()} years)");
            }
        };

        if (! $tenants->isMulti()) {
            $run('default');
        } else {
            $q = Tenant::whereIn('status', ['active', 'suspended'])->orderBy('slug');
            if ($slug = $this->option('tenant')) {
                $q->where('slug', $slug);
            }
            foreach ($q->get() as $tenant) {
                $tenants->runIn($tenant, fn () => $run($tenant->slug), allowInactive: true);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
