<?php

namespace App\Capacity;

/**
 * ENG-27: turns a database server's numbers into the scaling signals of docs/ops/CAPACITY.md, each OK / WARN / FULL
 * with what to do. Pure: the numbers come from Probe (or a test).
 */
class Signals
{
    /**
     * @param array{firms: int, capacity: ?int, tables: ?int, table_open_cache: ?int, table_definition_cache: ?int, open_files_limit: ?int,
     *     opened_tables: ?int, uptime: ?int, threads_connected: ?int, max_used_connections: ?int, max_connections: ?int,
     *     pool_reads: ?int, pool_read_requests: ?int, bytes: ?int, queue: ?int} $m
     * @return list<array{signal: string, value: string, ceiling: string, state: string, action: string}>
     */
    public static function evaluate(array $m): array
    {
        $warn = (float) config('capacity.warn_at', 0.8);
        $out = [];
        $add = function (string $signal, ?float $value, ?float $ceiling, string $action, bool $lowerIsWorse = false, string $fmt = '%d') use (&$out, $warn) {
            if ($value === null || $ceiling === null) {
                $out[] = ['signal' => $signal, 'value' => 'n/a', 'ceiling' => $ceiling === null ? 'n/a' : sprintf($fmt, $ceiling), 'state' => 'n/a', 'action' => ''];

                return;
            }
            $state = $lowerIsWorse
                ? ($value < $ceiling ? 'WARN' : 'OK')
                : ($value >= $ceiling ? 'FULL' : ($value >= $warn * $ceiling ? 'WARN' : 'OK'));
            $out[] = ['signal' => $signal, 'value' => sprintf($fmt, $value), 'ceiling' => sprintf($fmt, $ceiling), 'state' => $state, 'action' => $state === 'OK' ? '' : $action];
        };

        $add('firms in the cell', $m['firms'], $m['capacity'], 'it stops accepting firms at capacity: add a cell (cells add)');
        $add('tables on the server', $m['tables'], (float) config('capacity.tables_per_server'), 'lower the cell\'s capacity or add a cell');
        $need = $m['firms'] * (int) config('capacity.hot_tables_per_firm', 20);
        $add('table_open_cache vs firms x hot tables', $m['table_open_cache'], (float) $need, "raise table_open_cache to at least {$need} (and open_files_limit to twice that)", true);
        $add('table_definition_cache vs firms x hot tables', $m['table_definition_cache'], (float) $need, "raise table_definition_cache to at least {$need}", true);
        $rate = $m['opened_tables'] !== null && $m['uptime'] ? $m['opened_tables'] / max(1, $m['uptime']) : null;
        $add('tables opened per second', $rate, (float) config('capacity.opened_tables_per_second'), 'the table cache is churning: raise table_open_cache, then add a cell', false, '%.2f');
        $add('connections in use (peak)', $m['max_used_connections'], $m['max_connections'] !== null ? (float) $m['max_connections'] : null,
            'raise max_connections, put a connection proxy in front (CELLS.md), or add a cell');
        $hit = $m['pool_read_requests'] ? 1 - ($m['pool_reads'] ?? 0) / max(1, $m['pool_read_requests']) : null;
        $add('buffer pool hit ratio', $hit, (float) config('capacity.buffer_pool_hit_ratio'), 'the working set no longer fits in memory: a bigger instance or a new cell', true, '%.4f');
        $add('jobs waiting (interactive lane)', $m['queue'], (float) config('capacity.queue_backlog'), 'add queue workers (QUEUES.md)');
        $out[] = ['signal' => 'database size', 'value' => $m['bytes'] === null ? 'n/a' : round($m['bytes'] / 1073741824, 2).' GB', 'ceiling' => 'disk', 'state' => 'info',
            'action' => 'keep 30 % of the volume free for growth, temporary tables and backups'];

        return $out;
    }
}
