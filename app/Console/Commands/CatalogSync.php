<?php

namespace App\Console\Commands;

use App\Catalog\SystemCatalog;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * TEN-11: put the platform-owned reference catalog (courts, court types, hearing types)
 * into this install, or into every firm's database. app:upgrade and tenants:migrate
 * already do this; the command is for operators and for checking what a release changes.
 */
class CatalogSync extends Command
{
    protected $signature = 'catalog:sync {--dry-run : Report what would change; change nothing} {--tenant= : Only this tenant slug}';

    protected $description = 'Sync system reference data (courts, court types, hearing types) into the firm database(s)';

    public function handle(TenantManager $tenants): int
    {
        $dry = (bool) $this->option('dry-run');
        $failed = 0;

        $once = function (string $label) use ($dry, &$failed) {
            $report = SystemCatalog::sync($dry);
            if ($report === null) {
                $failed++;
                $this->error("{$label}: schema is not migrated yet (run app:upgrade first).");

                return;
            }
            $parts = [];
            foreach ($report as $table => $c) {
                $parts[] = sprintf('%s %d new, %d adopted, %d updated, %d unchanged', $table, $c['created'], $c['adopted'], $c['updated'], $c['unchanged']);
            }
            $this->line(($dry ? '[dry run] ' : '')."{$label}: ".implode('; ', $parts));
        };

        if (! $tenants->isMulti()) {
            $once('this firm');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $q = Tenant::whereNotIn('status', ['offboarded', 'purged'])->orderBy('slug');
        $this->option('tenant') && $q->where('slug', $this->option('tenant'));
        foreach ($q->get() as $tenant) {
            try {
                $tenants->runIn($tenant, fn () => $once($tenant->slug), allowInactive: true);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("{$tenant->slug}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
