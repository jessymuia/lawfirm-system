<?php

namespace App\Backup;

/**
 * TEN-14: how a firm database is dumped, restored next to the live one and (where the server keeps a binary log)
 * rolled forward to a moment. Connection configs are Laravel connection arrays (CellRegistry::connectionConfig).
 */
interface SnapshotEngine
{
    /**
     * Write a gzip-compressed dump of $conn's database to $file.
     *
     * @return array{binlog: ?array{file: string, position: int}} where in the binary log the dump is consistent, if logging
     */
    public function dump(array $conn, string $file): array;

    /** Load the dump into a new database $database on the same server; returns the connection config for it. */
    public function restore(array $conn, string $file, string $database): array;

    /** Whether $conn's server keeps the binary log a point-in-time restore needs. */
    public function supportsPointInTime(array $conn): bool;

    /** Apply the source database's changes from $binlog up to $until (Y-m-d H:i:s) to $restored. */
    public function replay(array $conn, array $binlog, string $until, array $restored): void;

    public function drop(array $restored): void;
}
