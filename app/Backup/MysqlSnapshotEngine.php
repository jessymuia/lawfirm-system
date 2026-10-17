<?php

namespace App\Backup;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * TEN-14 for MySQL/MariaDB: mysqldump in one consistent transaction, recording the binary-log coordinates the dump is
 * consistent at (--source-data / --master-data). A point-in-time restore replays the firm database's own events from
 * there with mysqlbinlog, read from the server (--read-from-remote-server), rewritten into the restored database.
 *
 * The account needs, besides access to the firm database: RELOAD (or FLUSH_TABLES) and REPLICATION CLIENT for the
 * coordinates, REPLICATION SLAVE to read the binary log, and CREATE/DROP for the scratch database. The binary log must
 * be kept at least as long as the point-in-time window (binlog_expire_logs_seconds).
 */
class MysqlSnapshotEngine implements SnapshotEngine
{
    public function dump(array $conn, string $file): array
    {
        $coordinates = $this->supportsPointInTime($conn) ? (str_contains($this->run(['mysqldump', '--help'], $conn), 'source-data') ? '--source-data=2' : '--master-data=2') : null;
        $cmd = array_merge(['mysqldump', '--single-transaction', '--routines', '--triggers', '--events', '--hex-blob'], array_filter([$coordinates]),
            $this->auth($conn), [$conn['database']]);
        $this->shell(implode(' ', array_map('escapeshellarg', $cmd)).' | gzip > '.escapeshellarg($file), $conn);

        $binlog = null;
        $head = (string) shell_exec('gunzip -c '.escapeshellarg($file).' | head -n 60');
        if (preg_match("/(?:MASTER|SOURCE)_LOG_FILE='([^']+)',\\s*(?:MASTER|SOURCE)_LOG_POS=(\\d+)/", $head, $m)) {
            $binlog = ['file' => $m[1], 'position' => (int) $m[2]];
        }

        return ['binlog' => $binlog];
    }

    public function restore(array $conn, string $file, string $database): array
    {
        $this->server($conn)->statement('CREATE DATABASE '.$this->quote($database).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $restored = ['database' => $database] + $conn;
        $this->shell('gunzip -c '.escapeshellarg($file).' | '.implode(' ', array_map('escapeshellarg', array_merge(['mysql'], $this->auth($conn), [$database]))), $conn);

        return $restored;
    }

    public function supportsPointInTime(array $conn): bool
    {
        try {
            return strtoupper((string) ($this->server($conn)->selectOne("SHOW VARIABLES LIKE 'log_bin'")->Value ?? '')) === 'ON';
        } catch (\Throwable) {
            return false;
        }
    }

    public function replay(array $conn, array $binlog, string $until, array $restored): void
    {
        $read = array_merge(['mysqlbinlog', '--read-from-remote-server', '--to-last-log', '--start-position='.$binlog['position'],
            '--stop-datetime='.$until, '--database='.$this->filterName($conn, $restored), '--rewrite-db='.$conn['database'].'->'.$restored['database']],
            $this->auth($conn), [$binlog['file']]);
        $apply = array_merge(['mysql'], $this->auth($conn), [$restored['database']]);
        // --stop-datetime is read in mysqlbinlog's local time zone: make that the app's, which $until is in
        $this->shell(implode(' ', array_map('escapeshellarg', $read)).' | '.implode(' ', array_map('escapeshellarg', $apply)), $conn, ['TZ' => (string) config('app.timezone', 'UTC')]);
    }

    /**
     * The name --database filters on next to --rewrite-db: MySQL's mysqlbinlog rewrites first and filters on the new
     * name, MariaDB's filters on the name the events were logged under.
     */
    private function filterName(array $conn, array $restored): string
    {
        return stripos($this->run(['mysqlbinlog', '--help'], $conn), 'MariaDB') !== false ? $conn['database'] : $restored['database'];
    }

    public function drop(array $restored): void
    {
        $this->server($restored)->statement('DROP DATABASE IF EXISTS '.$this->quote($restored['database']));
    }

    /** @return list<string> */
    private function auth(array $conn): array
    {
        return ['--host='.($conn['host'] ?? '127.0.0.1'), '--port='.($conn['port'] ?? 3306), '--user='.($conn['username'] ?? 'root')];
    }

    private function server(array $conn): Connection
    {
        $name = 'backup_server_'.md5(json_encode([$conn['host'] ?? null, $conn['port'] ?? null, $conn['username'] ?? null]));
        // CREATE/DROP of scratch databases run with information_schema as the default database (every account may use
        // it), so the binary log files them under it and a later replay filtered on a firm's database never repeats them
        config(["database.connections.{$name}" => ['database' => 'information_schema'] + $conn]);

        return DB::connection($name);
    }

    private function quote(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException("Unsafe database name [{$name}].");
        }

        return '`'.$name.'`';
    }

    private function run(array $cmd, array $conn): string
    {
        $p = new Process($cmd, null, ['MYSQL_PWD' => (string) ($conn['password'] ?? '')]);
        $p->run();

        return $p->getOutput();
    }

    private function shell(string $command, array $conn, array $env = []): void
    {
        // the password goes in the environment, never on the command line (ps, shell history)
        // bash for pipefail: a failed mysqldump must not hide behind a successful gzip
        $p = new Process(['bash', '-o', 'pipefail', '-c', $command], null, ['MYSQL_PWD' => (string) ($conn['password'] ?? '')] + $env);
        $p->setTimeout(3600);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new RuntimeException(trim($p->getErrorOutput()) ?: 'Command failed: '.$command);
        }
    }
}
