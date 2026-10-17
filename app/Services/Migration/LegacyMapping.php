<?php

namespace App\Services\Migration;

use Illuminate\Database\Connection;

/**
 * CARRY-4: every coded value actually present in a legacy database, set against config/legacy_mapping.php.
 *
 * Columns are found from the schema, not from a list: every table's generic `status`/`archive` flags, plus the
 * specific columns the mapping names. Soft-deleted rows are counted too (they migrate as well). A value with no entry
 * in the mapping is "unmapped": the cutover must not run until someone decides what it means.
 */
class LegacyMapping
{
    /**
     * @return list<array{column: string, value: string, count: int, meaning: ?string, becomes: ?string, mapped: bool}>
     */
    public function inventory(Connection $db): array
    {
        $rows = [];
        foreach ($this->columns($db) as [$table, $column, $key]) {
            $spec = (array) (((array) config('legacy_mapping.columns'))[$key]['values'] ?? []);   // keys contain dots: no config() dot lookup
            $counts = $db->table($table)->selectRaw("{$db->getQueryGrammar()->wrap($column)} as v, count(*) as n")->groupBy($column)->orderBy($column)->get();
            foreach ($counts as $c) {
                $value = $c->v === null ? 'NULL' : (string) $c->v;
                $known = $spec[$value] ?? null;
                $rows[] = ['column' => "{$table}.{$column}", 'value' => $value, 'count' => (int) $c->n,
                    'meaning' => $known['meaning'] ?? null, 'becomes' => $known['becomes'] ?? null, 'mapped' => $known !== null];
            }
        }

        return $rows;
    }

    /** @return list<array{column: string, value: string, count: int, meaning: ?string, becomes: ?string, mapped: bool}> */
    public function unmapped(Connection $db): array
    {
        return array_values(array_filter($this->inventory($db), fn ($r) => ! $r['mapped']));
    }

    /** True when the database still has the legacy invoice status and has not been upgraded (no workflow_status yet). */
    public static function isLegacy(Connection $db): bool
    {
        $schema = $db->getSchemaBuilder();

        return $schema->hasTable('invoices') && $schema->hasColumn('invoices', 'status') && ! $schema->hasColumn('invoices', 'workflow_status');
    }

    /** The sign-off sheet (Markdown) for docs/ops/MIGRATION_STATUS_MAPPING.md. */
    public function signoffSheet(Connection $db, string $source): string
    {
        $inv = $this->inventory($db);
        $unmapped = array_filter($inv, fn ($r) => ! $r['mapped']);
        $out = "# Legacy status mapping: sign-off sheet\n\n";
        $out .= "Database: `{$source}` · Generated: ".now()->toDateTimeString()." · Rows counted include soft-deleted rows.\n\n";
        $out .= $unmapped
            ? '**'.count($unmapped)." value(s) are not in the mapping.** The cutover is blocked until each is explained and added to `config/legacy_mapping.php`.\n\n"
            : "Every value in this database is in the mapping. Confirm each meaning below, then sign.\n\n";
        $out .= "| Column | Value | Rows | Meaning in the old system | After the cutover | Confirmed (initials) |\n|---|---|---|---|---|---|\n";
        foreach ($inv as $r) {
            $out .= sprintf("| `%s` | `%s` | %d | %s | %s | %s |\n", $r['column'], $r['value'], $r['count'],
                $r['meaning'] ?? '**UNMAPPED: what does this mean?**', $r['becomes'] ?? '**decide**', '');
        }
        $questions = [];
        foreach ((array) config('legacy_mapping.columns') as $key => $spec) {
            if (! empty($spec['question'])) {
                $questions[] = "- `{$key}`: {$spec['question']}";
            }
        }
        $out .= "\n## Questions to answer\n\n".implode("\n", $questions)."\n";
        $out .= "\n## Signed\n\nName: ______________________  Role: ______________________  Date: __________\n\n";
        $out .= "Knows the old system because: ______________________________________________\n";

        return $out;
    }

    /** @return list<array{0: string, 1: string, 2: string}> table, column, mapping key */
    private function columns(Connection $db): array
    {
        $schema = $db->getSchemaBuilder();
        $specific = (array) config('legacy_mapping.specific_status_tables', []);
        $out = [];
        $own = in_array($db->getDriverName(), ['mysql', 'mariadb'], true) ? $db->getDatabaseName() : null;   // MySQL lists every schema otherwise
        foreach ($schema->getTableListing($own, false) as $table) {
            foreach (['status', 'archive'] as $flag) {
                // the generic flags are small integers; a text `status` (a newer table's own states) is not legacy coding
                if (! $schema->hasColumn($table, $flag) || ! in_array(strtolower($schema->getColumnType($table, $flag)), ['tinyint', 'smallint', 'int', 'integer', 'bigint', 'boolean'], true)) {
                    continue;
                }
                if (array_key_exists("{$table}.{$flag}", (array) config('legacy_mapping.columns'))) {
                    $out[] = [$table, $flag, "{$table}.{$flag}"];
                } elseif (! ($flag === 'status' && in_array($table, $specific, true))) {
                    $out[] = [$table, $flag, "*.{$flag}"];
                }
            }
        }
        foreach (array_keys((array) config('legacy_mapping.columns')) as $key) {
            [$table, $column] = explode('.', $key, 2);
            if ($table !== '*' && ! in_array($column, ['status', 'archive'], true) && $schema->hasTable($table) && $schema->hasColumn($table, $column)) {
                $out[] = [$table, $column, $key];
            }
        }

        return $out;
    }
}
