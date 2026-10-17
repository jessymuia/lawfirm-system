<?php

namespace App\Console\Commands;

use App\Services\Migration\ForeignKeyRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Command\Command as CommandAlias;

class MigrationOrphanScan extends Command
{
    /**
     * §6.2/§6.4 pre-flight orphan scan. Run in the OLD system BEFORE
     * Phase 1's export — the whole point is to surface every orphaned
     * row while there's still time to fix or map it by hand, rather than
     * finding out mid-import on cutover day (see §6.4's risk register:
     * "Orphaned FK rows abort import" -> "Pre-flight orphan scan run
     * before cutover day").
     *
     * Deliberately a *different* command from the post-migration
     * MigrationVerificationTest, even though both use the same
     * ForeignKeyRegistry: this one runs standalone, any time, against
     * whatever database the current app is pointed at (no 'source' vs
     * 'destination' connection distinction needed, since at this point
     * in the timeline there's only one database — the one about to be
     * exported). The PHPUnit test's job is strictly post-migration
     * comparison; this command's job is pre-migration cleanup.
     *
     * Exits non-zero if any orphan is found, so this is safe to wire
     * into a pre-cutover checklist/CI gate rather than relying on a
     * human reading scrollback output.
     */
    protected $signature = 'migration:orphan-scan {--fix-reassign-to= : User ID to reassign orphaned user-reference rows to, instead of only reporting them}';

    protected $description = 'Pre-flight scan for orphaned foreign keys before export (§6.2/§6.4)';

    public function handle(): int
    {
        $totalOrphans = 0;
        $reassignTo = $this->option('fix-reassign-to');

        foreach (ForeignKeyRegistry::all() as $fk) {
            if (! Schema::hasTable($fk['table']) || ! Schema::hasTable($fk['references_table'])) {
                continue;
            }

            $orphans = DB::table($fk['table'].' as t')
                ->whereNotNull("t.{$fk['column']}")
                ->whereNotExists(function ($query) use ($fk) {
                    $query->select(DB::raw(1))
                        ->from($fk['references_table'].' as r')
                        ->whereColumn("r.{$fk['references_column']}", "t.{$fk['column']}");
                })
                ->select('t.id', "t.{$fk['column']}")
                ->get();

            if ($orphans->isEmpty()) {
                continue;
            }

            $totalOrphans += $orphans->count();
            $this->error(
                "{$fk['table']}.{$fk['column']}: {$orphans->count()} orphaned row(s) reference a "
                ."non-existent {$fk['references_table']}.{$fk['references_column']} — ids: "
                .$orphans->pluck('id')->implode(', ')
            );

            // Only meaningful for user-reference columns (DATA-6:
            // "reassign cases/clients pointing at soft-deleted users") —
            // reassigning e.g. an orphaned court_id to an arbitrary court
            // would silently corrupt data, so this opt-in fix only ever
            // applies to columns that reference the users table.
            if ($reassignTo && $fk['references_table'] === 'users') {
                DB::table($fk['table'])
                    ->whereIn('id', $orphans->pluck('id'))
                    ->update([$fk['column'] => (int) $reassignTo]);

                $this->warn("  -> reassigned to user #{$reassignTo}.");
                $totalOrphans -= $orphans->count();
            }
        }

        if ($totalOrphans === 0) {
            $this->info('No orphaned foreign keys found.');

            return CommandAlias::SUCCESS;
        }

        $this->error("{$totalOrphans} orphaned row(s) remain unresolved. Migration must not proceed until these are fixed or explicitly mapped.");

        return CommandAlias::FAILURE;
    }
}
