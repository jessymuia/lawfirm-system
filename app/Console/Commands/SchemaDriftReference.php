<?php

namespace App\Console\Commands;

use App\Tenancy\SchemaDrift;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * TEN-8, internal: run by SchemaDrift in a child process. Migrates a blank scratch database with the given migration
 * files and prints its schema fingerprint as JSON on stdout. Not for operators; use `schema:drift`.
 */
class SchemaDriftReference extends Command
{
    protected $signature = 'schema:drift-reference {--driver=} {--database=} {--path=}';

    protected $description = 'Internal: build a reference schema and print its fingerprint (used by schema:drift)';

    protected $hidden = true;

    public function handle(): int
    {
        // The parent passes the server settings of the database being checked (TEN-4: its cell) in the environment.
        $server = (array) json_decode((string) base64_decode((string) getenv('SCHEMA_DRIFT_SERVER')), true);
        $config = $this->option('driver') === 'sqlite'
            ? ['driver' => 'sqlite', 'database' => $this->option('database'), 'prefix' => '', 'foreign_key_constraints' => true]
            : array_merge($server, ['database' => $this->option('database')]);
        config(['database.connections.'.SchemaDrift::REF_CONNECTION => $config]);
        DB::setDefaultConnection(SchemaDrift::REF_CONNECTION);   // migrations that use DB:: / Schema:: without naming a connection

        $exit = Artisan::call('migrate', ['--database' => SchemaDrift::REF_CONNECTION, '--path' => $this->option('path'), '--realpath' => true, '--force' => true]);
        if ($exit !== 0) {
            $this->getOutput()->getErrorStyle()->writeln(Artisan::output());

            return self::FAILURE;
        }
        $this->line((string) json_encode(SchemaDrift::fingerprint(SchemaDrift::REF_CONNECTION)));

        return self::SUCCESS;
    }
}
