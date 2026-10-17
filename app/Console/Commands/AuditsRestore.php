<?php

namespace App\Console\Commands;

use App\Audit\AuditArchiver;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;

/** DATA-11: put an archived month of the audit trail back into the live table (verified by SHA-256 first). */
class AuditsRestore extends Command
{
    protected $signature = 'audits:restore {month : YYYY-MM} {--tenant= : Firm slug (multi mode)}';

    protected $description = 'Restore an archived month of the audit trail';

    public function handle(AuditArchiver $archiver, TenantManager $tenants): int
    {
        $go = function () use ($archiver): int {
            try {
                $n = $archiver->restore((string) $this->argument('month'));
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info("{$n} audit row(s) restored. The next audits:archive run archives them again if the month is still due.");

            return self::SUCCESS;
        };

        if ($slug = $this->option('tenant')) {
            return $tenants->runIn(Tenant::where('slug', $slug)->firstOrFail(), $go, allowInactive: true);
        }

        return $go();
    }
}
