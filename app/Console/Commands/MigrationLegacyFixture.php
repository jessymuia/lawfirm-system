<?php

namespace App\Console\Commands;

use App\Services\Migration\LegacyFixture;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CARRY-5: build a practice database shaped like the legacy production system (never point it at a real one: it
 * refuses a database that already has tables), to try the cutover tooling before a production copy is available.
 */
class MigrationLegacyFixture extends Command
{
    protected $signature = 'migration:legacy-fixture {database : name of a new, empty MySQL/MariaDB database to create and fill}
        {--matters=50} {--problems : plant the data problems real legacy data tends to have} {--files= : where to write the documents (default storage/app)}';

    protected $description = 'Create a practice legacy database to rehearse the cutover on (CARRY-5)';

    public function handle(LegacyFixture $fixture): int
    {
        $name = (string) $this->argument('database');
        if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            $this->error('Use letters, digits and underscores for the database name.');

            return self::FAILURE;
        }
        $base = (array) config('database.connections.'.config('database.default'));
        if (! in_array($base['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error('The practice database is created on the MySQL/MariaDB server of the default connection.');

            return self::FAILURE;
        }
        config(['database.connections.legacy_fixture_server' => array_merge($base, ['database' => 'information_schema'])]);
        $server = DB::connection('legacy_fixture_server');
        if ($server->table('information_schema.TABLES')->where('TABLE_SCHEMA', $name)->exists()) {
            $this->error("Database {$name} already has tables: refusing to touch it.");

            return self::FAILURE;
        }
        $server->statement("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config(['database.connections.legacy_fixture' => array_merge($base, ['database' => $name])]);
        $counts = $fixture->build('legacy_fixture', max(1, (int) $this->option('matters')), (bool) $this->option('problems'), $this->option('files') ?: null);
        $this->table(['table', 'rows'], collect($counts)->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->info("Practice legacy database {$name} is ready. Next: php artisan migration:status-report --connection=… and migration:rehearse-cutover --from=…");

        return self::SUCCESS;
    }
}
