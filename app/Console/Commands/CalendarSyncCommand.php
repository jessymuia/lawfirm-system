<?php

namespace App\Console\Commands;

use App\Calendar\CalendarSync;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** FUN-16: the safety net behind change-triggered syncs: every connected calendar, every 15 minutes. */
class CalendarSyncCommand extends Command
{
    protected $signature = 'calendar:sync {--tenant= : Only this tenant slug}';

    protected $description = 'Bring connected Google / Microsoft calendars in step with hearings and tasks';

    public function handle(CalendarSync $sync, TenantManager $tenants): int
    {
        $each = function (string $label) use ($sync) {
            if (! Schema::hasTable('calendar_connections')) {
                return;
            }
            $n = 0;
            foreach (DB::table('calendar_connections')->where('state', 'active')->pluck('id') as $id) {
                $sync->run((int) $id) !== null && $n++;
            }
            $n && $this->line("{$label}: {$n} calendar(s) synced");
        };
        if (! $tenants->isMulti()) {
            $each('this firm');

            return self::SUCCESS;
        }
        $q = Tenant::where('status', 'active')->orderBy('slug');
        $this->option('tenant') && $q->where('slug', $this->option('tenant'));
        $failed = 0;
        foreach ($q->get() as $t) {
            try {
                $tenants->runIn($t, fn () => $each($t->slug));
            } catch (\Throwable $e) {
                $failed++;
                $this->error("{$t->slug}: {$e->getMessage()}");   // one broken firm must not stop the others
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
