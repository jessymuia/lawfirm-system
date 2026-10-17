<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Process\Process;

class MigrationExport extends Command
{
    /**
     * §6.3 Phase 1 (Export). Run in the OLD system before cutover.
     * Produces, under --output=:
     *   - one {table}.json per entity in MIGRATED_TABLES, containing
     *     every row via a raw DB::table() query (no Eloquent global
     *     scope applied, so soft-deleted rows are included — matching
     *     §6.3 Phase 3's "row counts ... including soft-deleted rows"
     *     requirement on the *destination* side, which only means
     *     something if the *source* export also captured them);
     *   - a full mysqldump as belt-and-braces (§6.3 explicitly asks for
     *     both — the JSON is what Phase 2 actually imports from, the
     *     dump is a raw fallback if the JSON transform needs to be
     *     redone or re-verified against the literal source);
     *   - the document checksum manifest (delegates to the existing
     *     migration:document-checksums command rather than
     *     duplicating that logic).
     */
    protected $signature = 'migration:export {--output= : Directory to write the export into}';

    protected $description = '[legacy one-off, see portability:export] Phase 1: export every migrated table to JSON, plus a mysqldump and document checksum manifest (§6.3)';

    /** Same list as tests/Migration/MigrationVerificationTest.php's MIGRATED_TABLES — kept in sync manually since one is PHPUnit and one is a console command with a different lifecycle. */
    private const MIGRATED_TABLES = [
        'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'court_types', 'courts', 'hearing_types', 'expense_categories',
        'clients', 'cases', 'attorneys', 'hearings', 'tasks',
        'invoices', 'invoice_items', 'expenses', 'payments', 'time_entries', 'trust_transactions',
        'documents', 'document_accesses', 'audits',
    ];

    public function handle(): int
    {
        $outputDir = $this->option('output');
        if (! $outputDir) {
            $this->error('Pass --output=/path/to/export/dir');

            return CommandAlias::FAILURE;
        }

        if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
            $this->error("Could not create output directory: {$outputDir}");

            return CommandAlias::FAILURE;
        }

        $this->exportTablesToJson($outputDir);
        $this->runMysqldump($outputDir);
        $this->exportDocumentChecksums($outputDir);

        $this->info("Export complete: {$outputDir}");

        return CommandAlias::SUCCESS;
    }

    private function exportTablesToJson(string $outputDir): void
    {
        $jsonDir = $outputDir.'/tables';
        if (! is_dir($jsonDir)) {
            mkdir($jsonDir, 0755, true);
        }

        foreach (self::MIGRATED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn("Skipping {$table}: does not exist in this database.");

                continue;
            }

            $rows = DB::table($table)->orderBy(
                Schema::hasColumn($table, 'id') ? 'id' : DB::raw('1')
            )->get();

            file_put_contents(
                "{$jsonDir}/{$table}.json",
                $rows->toJson(JSON_PRETTY_PRINT)
            );

            $this->info("Exported {$table}: {$rows->count()} row(s).");
        }
    }

    private function runMysqldump(string $outputDir): void
    {
        $dumpPath = $outputDir.'/full-dump.sql';

        $process = new Process([
            'mysqldump',
            '--host='.config('database.connections.mysql.host'),
            '--port='.config('database.connections.mysql.port'),
            '--user='.config('database.connections.mysql.username'),
            '--single-transaction',
            '--no-tablespaces',
            config('database.connections.mysql.database'),
        ]);
        $process->setEnv(['MYSQL_PWD' => config('database.connections.mysql.password')]);
        $process->setTimeout(3600);

        try {
            $process->mustRun();
            file_put_contents($dumpPath, $process->getOutput());
            $this->info("mysqldump written to {$dumpPath}.");
        } catch (\Throwable $e) {
            // The JSON export above is the source of truth Phase 2 actually
            // imports from — the mysqldump is explicitly "belt-and-braces"
            // per §6.3, so a failure here (e.g. mysqldump not installed on
            // this host) is a warning, not a reason to abort the whole
            // export.
            $this->warn('mysqldump failed (continuing — the JSON export above is unaffected): '.$e->getMessage());
        }
    }

    private function exportDocumentChecksums(string $outputDir): void
    {
        $manifestPath = $outputDir.'/document-checksums.json';
        $exitCode = Artisan::call('migration:document-checksums', ['--manifest' => $manifestPath]);

        if ($exitCode === CommandAlias::SUCCESS) {
            $this->info("Document checksum manifest written to {$manifestPath}.");
        } else {
            $this->warn('Document checksum manifest generation reported problems: '.Artisan::output());
        }
    }
}
