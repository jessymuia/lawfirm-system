<?php

namespace App\Console\Commands;

use App\Compliance\Retention;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;

/** SEC-13: dispose of records past their retention period, class by class. A dry run unless --execute is given. */
class RetentionRun extends Command
{
    protected $signature = 'retention:run {--execute : Actually dispose (default is a report only)} {--tenant= : Only this tenant slug}';

    protected $description = 'Report or dispose of records past their retention period (records under legal hold are never touched)';

    public function handle(Retention $retention, TenantManager $tenants): int
    {
        $execute = (bool) $this->option('execute');
        $failed = 0;
        $once = function (string $label) use ($retention, $execute, &$failed) {
            $any = false;
            foreach ($retention->purgeableClasses() as $class) {
                $p = $retention->policy($class);
                if (! $p) {
                    continue;
                }
                $any = true;
                // the scheduled run only disposes when the firm has switched auto-purge on for the class; an explicit --execute by
                // an operator also honours it, so no one can purge records a firm has not opted in to.
                $go = $execute && $p->auto_purge;
                if ($execute && ! $p->auto_purge) {
                    $this->warn("{$label} [{$class}]: auto-purge is off; reporting only.");
                }
                $r = $retention->run($go, null, $class);
                $failed += $r['errors'];
                $this->line(sprintf('%s [%s]: %s %d record(s); %d held and skipped; %d error(s)', $label, $class, $go ? 'disposed' : 'would dispose', $r['disposed'], $r['held_skipped'], $r['errors']));
            }
            if (! $any) {
                $this->line("{$label}: no retention policy set; nothing to do.");
            }
        };

        if (! $tenants->isMulti()) {
            $once('this firm');

            return $failed ? self::FAILURE : self::SUCCESS;
        }
        $q = Tenant::where('status', 'active')->orderBy('slug');
        $this->option('tenant') && $q->where('slug', $this->option('tenant'));
        foreach ($q->get() as $tenant) {
            try {
                $tenants->runIn($tenant, fn () => $once($tenant->slug));
            } catch (\Throwable $e) {
                $failed++;
                $this->error("{$tenant->slug}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
