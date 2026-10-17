<?php

namespace App\Warehouse;

use InvalidArgumentException;

/**
 * ENG-26: everything the warehouse may hold, column by column. Every row is checked against it before it leaves, so
 * nothing outside it (a name, a description, a note, an id of a client or matter) can reach the warehouse even by a
 * mistake in a query: free text has no column type here. Adding a column is a reviewed change to this file.
 *
 * Types: date (Y-m-d), key (a firm's key), code (an identifier from the code: an event name, a plan, a status),
 * currency (ISO 4217), int, int? (nullable), decimal (a sum, 2 places).
 */
class WarehouseSchema
{
    public const KEYS = ['date', 'tenant_key'];

    public const TABLES = [
        // one row per firm: what it is (from the platform's registry, not from the firm's data)
        'dim_firm' => ['date' => 'date', 'tenant_key' => 'key', 'tenant_id' => 'int?', 'status' => 'code', 'plan' => 'code', 'cell' => 'code',
            'region' => 'code', 'created_on' => 'date'],
        // one row per firm per day: how much it used the product
        'firm_daily' => ['date' => 'date', 'tenant_key' => 'key', 'users_total' => 'int', 'users_active' => 'int', 'matters_total' => 'int',
            'matters_open' => 'int', 'matters_opened' => 'int', 'clients_total' => 'int', 'clients_added' => 'int', 'hearings_held' => 'int',
            'documents_added' => 'int', 'document_bytes_added' => 'int', 'document_bytes_total' => 'int', 'tasks_due' => 'int',
            'hours_logged' => 'decimal', 'hours_billable' => 'decimal'],
        // money, per currency (never mixed): invoiced and collected that day
        'firm_money_daily' => ['date' => 'date', 'tenant_key' => 'key', 'currency' => 'currency', 'invoices_issued' => 'int',
            'invoiced' => 'decimal', 'payments' => 'int', 'collected' => 'decimal', 'time_value' => 'decimal'],
        // which features were used: audit event names and how often (never who, or on what)
        'firm_events_daily' => ['date' => 'date', 'tenant_key' => 'key', 'event' => 'code', 'count' => 'int'],
    ];

    /** @param array<string, mixed> $row */
    public static function check(string $table, array $row): array
    {
        $columns = self::TABLES[$table] ?? throw new InvalidArgumentException("Not a warehouse table: {$table}");
        if ($extra = array_diff(array_keys($row), array_keys($columns))) {
            throw new InvalidArgumentException("{$table}: columns not in the warehouse schema: ".implode(', ', $extra));
        }
        $out = [];
        foreach ($columns as $column => $type) {
            $v = $row[$column] ?? null;
            $out[$column] = match ($type) {
                'date' => self::match($table, $column, $v, '/^\d{4}-\d{2}-\d{2}$/'),
                'key' => self::match($table, $column, $v, '/^[a-z0-9][a-z0-9-]{0,62}$/'),
                'code' => $v === null ? null : self::match($table, $column, $v, '/^[A-Za-z0-9_.:-]{1,64}$/'),
                'currency' => self::match($table, $column, $v, '/^[A-Z]{3}$/'),
                'int' => is_numeric($v) && (int) $v == $v ? (int) $v : throw new InvalidArgumentException("{$table}.{$column}: not an integer"),
                'int?' => $v === null ? null : (is_numeric($v) ? (int) $v : throw new InvalidArgumentException("{$table}.{$column}: not an integer")),
                'decimal' => is_numeric($v) ? round((float) $v, 2) : throw new InvalidArgumentException("{$table}.{$column}: not a number"),
            };
        }

        return $out;
    }

    private static function match(string $table, string $column, mixed $v, string $re): string
    {
        if (! is_string($v) || ! preg_match($re, $v)) {
            throw new InvalidArgumentException("{$table}.{$column}: not allowed in the warehouse");
        }

        return $v;
    }
}
