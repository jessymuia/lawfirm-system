<?php

namespace App\Warehouse;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ENG-26: rows into a warehouse database (`WAREHOUSE_DB_CONNECTION`: its own server, never a firm's database or the
 * control plane). Tables <prefix><table> are created from WarehouseSchema on first use.
 */
class DatabaseSink implements WarehouseSink
{
    public function write(string $table, string $date, string $tenantKey, array $rows): void
    {
        $db = $this->db();
        $name = $this->ensure($db, $table);
        $db->transaction(function () use ($db, $name, $date, $tenantKey, $rows) {
            $db->table($name)->where('date', $date)->where('tenant_key', $tenantKey)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                $db->table($name)->insert($chunk);
            }
        });
    }

    public function purge(string $tenantKey): int
    {
        $db = $this->db();
        $n = 0;
        foreach (array_keys(WarehouseSchema::TABLES) as $table) {
            $name = config('warehouse.table_prefix').$table;
            if ($db->getSchemaBuilder()->hasTable($name)) {
                $n += $db->table($name)->where('tenant_key', $tenantKey)->delete();
            }
        }

        return $n;
    }

    private function db(): Connection
    {
        $name = (string) config('warehouse.connection');
        if ($name === '' || in_array($name, [config('tenancy.control_connection'), 'tenant', config('database.default')], true)) {
            throw new RuntimeException('WAREHOUSE_DB_CONNECTION must name a separate warehouse connection, not the application\'s own database.');
        }

        return DB::connection($name);
    }

    private function ensure(Connection $db, string $table): string
    {
        $name = config('warehouse.table_prefix').$table;
        if (! $db->getSchemaBuilder()->hasTable($name)) {
            $db->getSchemaBuilder()->create($name, function (Blueprint $t) use ($table) {
                foreach (WarehouseSchema::TABLES[$table] as $column => $type) {
                    $col = match ($type) {
                        'date' => $t->date($column),
                        'int' => $t->bigInteger($column),
                        'int?' => $t->bigInteger($column)->nullable(),
                        'decimal' => $t->decimal($column, 18, 2),
                        'currency' => $t->char($column, 3),
                        default => $t->string($column, 64),
                    };
                    $type === 'code' && $col->nullable();
                }
                $t->index(['date', 'tenant_key']);
            });
        }

        return $name;
    }
}
