<?php

namespace App\Warehouse;

/** ENG-26: where checked warehouse rows go. Writes are idempotent per (table, date, firm): a re-run replaces. */
interface WarehouseSink
{
    /** @param list<array<string, mixed>> $rows already checked against WarehouseSchema */
    public function write(string $table, string $date, string $tenantKey, array $rows): void;

    /** Remove every row of a firm (TEN-9 purge). @return int rows or files removed */
    public function purge(string $tenantKey): int;
}
