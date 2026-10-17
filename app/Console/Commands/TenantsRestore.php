<?php

namespace App\Console\Commands;

use App\Backup\FirmRestore;
use App\Platform\PlatformAudit;
use App\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TEN-14: restore one firm to a snapshot or a moment (`--to="2026-10-07 14:00"`), into a database next to the live one.
 * Without --switch it reports and drops the copy (or keeps it with --keep, for inspection). With --switch and
 * --confirm=<slug>, the firm is moved onto the restored database with its files; the previous database is kept.
 */
class TenantsRestore extends Command
{
    protected $signature = 'tenants:restore {slug} {--to= : the moment to restore to (needs the binary log after the snapshot)}
        {--snapshot= : a snapshot id instead (tenants:snapshot <slug> --list)} {--keep : keep the restored copy instead of dropping it}
        {--switch : move the firm onto the restored database} {--confirm= : the firm\'s slug again, required with --switch}';

    protected $description = 'Restore one firm to a snapshot or a point in time, database and files together';

    public function handle(FirmRestore $restore): int
    {
        $tenant = Tenant::where('slug', $this->argument('slug'))->first();
        if (! $tenant) {
            $this->error('Unknown tenant.');

            return self::FAILURE;
        }
        if ($this->option('switch') && $this->option('confirm') !== $tenant->slug) {
            $this->error("--switch replaces the firm's live database: repeat its slug with --confirm={$tenant->slug}.");

            return self::FAILURE;
        }
        $started = now();
        $t0 = hrtime(true);
        try {
            $r = $restore->restore($tenant, $this->option('to') ?: null, $this->option('snapshot') ?: null);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info(sprintf('Restored snapshot %s%s into %s.', $r['manifest']['id'], $r['pitr'] ? " rolled forward to {$r['target']}" : '', basename((string) $r['conn']['database'])));
        $this->line(sprintf('Files: %d referenced, %d present, %d recoverable, %d missing.', $r['files']['referenced'], $r['files']['present'], $r['files']['recoverable'], count($r['files']['missing'])));
        foreach (array_slice($r['files']['missing'], 0, 10) as $m) {
            $this->warn("  missing: [{$m['disk']}] {$m['path']} ({$m['table']})");
        }

        $kind = 'restore';
        $extra = [];
        if ($this->option('switch')) {
            try {
                $extra = $restore->switchTo($tenant, $r);
                $kind = 'switch';
                $this->info("{$tenant->slug} now runs on the restored database; {$extra['files_restored']} file(s) put back. Previous database kept: {$extra['previous_database']}.");
            } catch (Throwable $e) {
                $this->error($e->getMessage());
            }
        }
        if ($kind !== 'switch' && ! $this->option('keep')) {
            $restore->discard($r);
        }
        DB::connection('control')->table('tenant_restores')->insert([
            'tenant_id' => $tenant->id, 'kind' => $kind, 'snapshot' => $r['manifest']['id'], 'target_time' => $r['target'],
            'restored_database' => basename((string) $r['conn']['database']), 'previous_database' => $extra['previous_database'] ?? null,
            'outcome' => $kind === 'switch' ? 'switched' : ($r['consistent'] ? 'passed' : 'failed'), 'duration_ms' => (int) ((hrtime(true) - $t0) / 1e6),
            'report' => json_encode(['files' => $r['files'], 'kept' => (bool) $this->option('keep')] + $extra), 'run_by' => 'cli',
            'started_at' => $started, 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        PlatformAudit::record(null, "tenant.restore.{$kind}", $tenant->slug, ['to' => $this->option('to'), 'snapshot' => $r['manifest']['id']], $r['consistent'] ? 'ok' : 'failed', null, 'cli');

        return $r['consistent'] && ($kind === 'switch' || ! $this->option('switch')) ? self::SUCCESS : self::FAILURE;
    }
}
