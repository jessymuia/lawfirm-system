<?php

namespace App\Console\Commands;

use App\Audit\AuditPartitions;
use Illuminate\Console\Command;

/**
 * DATA-11: switch this database's audit table to monthly partitions (MySQL/MariaDB). Rebuilds the table once: run it
 * in a maintenance window. In multi mode pass --tenant=<slug>, once per firm.
 */
class AuditsPartition extends Command
{
    protected $signature = 'audits:partition {--enable : Rebuild the table with monthly partitions} {--tenant= : Firm slug (multi mode)}';

    protected $description = 'Show or enable monthly partitions on the audit table';

    public function handle(AuditPartitions $partitions, \App\Tenancy\TenantManager $tenants): int
    {
        $go = function () use ($partitions): int {
            if (! $partitions->supported()) {
                $this->warn('This database driver has no partitions; archiving deletes row by row.');

                return self::SUCCESS;
            }
            if ($this->option('enable')) {
                $partitions->enable();
            }
            $names = $partitions->names();
            $this->line($names ? 'Partitions: '.implode(', ', $names) : 'Not partitioned (run with --enable).');

            return self::SUCCESS;
        };

        if ($slug = $this->option('tenant')) {
            $tenant = \App\Tenancy\Models\Tenant::where('slug', $slug)->firstOrFail();

            return $tenants->runIn($tenant, $go, allowInactive: true);
        }

        return $go();
    }
}
