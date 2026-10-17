<?php

namespace App\Console\Commands;

use App\Backup\RestoreDrill;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** TEN-14: restore drill of one firm (by default the one drilled longest ago), or the log of past drills (--list). */
class TenantsRestoreDrill extends Command
{
    protected $signature = 'tenants:restore-drill {--tenant= : drill this firm} {--list : show the recent drills}';

    protected $description = 'Prove a firm can be restored: restore its latest snapshot next to it, check it, drop it, log it';

    public function handle(RestoreDrill $drill, TenantManager $tenants): int
    {
        if (! $tenants->isMulti()) {
            $this->info('Restore drills are per firm, in multi-tenant mode. Nothing to do.');

            return self::SUCCESS;   // the scheduler runs it on every install
        }
        if ($this->option('list')) {
            $rows = DB::connection('control')->table('tenant_restores')->join('tenants', 'tenants.id', '=', 'tenant_restores.tenant_id')
                ->where('kind', 'drill')->orderByDesc('tenant_restores.id')->limit(30)
                ->get(['tenants.slug', 'tenant_restores.snapshot', 'tenant_restores.outcome', 'tenant_restores.duration_ms', 'tenant_restores.started_at']);
            $this->table(['firm', 'snapshot', 'outcome', 'ms', 'started'], $rows->map(fn ($r) => (array) $r)->all());

            return self::SUCCESS;
        }
        $tenant = $this->option('tenant') ? Tenant::where('slug', $this->option('tenant'))->first() : $drill->next();
        if (! $tenant) {
            $this->error('No firm to drill.');

            return self::FAILURE;
        }
        $row = $drill->run($tenant, $this->option('tenant') ? 'cli' : 'scheduler');
        $report = json_decode($row->report, true);
        $this->line(sprintf('%s: drill %s in %d ms (%s)%s', $tenant->slug, strtoupper($row->outcome), $row->duration_ms, $report['mode'] ?? '-',
            isset($report['error']) ? ': '.$report['error'] : ''));

        return $row->outcome === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
