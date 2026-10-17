<?php

namespace App\Capacity;

use Illuminate\Database\Connection;

/** ENG-27: reads a database server's capacity numbers (MySQL/MariaDB; other drivers answer what they can). */
class Probe
{
    /** @return array<string, int|null> */
    public static function server(Connection $db, ?string $schemaLike = null): array
    {
        $none = ['tables' => null, 'table_open_cache' => null, 'table_definition_cache' => null, 'open_files_limit' => null, 'opened_tables' => null, 'uptime' => null,
            'threads_connected' => null, 'max_used_connections' => null, 'max_connections' => null, 'pool_reads' => null, 'pool_read_requests' => null, 'bytes' => null];
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $none;
        }
        $var = fn (string $sql, string $name) => isset(($r = (array) $db->selectOne("{$sql} LIKE '{$name}'"))['Value']) ? (int) $r['Value'] : (int) (array_values($r)[1] ?? 0);
        $where = $schemaLike !== null ? 'table_schema like ?' : "table_schema not in ('mysql', 'information_schema', 'performance_schema', 'sys')";
        $sizes = (array) $db->selectOne("select count(*) as n, sum(data_length + index_length) as bytes from information_schema.tables where {$where}", $schemaLike !== null ? [$schemaLike] : []);

        return [
            'tables' => (int) $sizes['n'], 'bytes' => (int) $sizes['bytes'],
            'table_open_cache' => $var('SHOW VARIABLES', 'table_open_cache'), 'table_definition_cache' => $var('SHOW VARIABLES', 'table_definition_cache'),
            'open_files_limit' => $var('SHOW VARIABLES', 'open_files_limit'), 'max_connections' => $var('SHOW VARIABLES', 'max_connections'),
            'opened_tables' => $var('SHOW GLOBAL STATUS', 'Opened_tables'), 'uptime' => $var('SHOW GLOBAL STATUS', 'Uptime'),
            'threads_connected' => $var('SHOW GLOBAL STATUS', 'Threads_connected'), 'max_used_connections' => $var('SHOW GLOBAL STATUS', 'Max_used_connections'),
            'pool_reads' => $var('SHOW GLOBAL STATUS', 'Innodb_buffer_pool_reads'), 'pool_read_requests' => $var('SHOW GLOBAL STATUS', 'Innodb_buffer_pool_read_requests'),
        ];
    }
}
