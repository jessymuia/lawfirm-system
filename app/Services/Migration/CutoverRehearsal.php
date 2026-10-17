<?php

namespace App\Services\Migration;

use App\Models\Cases;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * CARRY-5 (MIG-6): the legacy cutover, rehearsed end to end on a copy, never on the source.
 *
 *  1. restore    a copy of the legacy database (a dump file, or straight from a connection) into a scratch database;
 *  2. preflight  the coded values against the signed-off mapping (unmapped values stop it), orphaned references,
 *                duplicates the upgrade will rename, line totals that do not add up, document files that are missing;
 *  3. upgrade    `php artisan app:upgrade` against the copy: the same command the cutover runs, backfills included;
 *  4. verify     nothing lost or changed that should not be: rows per table, invoice states as the mapping says,
 *                money to the cent, password hashes, document files, the application reading its own data, no pending
 *                migrations;
 *  5. report     every phase timed, every finding listed, an estimate of the cutover window.
 *
 * The source is only ever read. The scratch database is dropped afterwards unless kept to inspect.
 */
class CutoverRehearsal
{
    public const CONNECTION = 'cutover_rehearsal';

    /** @var array<string, float> */
    private array $timings = [];

    /**
     * @param  array{dump?: ?string, from?: ?string, files?: ?string, keep?: bool, accept_unmapped?: bool}  $opts
     * @return array<string, mixed> the report
     */
    public function run(array $opts): array
    {
        $template = $this->template($opts['from'] ?? null);
        $scratch = 'cutover_'.date('Ymd_His');
        $filesRoot = rtrim((string) ($opts['files'] ?? storage_path('app')), '/');
        $blocking = $warnings = [];
        $before = $after = $preflight = $verify = [];
        $slowest = [];
        $original = config('database.default');
        $started = microtime(true);

        try {
            $this->timed('restore', function () use ($template, $scratch, $opts) {
                $this->server($template)->statement("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $into = 'mysql '.$this->auth($template).' '.escapeshellarg($scratch);
                if (! empty($opts['dump'])) {
                    $dump = (string) $opts['dump'];
                    is_file($dump) || throw new RuntimeException("No dump file at {$dump}.");
                    $this->shell((str_ends_with($dump, '.gz') ? 'gunzip -c ' : 'cat ').escapeshellarg($dump).' | '.$into, $template);
                } else {
                    $source = (string) ($template['database'] ?? '');
                    $this->shell('mysqldump --single-transaction --no-tablespaces --routines '.$this->auth($template).' '.escapeshellarg($source).' | '.$into, $template);
                }
                config(['database.connections.'.self::CONNECTION => array_merge($template, ['database' => $scratch])]);
                DB::purge(self::CONNECTION);
            });
            $db = DB::connection(self::CONNECTION);
            if (! LegacyMapping::isLegacy($db)) {
                $warnings[] = 'The copy is not a legacy database (invoices already have workflow_status): it was upgraded before, so this rehearses a normal upgrade.';
            }

            $this->timed('preflight', function () use ($db, $filesRoot, $opts, &$preflight, &$blocking, &$warnings, &$before) {
                $preflight['unmapped'] = app(LegacyMapping::class)->unmapped($db);
                foreach ($preflight['unmapped'] as $u) {
                    $msg = "{$u['column']} = {$u['value']} ({$u['count']} rows) is not in the signed-off mapping";
                    empty($opts['accept_unmapped']) ? $blocking[] = $msg : $warnings[] = $msg.' (accepted for this rehearsal)';
                }
                $preflight['orphans'] = $this->orphans($db);
                foreach ($preflight['orphans'] as $o) {
                    $warnings[] = "{$o['count']} orphaned {$o['fk']} reference(s): the upgrade's foreign keys may refuse them";
                }
                $preflight['duplicates'] = $this->duplicates($db);
                foreach ($preflight['duplicates'] as $d) {
                    $warnings[] = "{$d['column']}: {$d['rows']} row(s) share a value with another; the upgrade renames all but the oldest with a -DUP suffix";
                }
                $preflight['line_mismatches'] = $this->lineMismatches($db);
                $preflight['line_mismatches'] && $warnings[] = count($preflight['line_mismatches']).' invoice line(s) whose total is not quantity × rate + tax (ids '.implode(', ', array_slice($preflight['line_mismatches'], 0, 10)).'): carried as recorded';
                $preflight['documents_missing'] = $this->missingFiles($db, $filesRoot);
                $preflight['documents_missing'] && $warnings[] = count($preflight['documents_missing']).' document row(s) whose file is not under '.$filesRoot.' (ids '.implode(', ', array_slice($preflight['documents_missing'], 0, 10)).')';
                $before = $this->snapshot($db);
            });
            if ($blocking) {
                throw new RuntimeException('Stopped before the upgrade: '.implode('; ', $blocking));
            }

            $this->timed('upgrade', function () use ($opts, &$slowest) {
                config(['database.default' => self::CONNECTION, 'queue.default' => 'sync']);   // backfills run inline, as on a quiet cutover
                DB::setDefaultConnection(self::CONNECTION);
                // its own buffer: commands app:upgrade runs inside replace Artisan::output()
                $buffer = new BufferedOutput;
                $code = Artisan::call('app:upgrade', ['--skip-cache' => true, '--accept-legacy-values' => ! empty($opts['accept_unmapped'])], $buffer);
                $out = $buffer->fetch();
                // migrate prints "<migration> .... 74.50ms DONE" or "6s DONE": the schema steps are the downtime; the backfills
                // queued afterwards (run inline here) are not, on a real cutover they finish while people work
                preg_match_all('/^\s*(\d{4}_\d{2}_\d{2}_\d{6}_\S+)\s+\.*\s*([\d,.]+)\s*(ms|s)\s+DONE/m', $out, $m, PREG_SET_ORDER);
                foreach ($m as $x) {
                    $slowest[$x[1]] = round((float) str_replace(',', '', $x[2]) / ($x[3] === 'ms' ? 1000 : 1), 2);
                }
                arsort($slowest);
                if ($code !== 0) {
                    throw new RuntimeException('app:upgrade failed on the copy: '.trim(mb_substr($out, -1500)));
                }
            });

            $this->timed('verify', function () use ($db, $filesRoot, $before, &$after, &$verify, &$blocking) {
                $after = $this->snapshot($db);
                foreach ($before['rows'] as $table => $n) {
                    $now = $after['rows'][$table] ?? null;
                    if (in_array($table, LegacyMigratedTables::CATALOG, true) && $now !== null && $now >= $n) {
                        $now > $n && $verify['catalog_added'][$table] = $now - $n;   // the permission and system catalogs (TEN-11) add their rows
                    } elseif ($now !== $n) {
                        $blocking[] = "{$table}: {$n} rows before the upgrade, ".($now ?? 'none').' after';
                    }
                }
                $expected = $this->expectedWorkflow($before['invoice_status'] ?? []);
                $verify['invoice_states'] = ['expected' => $expected, 'actual' => $after['workflow'] ?? []];
                if ($expected && $expected != ($after['workflow'] ?? [])) {
                    $blocking[] = 'invoice states after the upgrade ('.json_encode($after['workflow'] ?? []).') are not what the mapping says ('.json_encode($expected).')';
                }
                if (abs(($before['money']['lines'] ?? 0) - ($after['money']['lines'] ?? 0)) > 0.005 * max(1, $before['rows']['invoice_items'] ?? 1)) {
                    $blocking[] = 'invoice line totals changed: '.$before['money']['lines'].' before, '.$after['money']['lines'].' after';
                }
                if (abs(($before['money']['expenses'] ?? 0) - ($after['money']['expenses'] ?? 0)) > 0.005 * max(1, $before['rows']['expenses'] ?? 1)) {
                    $blocking[] = 'expense amounts changed: '.$before['money']['expenses'].' before, '.$after['money']['expenses'].' after';
                }
                $verify['invoice_totals_off'] = $this->invoiceTotalsOff($db);
                $verify['invoice_totals_off'] && $blocking[] = count($verify['invoice_totals_off']).' invoice(s) whose stored totals do not match their lines (ids '.implode(', ', array_slice($verify['invoice_totals_off'], 0, 10)).')';
                if ($before['passwords'] !== $after['passwords']) {
                    $blocking[] = 'password hashes changed: people could not sign in';
                }
                $verify['documents_missing'] = $this->missingFiles($db, $filesRoot);
                try {
                    User::on(self::CONNECTION)->first();
                    Cases::on(self::CONNECTION)->with('client')->latest('id')->first();
                    Invoice::on(self::CONNECTION)->with('items')->latest('id')->first();
                    $verify['app_reads_data'] = true;
                } catch (\Throwable $e) {
                    $verify['app_reads_data'] = false;
                    $blocking[] = 'the application cannot read the upgraded data: '.mb_substr($e->getMessage(), 0, 300);
                }
                $repo = app('migration.repository');
                $repo->setSource(self::CONNECTION);
                $verify['pending_migrations'] = count(array_diff(array_keys(app('migrator')->getMigrationFiles([database_path('migrations')])), $repo->getRan()));
                $verify['pending_migrations'] && $blocking[] = $verify['pending_migrations'].' migration(s) still pending after the upgrade';
                $verify['renamed_case_numbers'] = $db->table('cases')->where('case_number', 'like', '%-DUP%')->count();
                $verify['renamed_client_phones'] = $db->table('clients')->where('phone_number', 'like', '%-DUP%')->count();
            });
        } catch (\Throwable $e) {
            $blocking[] = $e->getMessage();
        } finally {
            config(['database.default' => $original]);
            DB::setDefaultConnection($original);
            if (empty($opts['keep'])) {
                DB::purge(self::CONNECTION);
                try {
                    $this->server($template)->statement("DROP DATABASE IF EXISTS `{$scratch}`");
                } catch (\Throwable) {
                    // nothing was created
                }
            }
        }
        $this->timings['total'] = round(microtime(true) - $started, 2);
        $blocking = array_values(array_unique($blocking));

        return [
            'ok' => $blocking === [], 'at' => now()->toIso8601String(), 'source' => $opts['dump'] ?? ($opts['from'] ?? 'source'),
            'scratch_database' => $scratch, 'kept' => ! empty($opts['keep']), 'timings' => $this->timings,
            // the downtime: restore the final dump, run the schema migrations, check; queued backfills run after reopening
            'cutover_window_seconds' => round(($this->timings['restore'] ?? 0) + array_sum($slowest) + ($this->timings['verify'] ?? 0), 1),
            'migrations_seconds' => round(array_sum($slowest), 1), 'backfills_seconds' => round(max(0, ($this->timings['upgrade'] ?? 0) - array_sum($slowest)), 1), 'slowest_migrations_seconds' => array_slice($slowest, 0, 8, true), 'blocking' => $blocking, 'warnings' => $warnings, 'before' => $before, 'after' => $after, 'preflight' => $preflight, 'verify' => $verify,
        ];
    }

    /** @return array{rows: array<string, int>, invoice_status?: array<string, int>, workflow?: array<string, int>, money: array<string, float>, passwords: string} */
    private function snapshot(Connection $db): array
    {
        $schema = $db->getSchemaBuilder();
        $rows = [];
        foreach (LegacyMigratedTables::TABLES as $t) {
            $schema->hasTable($t) && $rows[$t] = (int) $db->table($t)->count();
        }
        $out = ['rows' => $rows, 'money' => [
            'lines' => round((float) $db->table('invoice_items')->sum('total_amount'), 2),
            'expenses' => round((float) $db->table('expenses')->sum('amount'), 2),
        ]];
        if ($schema->hasColumn('invoices', 'status') && LegacyMapping::isLegacy($db)) {
            $out['invoice_status'] = $db->table('invoices')->selectRaw('status as s, count(*) as n')->groupBy('status')->pluck('n', 's')->map(fn ($n) => (int) $n)->all();
        }
        if ($schema->hasColumn('invoices', 'workflow_status')) {
            $out['workflow'] = $db->table('invoices')->selectRaw('workflow_status as s, count(*) as n')->groupBy('workflow_status')->orderBy('workflow_status')->pluck('n', 's')->map(fn ($n) => (int) $n)->all();
        }
        $out['passwords'] = hash('sha256', $db->table('users')->orderBy('id')->pluck('password', 'email')->toJson());

        return $out;
    }

    /** @param  array<int|string, int>  $legacy  @return array<string, int> */
    private function expectedWorkflow(array $legacy): array
    {
        $map = [1 => 'submitted', 3 => 'draft', 4 => 'void', 5 => 'void'];
        $out = [];
        foreach ($legacy as $status => $n) {
            $state = $map[(int) $status] ?? 'draft';   // the upgrade's default for anything else (which the mapping gate stops first)
            $out[$state] = ($out[$state] ?? 0) + $n;
        }
        ksort($out);

        return $out;
    }

    /** @return list<array{fk: string, count: int}> */
    private function orphans(Connection $db): array
    {
        $schema = $db->getSchemaBuilder();
        $out = [];
        foreach (ForeignKeyRegistry::all() as $fk) {
            if (! $schema->hasTable($fk['table']) || ! $schema->hasTable($fk['references_table'])) {
                continue;
            }
            $n = $db->table($fk['table'].' as t')->whereNotNull("t.{$fk['column']}")
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($fk['references_table'].' as r')->whereColumn("r.{$fk['references_column']}", "t.{$fk['column']}"))
                ->count();
            $n && $out[] = ['fk' => "{$fk['table']}.{$fk['column']} → {$fk['references_table']}", 'count' => $n];
        }

        return $out;
    }

