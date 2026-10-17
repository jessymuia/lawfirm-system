<?php

namespace App\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * DATA-13: fills a scratch database with a given number of rows per table, so migrations can be timed against volume
 * when no real firm is at hand (CI). The rows are not realistic, only valid: every NOT NULL column gets a value of its
 * type, enum columns one of their values, unique columns distinct values, and foreign keys an existing parent row.
 * What matters for a migration's cost is how many rows it rewrites or scans, and indexes being real.
 */
class VolumeFiller
{
    /** The tables a firm grows in, parents first. */
    public const TABLES = ['users', 'clients', 'cases', 'documents', 'hearings', 'tasks', 'time_entries', 'expenses',
        'invoices', 'invoice_items', 'payments', 'trust_transactions', 'audits'];

    /** @var array<string, array<string, string>> table => column => a valid value (DATA-12 enum columns) */
    private array $enums = [];

    /** @var array<string, true> tables whose parent row is being made (foreign keys can form cycles) */
    private array $making = [];

    /** @return array<string, int> rows inserted per table */
    public function fill(Connection $db, int $rows, int $chunk = 1000): array
    {
        $this->enums = self::enumValues();
        $done = [];
        $schema = $db->getSchemaBuilder();
        foreach (self::TABLES as $table) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            $existing = (int) $db->table($table)->count();
            for ($from = $existing; $from < $rows; $from += $chunk) {
                $db->table($table)->insert($this->rows($db, $table, $from + 1, min($rows, $from + $chunk)));
            }
            $done[$table] = max(0, $rows - $existing);
        }

        return $done;
    }

    /** @return list<array<string, mixed>> rows $from..$to of $table, its foreign keys pointing at existing parents */
    private function rows(Connection $db, string $table, int $from, int $to): array
    {
        $schema = $db->getSchemaBuilder();
        $parents = [];
        $this->making[$table] = true;
        foreach ($schema->getForeignKeys($table) as $fk) {
            $parents[$fk['columns'][0]] = $this->parentId($db, $fk['foreign_table'], $fk['foreign_columns'][0] ?? 'id');
        }
        unset($this->making[$table]);
        $columns = $schema->getColumns($table);
        $unique = collect($schema->getIndexes($table))->filter(fn ($i) => $i['unique'] && ! $i['primary'])->flatMap(fn ($i) => $i['columns'])->all();
        $out = [];
        for ($n = $from; $n <= $to; $n++) {
            $row = $this->row($table, $columns, $unique, $n);
            foreach ($parents as $column => $id) {
                if ($id === null) {
                    unset($row[$column]);   // a cycle: left null (nullable on one side of every real cycle)
                } elseif (array_key_exists($column, $row) || ! collect($columns)->firstWhere('name', $column)['nullable']) {
                    $row[$column] = $id;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /** An existing row of the parent table (one is created, recursively, if it has none); null inside a cycle. */
    private function parentId(Connection $db, string $table, string $key): mixed
    {
        $id = $db->table($table)->min($key);
        if ($id === null && ! isset($this->making[$table])) {
            $db->table($table)->insert($this->rows($db, $table, 1, 1));
            $id = $db->table($table)->min($key);
        }

        return $id;
    }

    /** @return array<string, array<string, string>> */
    private static function enumValues(): array
    {
        $out = [];
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (class_exists($class) && defined("{$class}::ENUM_COLUMNS")) {
                foreach ($class::ENUM_COLUMNS as $column => $enum) {
                    $out[(new $class)->getTable()][$column] = $enum::cases()[0]->value;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $columns  Schema::getColumns()
     * @param  list<string>  $unique
     * @return array<string, mixed>
     */
    private function row(string $table, array $columns, array $unique, int $n): array
    {
        $row = [];
        foreach ($columns as $c) {
            $name = $c['name'];
            if ($c['auto_increment'] || ($name === 'id' && str_contains(strtolower((string) $c['type_name']), 'int'))) {
                continue;
            }
            if ($c['nullable'] && ! in_array($name, $unique, true) && ! in_array($name, ['deleted_at'], true)) {
                if ($name !== 'created_at' && $name !== 'updated_at') {
                    continue;
                }
            }
            if ($name === 'deleted_at' || $name === 'archived_at') {
                continue;   // live rows
            }
            $row[$name] = $this->enums[$table][$name] ?? $this->value($c, in_array($name, $unique, true), $n);
        }

        return $row;
    }

    private function value(array $c, bool $unique, int $n): mixed
    {
        $type = strtolower((string) $c['type_name']);
        $full = strtolower((string) $c['type']);
        if (preg_match("/^enum\\('([^']*)'/", $full, $m)) {
            return $m[1];
        }
        if (str_ends_with($c['name'], '_id') || in_array($c['name'], ['assigned_to', 'created_by', 'updated_by', 'received_by', 'recorded_by', 'advocate_id', 'user_id'], true)) {
            return $unique ? $n : 1;
        }

        if ($c['name'] === 'currency' || str_ends_with($c['name'], '_currency')) {
            return 'KES';
        }
        $string = $unique ? 'v'.$n.'-'.Str::random(6) : 'Volume row '.$n;
        if (preg_match('/char\((\d+)\)/', $full, $len)) {
            $string = $unique ? substr(str_pad((string) $n, (int) $len[1], '0', STR_PAD_LEFT), -(int) $len[1]) : substr($string, 0, (int) $len[1]);
        }

        return match (true) {
            str_contains($type, 'int') => $unique ? $n : (str_contains($type, 'tinyint') ? 0 : 1),
            in_array($type, ['decimal', 'numeric', 'double', 'float', 'real'], true) => '100.00',
            in_array($type, ['date'], true) => '2025-01-01',
            in_array($type, ['datetime', 'timestamp'], true) => '2025-01-01 09:00:00',
            in_array($type, ['time'], true) => '09:00:00',
            in_array($type, ['json'], true) => '[]',
            in_array($type, ['bool', 'boolean'], true) => 0,
            default => $string,
        };
    }
}
