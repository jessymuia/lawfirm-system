<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * TEN-8: does a firm's database still have exactly the schema its migrations say it should?
 *
 * Drift happens: a migration that half-failed, a hand edit by support, a self-hosted customer who "fixed" something.
 * Undetected, it makes the next migration fail unpredictably. The expected schema is not written down anywhere: it is
 * whatever the firm's own applied migrations produce on a blank database of the same engine. So:
 *
 *   1. read the migrations the firm has applied (a lagging firm is compared with ITS version, not the newest);
 *   2. build a scratch reference database with exactly those migrations (cached per engine + migration set);
 *   3. compare fingerprints: tables, columns (type, nullability), indexes, foreign keys, triggers (DATA-10 ledger
 *      guards are part of the schema), and migrations applied vs. present in the code.
 */
class SchemaDrift
{
    public const REF_CONNECTION = 'schema_drift_reference';

    /**
     * @return list<string> differences, empty when the schema is exactly as expected
     */
    public function check(string $connection): array
    {
        $applied = Schema::connection($connection)->hasTable('migrations')
            ? DB::connection($connection)->table('migrations')->orderBy('id')->pluck('migration')->all()
            : [];
        $files = $this->migrationFiles();

        $problems = [];
        foreach (array_diff($applied, array_keys($files)) as $m) {
            $problems[] = "migration recorded but not in the code: {$m}";
        }
        $driver = DB::connection($connection)->getDriverName();
        // TEN-4: the reference is built on the same server (cell) as the database being checked.
        $server = (array) config("database.connections.{$connection}");
        unset($server['database'], $server['url']);
        $expected = $this->reference($driver, array_values(array_intersect($applied, array_keys($files))), $files, $server);

        return array_merge($problems, $this->diff($expected, self::fingerprint($connection)));
    }

    /** @return array<string, array{columns: array<string,string>, indexes: list<string>, foreign: list<string>}>|array{__triggers: list<string>} */
    public static function fingerprint(string $connection): array
    {
        $schema = Schema::connection($connection);
        $db = DB::connection($connection);
        // MySQL/MariaDB list every database the user can see (other firms' included): only this connection's own.
        $own = in_array($db->getDriverName(), ['mysql', 'mariadb'], true) ? $db->getDatabaseName() : null;
        $out = [];
        foreach ($schema->getTables() as $t) {
            if ($own !== null && ($t['schema'] ?? $own) !== $own) {
                continue;
            }
            $name = $t['name'];
            if (str_starts_with($name, 'sqlite_')) {
                continue;
            }
            $columns = [];
            foreach ($schema->getColumns($name) as $c) {
                $columns[$c['name']] = strtolower($c['type']).($c['nullable'] ? ' null' : ' not null');
            }
            ksort($columns);
            $indexes = array_map(fn ($i) => ($i['primary'] ? 'primary' : ($i['unique'] ? 'unique' : 'index')).'('.implode(',', $i['columns']).')', $schema->getIndexes($name));
            sort($indexes);
            $foreign = array_map(fn ($f) => '('.implode(',', $f['columns']).')->'.$f['foreign_table'].'('.implode(',', $f['foreign_columns']).')', $schema->getForeignKeys($name));
            sort($foreign);
            $out[$name] = ['columns' => $columns, 'indexes' => $indexes, 'foreign' => $foreign];
        }
        ksort($out);
        $out['__triggers'] = self::triggers($connection);

        return $out;
    }

    /** @return list<string> */
    private static function triggers(string $connection): array
    {
        $db = DB::connection($connection);
        $names = match ($db->getDriverName()) {
            'sqlite' => $db->table('sqlite_master')->where('type', 'trigger')->pluck('name')->all(),
            'mysql', 'mariadb' => array_map(fn ($r) => $r->TRIGGER_NAME, $db->select('select TRIGGER_NAME from information_schema.TRIGGERS where TRIGGER_SCHEMA = database()')),
            'pgsql' => array_map(fn ($r) => $r->tgname, $db->select('select tgname from pg_trigger where not tgisinternal')),
            default => [],
        };
        sort($names);

        return $names;
    }