    /** @return list<array{column: string, rows: int}> */
    private function duplicates(Connection $db): array
    {
        $out = [];
        foreach ([['cases', 'case_number'], ['clients', 'phone_number']] as [$t, $c]) {
            $dupes = $db->table($t)->whereNull('deleted_at')->select($c)->groupBy($c)->havingRaw('count(*) > 1')->pluck($c);
            $renamed = 0;
            foreach ($dupes as $v) {
                $renamed += $db->table($t)->whereNull('deleted_at')->where($c, $v)->count() - 1;
            }
            $renamed && $out[] = ['column' => "{$t}.{$c}", 'rows' => $renamed];
        }

        return $out;
    }

    /** @return list<int> */
    private function lineMismatches(Connection $db): array
    {
        return $db->table('invoice_items')->whereNull('deleted_at')
            ->whereRaw('abs(round(quantity * rate + tax, 2) - round(total_amount, 2)) > 0.01')->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** @return list<int> */
    private function invoiceTotalsOff(Connection $db): array
    {
        $sums = $db->table('invoice_items')->whereNull('deleted_at')->groupBy('invoice_id')
            ->selectRaw('invoice_id, round(sum(total_amount), 2) as total')->pluck('total', 'invoice_id');
        $off = [];
        foreach ($db->table('invoices')->whereNull('deleted_at')->get(['id', 'total_amount']) as $inv) {
            if (abs((float) $inv->total_amount - (float) ($sums[$inv->id] ?? 0)) > 0.01) {
                $off[] = (int) $inv->id;
            }
        }

        return $off;
    }

    /** @return list<int> */
    private function missingFiles(Connection $db, string $root): array
    {
        return $db->table('documents')->whereNull('deleted_at')->get(['id', 'full_path'])
            ->filter(fn ($d) => ! is_file($root.'/'.ltrim((string) $d->full_path, '/')))->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
    }

    /** @return array<string, mixed> the source (or default MySQL) connection's settings */
    private function template(?string $from): array
    {
        $name = $from ?: (config('database.connections.source.database') ? 'source' : config('database.default'));
        $config = (array) config("database.connections.{$name}");
        if (! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("The cutover is rehearsed on MySQL/MariaDB; connection [{$name}] is ".($config['driver'] ?? 'not configured').'.');
        }

        return $config;
    }

    private function server(array $conn): Connection
    {
        config(['database.connections.cutover_server' => array_merge($conn, ['database' => 'information_schema'])]);
        DB::purge('cutover_server');

        return DB::connection('cutover_server');
    }

    private function auth(array $conn): string
    {
        return implode(' ', array_map('escapeshellarg', ['--host='.($conn['host'] ?? '127.0.0.1'), '--port='.($conn['port'] ?? 3306), '--user='.($conn['username'] ?? 'root')]));
    }

    private function shell(string $command, array $conn): void
    {
        $p = new Process(['bash', '-o', 'pipefail', '-c', $command], null, ['MYSQL_PWD' => (string) ($conn['password'] ?? '')]);
        $p->setTimeout(7200);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new RuntimeException(trim($p->getErrorOutput()) ?: 'restore failed');
        }
    }

    private function timed(string $phase, \Closure $fn): void
    {
        $t = microtime(true);
        try {
            $fn();
        } finally {
            $this->timings[$phase] = round(microtime(true) - $t, 2);
        }
    }
}
