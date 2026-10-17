<?php

namespace App\Console\Commands;

use App\Backup\FirmRestore;
use App\Backup\FirmSnapshots;
use App\Database\MigrationRehearsal;
use App\Database\VolumeFiller;
use App\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DATA-13: time the migrations about to ship against real volume, so one that would lock a large firm out is caught
 * before the fleet runs it (docs/MIGRATION_POLICY.md, "Rehearsal").
 *
 *  --largest / --tenant=slug   the new code's pending migrations on a copy of that firm's latest snapshot (SaaS, before
 *                              tenants:migrate)
 *  --synthetic=N               the newest --last=K migrations on a scratch database holding N rows per growing table (CI)
 *
 * Fails when any single migration takes longer than --budget seconds. The scratch database is always dropped.
 */
class MigrationsRehearse extends Command
{
    protected $signature = 'migrations:rehearse {--tenant= : rehearse on this firm\'s latest snapshot} {--largest : on the firm with the most rows}
        {--synthetic= : rows per table in a synthetic database instead} {--last=10 : with --synthetic, the newest K migrations}
        {--budget=60 : seconds one migration may take}';

    protected $description = 'Time pending migrations against the largest firm (or synthetic volume) before they ship';

    public function handle(MigrationRehearsal $rehearsal): int
    {
        $budget = (float) $this->option('budget');
        try {
            [$connection, $files, $volume, $cleanup] = $this->option('synthetic') !== null ? $this->synthetic($rehearsal) : $this->firm($rehearsal);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        try {
            if ($files === []) {
                $this->info("Nothing to rehearse: the source is already migrated ({$volume} rows).");

                return self::SUCCESS;
            }
            $this->line('Rehearsing '.count($files)." migration(s) against {$volume} rows (budget {$budget}s each)…");
            $timings = $rehearsal->run($connection, $files);
            $this->table(['migration', 'seconds'], array_map(fn ($t) => [$t['migration'], number_format($t['seconds'], 3)], $timings));
            $over = array_filter($timings, fn ($t) => $t['seconds'] > $budget);
            foreach ($over as $t) {
                $this->error("{$t['migration']} took {$t['seconds']}s, over the {$budget}s budget: split it, or move the data work into a queued backfill (docs/MIGRATION_POLICY.md).");
            }

            return $over ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('A migration failed during the rehearsal: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $cleanup();
        }
    }

    /** @return array{0: string, 1: list<string>, 2: int, 3: \Closure} */
    private function synthetic(MigrationRehearsal $rehearsal): array
    {
        $base = (string) config('database.default');
        $conn = (array) config("database.connections.{$base}");
        $name = 'rehearsal_'.now()->format('YmdHis');
        if (($conn['driver'] ?? null) === 'sqlite') {
            $conn['database'] = sys_get_temp_dir()."/{$name}.sqlite";
            touch($conn['database']);
            $drop = fn () => @unlink($conn['database']);
        } else {
            DB::connection($base)->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $conn['database'] = $name;
            $drop = fn () => DB::connection($base)->statement("DROP DATABASE IF EXISTS `{$name}`");
        }
        config(['database.connections.rehearsal' => $conn]);
        DB::purge('rehearsal');

        $cleanup = function () use ($drop) {
            DB::purge('rehearsal');
            $drop();
        };
        $all = $rehearsal->files();
        $last = max(1, (int) $this->option('last'));
        try {
            $rehearsal->prepare('rehearsal', array_slice($all, 0, -$last));
            $rows = array_sum((new VolumeFiller)->fill(DB::connection('rehearsal'), (int) $this->option('synthetic')));
        } catch (Throwable $e) {
            $cleanup();
            throw $e;
        }

        return ['rehearsal', array_slice($all, -$last), $rows, $cleanup];
    }

    /** @return array{0: string, 1: list<string>, 2: int, 3: \Closure} */
    private function firm(MigrationRehearsal $rehearsal): array
    {
        $snapshots = app(FirmSnapshots::class);
        if ($slug = $this->option('tenant')) {
            $tenant = Tenant::where('slug', $slug)->firstOrFail();
        } elseif ($this->option('largest')) {
            $tenant = Tenant::whereIn('status', ['active', 'suspended'])->get()
                ->sortByDesc(fn ($t) => array_sum($snapshots->latest($t)['tables'] ?? []))->first()
                ?? throw new \RuntimeException('No firm to rehearse on.');
        } else {
            throw new \RuntimeException('Choose --largest, --tenant=<slug> or --synthetic=<rows>.');
        }
        $restore = app(FirmRestore::class);
        $r = $restore->restore($tenant);   // its latest snapshot, beside the live database
        config(['database.connections.rehearsal' => $r['conn']]);
        DB::purge('rehearsal');
        $this->line("Rehearsing on a copy of {$tenant->slug}'s snapshot {$r['manifest']['id']}.");

        return ['rehearsal', $rehearsal->pending('rehearsal'), array_sum($r['counts']), function () use ($restore, $r) {
            DB::purge('rehearsal');
            $restore->discard($r);
        }];
    }
}