    /** @return list<string> */
    public function diff(array $expected, array $actual): array
    {
        $out = [];
        $et = $expected['__triggers'] ?? [];
        $at = $actual['__triggers'] ?? [];
        unset($expected['__triggers'], $actual['__triggers']);

        foreach (array_diff_key($expected, $actual) as $t => $_) {
            $out[] = "missing table {$t}";
        }
        foreach (array_diff_key($actual, $expected) as $t => $_) {
            $out[] = "unexpected table {$t}";
        }
        foreach (array_intersect_key($expected, $actual) as $t => $want) {
            $have = $actual[$t];
            foreach ($want['columns'] as $col => $def) {
                if (! isset($have['columns'][$col])) {
                    $out[] = "{$t}: missing column {$col}";
                } elseif ($have['columns'][$col] !== $def) {
                    $out[] = "{$t}.{$col}: is {$have['columns'][$col]}, expected {$def}";
                }
            }
            foreach (array_diff_key($have['columns'], $want['columns']) as $col => $_) {
                $out[] = "{$t}: unexpected column {$col}";
            }
            foreach (['indexes' => 'index', 'foreign' => 'foreign key'] as $k => $label) {
                foreach (array_diff($want[$k], $have[$k]) as $x) {
                    $out[] = "{$t}: missing {$label} {$x}";
                }
                foreach (array_diff($have[$k], $want[$k]) as $x) {
                    $out[] = "{$t}: unexpected {$label} {$x}";
                }
            }
        }
        foreach (array_diff($et, $at) as $x) {
            $out[] = "missing trigger {$x}";
        }
        foreach (array_diff($at, $et) as $x) {
            $out[] = "unexpected trigger {$x}";
        }

        return $out;
    }

    /** @return array<string,string> migration name => file path, tenant (app) migrations only */
    private function migrationFiles(): array
    {
        return app('migrator')->getMigrationFiles([database_path('migrations')]);
    }

    /**
     * Fingerprint of a blank database of this engine after exactly these migrations. Built once per (engine, migration
     * set) and cached on disk, so a fleet at one version builds one reference.
     *
     * @param  list<string>  $migrations
     * @param  array<string,string>  $files
     * @param  array<string,mixed>  $server  connection settings of the server to build it on, without a database
     */
    private function reference(string $driver, array $migrations, array $files, array $server): array
    {
        // Names and contents: an edited migration must never be compared against a stale reference.
        $key = $driver.'-'.sha1(implode("\n", array_map(fn ($m) => $m.':'.sha1_file($files[$m]), $migrations)));
        $cache = storage_path("framework/schema/{$key}.json");
        if (is_file($cache)) {
            return json_decode((string) file_get_contents($cache), true);
        }

        $dir = storage_path('framework/schema/migrations-'.$key);
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir);
        foreach ($migrations as $m) {
            copy($files[$m], "{$dir}/{$m}.php");
        }

        $db = $this->createReferenceDatabase($driver, substr(sha1($key), 0, 12), $server);
        try {
            // A separate PHP process: several old migrations declare named classes, which cannot be loaded twice in
            // the process that already ran them.
            // the server settings (credentials included) go through the environment, never the command line
            $p = new Process([PHP_BINARY, base_path('artisan'), 'schema:drift-reference', '--driver='.$driver, '--database='.$db, '--path='.$dir],
                base_path(), ['SCHEMA_DRIFT_SERVER' => base64_encode((string) json_encode($server))], null, 600);
            $p->run();
            $fingerprint = json_decode(trim($p->getOutput()), true);
            if (! $p->isSuccessful() || ! is_array($fingerprint)) {
                throw new \RuntimeException('Could not build the reference schema: '.mb_substr(trim($p->getErrorOutput().' '.$p->getOutput()), 0, 1000));
            }
        } finally {
            $this->dropReferenceDatabase($driver, $db);
            File::deleteDirectory($dir);
        }

        File::ensureDirectoryExists(dirname($cache));
        file_put_contents($cache, json_encode($fingerprint));

        return $fingerprint;
    }

    private function createReferenceDatabase(string $driver, string $suffix, array $server): string
    {
        if ($driver === 'sqlite') {
            $file = storage_path("framework/schema/reference-{$suffix}.sqlite");
            @unlink($file);
            touch($file);

            return $file;
        }

        $name = 'schema_ref_'.$suffix;
        config(['database.connections.schema_drift_admin' => $server + ['database' => null]]);
        DB::purge('schema_drift_admin');
        DB::connection('schema_drift_admin')->statement("DROP DATABASE IF EXISTS `{$name}`");
        DB::connection('schema_drift_admin')->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        return $name;
    }

    private function dropReferenceDatabase(string $driver, string $db): void
    {
        if ($driver === 'sqlite') {
            @unlink($db);

            return;
        }
        DB::connection('schema_drift_admin')->statement("DROP DATABASE IF EXISTS `{$db}`");
        DB::purge('schema_drift_admin');
    }
}
