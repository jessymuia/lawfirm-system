<?php

namespace App\Console\Commands;

use App\Tenancy\Models\Tenant;
use App\Tenancy\SchemaDrift;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TEN-8: compare each firm's database with the schema its own applied migrations should produce. Scheduled daily.
 * Any difference is logged as an error (so it reaches whatever alerting watches the logs), recorded on the tenant
 * row for the platform console, and makes the command exit non-zero.
 */
class SchemaDriftCommand extends Command
{
    protected $signature = 'schema:drift {--tenant= : Only this tenant slug}';

    protected $description = 'Detect schema drift: each firm database vs. a reference built from its own applied migrations';

    public function handle(SchemaDrift $drift, TenantManager $tenants): int
    {
        $drifted = 0;
        $report = function (string $label, array $problems) use (&$drifted) {
            if ($problems === []) {
                $this->line("{$label}: schema matches its migrations.");

                return;
            }
            $drifted++;
            $this->error("{$label}: ".count($problems).' difference(s)');
            foreach ($problems as $p) {
                $this->line("  - {$p}");
            }
            Log::error('Schema drift detected', ['firm' => $label, 'differences' => $problems]);
        };

        if (! $tenants->isMulti()) {
            $report('this installation', $drift->check(DB::getDefaultConnection()));

            return $drifted ? self::FAILURE : self::SUCCESS;
        }

        $q = Tenant::whereNotIn('status', ['provisioning', 'offboarded', 'purged'])->orderBy('slug');
        $this->option('tenant') && $q->where('slug', $this->option('tenant'));
        foreach ($q->get() as $tenant) {
            try {
                $problems = $tenants->runIn($tenant, fn () => $drift->check(TenantManager::TENANT_CONNECTION), allowInactive: true);
            } catch (\Throwable $e) {
                $problems = ['check could not run: '.mb_substr($e->getMessage(), 0, 300)];
            }
            $tenant->update(['schema_drift' => $problems ?: null, 'drift_checked_at' => now()]);
            $report($tenant->slug, $problems);
        }

        return $drifted ? self::FAILURE : self::SUCCESS;
    }
}
