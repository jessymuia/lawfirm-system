<?php

namespace App\Capacity;

use Illuminate\Database\Connection;

/**
 * ENG-27: how a database server behaves as firm databases accumulate on it (database-per-firm, TEN-4). Creates scratch
 * copies of the firm schema step by step (`CREATE TABLE ... LIKE` from the current firm database, with a few rows each)
 * and at each step measures:
 *  - provisioning: milliseconds to create one firm's tables;
 *  - catalogue: a query over information_schema (what backups, migrations and monitoring do);
 *  - work: p50/p95 of a typical request's queries while requests rotate across all firms, so the table cache churns
 *    as it does in production;
 *  - table cache: tables opened per 1 000 queries (Opened_tables), the signal that the cache is too small.
 * MySQL/MariaDB only. Scratch databases are named <prefix>NNNN and dropped at the end unless kept.
 */
class Soak
{
    public bool $keep = false;

    /**
     * One request as the screens make it: who is signing in and what they may do (permissions, firm settings, walls),
     * then a dashboard's worth of reads: 17 tables, about what a typical page touches.
     */
    private const REQUEST = [
        'select u.id from `{db}`.`users` u left join `{db}`.`user_mfa` m on m.user_id = u.id where u.deleted_at is null limit 1',
        'select p.name from `{db}`.`model_has_roles` mr join `{db}`.`role_has_permissions` rp on rp.role_id = mr.role_id join `{db}`.`permissions` p on p.id = rp.permission_id limit 50',
        'select r.name from `{db}`.`roles` r limit 10',
        'select `value` from `{db}`.`firm_settings` limit 20',
        'select id from `{db}`.`ethical_screens` where lifted_at is null limit 20',
        'select count(*) from `{db}`.`clients` where deleted_at is null',
        "select count(*) from `{db}`.`cases` where lifecycle_status = 'open' and deleted_at is null",
        'select h.id from `{db}`.`hearings` h join `{db}`.`cases` c on c.id = h.case_id join `{db}`.`courts` ct on ct.id = h.court_id where h.hearing_date >= current_date order by h.hearing_date limit 10',
        "select id from `{db}`.`tasks` where task_status <> 'completed' and deleted_at is null order by due_date limit 10",
        'select sum(hours) from `{db}`.`time_entries` where billable = 1 and billed = 0 and deleted_at is null',
        "select sum(total_amount - amount_paid) from `{db}`.`invoices` where workflow_status = 'submitted' and deleted_at is null",
        'select sum(amount) from `{db}`.`payments` where deleted_at is null',
        'select id from `{db}`.`documents` where is_current = 1 and deleted_at is null order by id desc limit 10',
    ];

    public function __construct(private Connection $db, private string $prefix = 'capsoak_') {}

    /** @return list<array<string, int|float>> one row per step */
    public function run(int $firms, int $step, int $queries, ?callable $progress = null): array
    {
        $template = (string) $this->db->getDatabaseName();
        $tables = array_map(fn ($r) => array_values((array) $r)[0], $this->db->select('SHOW TABLES'));
        $rows = [];
        $made = 0;
        try {
            while ($made < $firms) {
                $t0 = hrtime(true);
                $target = min($firms, $made + $step);
                for ($i = $made + 1; $i <= $target; $i++) {
                    $this->create($this->name($i), $template, $tables);
                }
                $provision = (hrtime(true) - $t0) / 1e6 / ($target - $made);
                $made = $target;

                $t0 = hrtime(true);
                $this->db->select("select count(*) as n from information_schema.tables where table_schema like '{$this->prefix}%'");
                $catalogue = (hrtime(true) - $t0) / 1e6;

                [$p50, $p95, $opened] = $this->work($made, $queries);
                $rows[] = ['firms' => $made, 'tables' => $made * count($tables), 'provision_ms' => round($provision, 1), 'catalogue_ms' => round($catalogue, 1),
                    'request_p50_ms' => $p50, 'request_p95_ms' => $p95, 'tables_opened_per_1k' => $opened];
                $progress && $progress(end($rows));
            }
        } finally {
            if (! $this->keep) {
                $this->drop($made);
            }
        }

        return $rows;
    }

    private function name(int $i): string
    {
        return $this->prefix.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
    }

    private function create(string $db, string $template, array $tables): void
    {
        $this->db->statement("CREATE DATABASE IF NOT EXISTS `{$db}`");
        $this->db->statement('SET foreign_key_checks = 0');
        foreach ($tables as $t) {
            $this->db->statement("CREATE TABLE IF NOT EXISTS `{$db}`.`{$t}` LIKE `{$template}`.`{$t}`");
        }
        // a few rows where a request reads, so queries touch real pages
        foreach (['users', 'roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'courts', 'clients', 'cases', 'hearings', 'tasks', 'invoices', 'documents'] as $t) {
            if (in_array($t, $tables, true)) {
                $this->db->statement("INSERT IGNORE INTO `{$db}`.`{$t}` SELECT * FROM `{$template}`.`{$t}` LIMIT 50");
            }
        }
        $this->db->statement('SET foreign_key_checks = 1');
    }

    /** A dashboard-like request: the queries most screens run, against a random firm each time. */
    private function work(int $firms, int $requests): array
    {
        $before = (int) $this->status('Opened_tables');
        $times = [];
        for ($i = 0; $i < $requests; $i++) {
            $db = $this->name(random_int(1, $firms));
            $t0 = hrtime(true);
            foreach (self::REQUEST as $sql) {
                $this->db->select(str_replace('{db}', $db, $sql));
            }
            $times[] = (hrtime(true) - $t0) / 1e6;
        }
        sort($times);
        $opened = ((int) $this->status('Opened_tables') - $before) / max(1, $requests * count(self::REQUEST)) * 1000;

        return [round($times[(int) floor(0.5 * (count($times) - 1))], 2), round($times[(int) floor(0.95 * (count($times) - 1))], 2), round($opened, 1)];
    }

    private function status(string $var): string
    {
        return (string) (array_values((array) ($this->db->selectOne("SHOW GLOBAL STATUS LIKE '{$var}'") ?? []))[1] ?? '0');
    }

    private function drop(int $made): void
    {
        for ($i = 1; $i <= $made; $i++) {
            $this->db->statement('DROP DATABASE IF EXISTS `'.$this->name($i).'`');
        }
    }
}
