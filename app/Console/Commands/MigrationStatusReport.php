<?php

namespace App\Console\Commands;

use App\Services\Migration\LegacyMapping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CARRY-4: compare the coded values in the (legacy) production database with config/legacy_mapping.php and write the
 * sign-off sheet. Read-only. Exit 1 when the database holds a value the mapping does not explain.
 *
 *   php artisan migration:status-report --connection=source --signoff=storage/app/status-signoff.md
 */
class MigrationStatusReport extends Command
{
    protected $signature = 'migration:status-report {--connection= : the database to inspect (default: the default connection)} {--signoff= : write the sign-off sheet (Markdown) here}';

    protected $description = 'Legacy status/archive/enum values in the database vs the signed-off mapping (CARRY-4)';

    public function handle(LegacyMapping $mapping): int
    {
        $name = (string) ($this->option('connection') ?: config('database.default'));
        $db = DB::connection($name);
        $rows = $mapping->inventory($db);
        $this->table(['column', 'value', 'rows', 'meaning', 'after the cutover'], array_map(fn ($r) => [
            $r['column'], $r['value'], $r['count'], $r['meaning'] ?? '<error>UNMAPPED</error>', $r['becomes'] ?? '-',
        ], $rows));

        if ($path = $this->option('signoff')) {
            file_put_contents($path, $mapping->signoffSheet($db, $name.' ('.$db->getDatabaseName().')'));
            $this->info("Sign-off sheet written to {$path}.");
        }
        $unmapped = array_filter($rows, fn ($r) => ! $r['mapped']);
        if ($unmapped) {
            $this->error(count($unmapped).' value(s) are not in config/legacy_mapping.php. Find out what each means and add it before the cutover.');

            return self::FAILURE;
        }
        $this->info('Every value is mapped. Have the sheet confirmed and signed by someone who knows the old system.');

        return self::SUCCESS;
    }
}
