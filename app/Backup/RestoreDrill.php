<?php

namespace App\Backup;

use App\Models\Client;
use App\Tenancy\CellRegistry;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * TEN-14: a restore drill proves a firm can actually be brought back, and how long it takes. It restores the firm's
 * latest snapshot next to the live database (rolled forward to now when the binary log allows), then checks:
 *
 *  - the tables and rows: equal to the snapshot's manifest (a snapshot restore), or present (a point-in-time one);
 *  - the pair: every file the restored database points at exists or is recoverable;
 *  - the app: a firm query runs against the restored database.
 *
 * The restored copy is dropped afterwards. The outcome, timing and report go to `tenant_restores` (kind `drill`) and
 * the log; a failed drill logs at critical so alerting picks it up.
 */
class RestoreDrill
{
    public function __construct(private FirmRestore $restore, private FirmSnapshots $snapshots, private TenantManager $tenants) {}

    /** The active firm whose last drill is oldest (never drilled first). */
    public function next(): ?Tenant
    {
        $last = DB::connection('control')->table('tenant_restores')->where('kind', 'drill')->groupBy('tenant_id')->selectRaw('tenant_id, MAX(started_at) AS last')->pluck('last', 'tenant_id');

        return Tenant::where('status', 'active')->orderBy('id')->get()->sortBy(fn ($t) => $last[$t->id] ?? '0000')->first();
    }

    /** @return object the tenant_restores row */
    public function run(Tenant $tenant, string $by = 'scheduler'): object
    {
        $started = now();
        $t0 = hrtime(true);
        $report = ['checks' => []];
        $outcome = 'failed';
        $manifest = null;
        $target = null;
        try {
            $manifest = $this->snapshots->latest($tenant) ?? throw new \RuntimeException('No snapshot to restore from.');
            $live = app(CellRegistry::class)->connectionConfig($tenant);
            $pitr = ! empty($manifest['binlog']) && $manifest['database'] === basename((string) $live['database'])
                && $this->snapshots->engine($live)->supportsPointInTime($live);
            $target = $pitr ? now()->toDateTimeString() : null;
            $r = $this->restore->restore($tenant, $target, $pitr ? null : $manifest['id']);
            try {
                $report['mode'] = $r['pitr'] ? 'point-in-time' : 'snapshot';
                $report['files'] = array_diff_key($r['files'], ['missing' => 1]) + ['missing' => array_slice($r['files']['missing'], 0, 20)];
                $report['checks']['files_consistent'] = $r['consistent'];
                $missingTables = array_values(array_diff(array_keys($manifest['tables']), array_keys($r['counts'])));
                $report['checks']['tables_present'] = $missingTables === [];
                $missingTables && $report['missing_tables'] = $missingTables;
                if (! $r['pitr']) {
                    $diff = array_filter($manifest['tables'], fn ($n, $table) => ($r['counts'][$table] ?? null) !== $n, ARRAY_FILTER_USE_BOTH);
                    $report['checks']['rows_match_snapshot'] = $diff === [];
                    $diff && $report['row_differences'] = array_slice($diff, 0, 20, true);
                }
                // the app itself reads the restored firm
                $copy = $tenant->replicate()->forceFill(['database' => $r['conn']['database']]);
                $copy->id = $tenant->id;
                $report['clients_read'] = $this->tenants->runIn($copy, fn () => Client::withoutGlobalScopes()->count(), allowInactive: true);
                $report['checks']['app_reads'] = true;
            } finally {
                $this->restore->discard($r);
            }
            $outcome = in_array(false, $report['checks'], true) ? 'failed' : 'passed';
        } catch (\Throwable $e) {
            $report['error'] = $e->getMessage();
        }
        $ms = (int) ((hrtime(true) - $t0) / 1e6);
        $id = DB::connection('control')->table('tenant_restores')->insertGetId([
            'tenant_id' => $tenant->id, 'kind' => 'drill', 'snapshot' => $manifest['id'] ?? '-', 'target_time' => $target, 'outcome' => $outcome,
            'duration_ms' => $ms, 'report' => json_encode($report), 'run_by' => $by, 'started_at' => $started, 'finished_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $outcome === 'passed'
            ? logger()->info("Restore drill of {$tenant->slug} passed in {$ms} ms.", ['tenant' => $tenant->slug])
            : logger()->critical("Restore drill of {$tenant->slug} FAILED: ".($report['error'] ?? json_encode($report['checks'])), ['tenant' => $tenant->slug]);

        return DB::connection('control')->table('tenant_restores')->find($id);
    }
}
