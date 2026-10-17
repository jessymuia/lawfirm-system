<?php

namespace App\Backup;

use RuntimeException;

/**
 * TEN-14 for SQLite firm databases (development, tests, the smallest installs): a snapshot is a gzip copy of the file
 * (taken with VACUUM INTO, so it is consistent while the app writes). SQLite keeps no binary log, so restores are to a
 * snapshot only, never to a point in time between snapshots.
 */
class SqliteSnapshotEngine implements SnapshotEngine
{
    public function dump(array $conn, string $file): array
    {
        $copy = $file.'.db';
        @unlink($copy);
        (new \PDO('sqlite:'.$conn['database']))->exec('VACUUM INTO '.(new \PDO('sqlite::memory:'))->quote($copy));
        $this->gzip($copy, $file);
        @unlink($copy);

        return ['binlog' => null];
    }

    public function restore(array $conn, string $file, string $database): array
    {
        $path = dirname((string) $conn['database']).'/'.$database.'.sqlite';
        $in = gzopen($file, 'rb') ?: throw new RuntimeException("Cannot read {$file}.");
        $out = fopen($path, 'wb') ?: throw new RuntimeException("Cannot write {$path}.");
        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, 1048576));
        }
        gzclose($in);
        fclose($out);

        return ['database' => $path] + $conn;
    }

    public function supportsPointInTime(array $conn): bool
    {
        return false;
    }

    public function replay(array $conn, array $binlog, string $until, array $restored): void
    {
        throw new RuntimeException('SQLite keeps no binary log: restore to a snapshot instead.');
    }

    public function drop(array $restored): void
    {
        @unlink((string) $restored['database']);
    }

    private function gzip(string $from, string $to): void
    {
        $in = fopen($from, 'rb') ?: throw new RuntimeException("Cannot read {$from}.");
        $out = gzopen($to, 'wb6') ?: throw new RuntimeException("Cannot write {$to}.");
        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1048576));
        }
        fclose($in);
        gzclose($out);
    }
}
