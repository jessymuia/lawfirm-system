<?php

namespace App\Warehouse;

/** ENG-26: one firm's day, checked row by row against the schema, to every configured sink. */
class WarehouseExporter
{
    public function __construct(private FirmMetrics $metrics) {}

    /** @return list<WarehouseSink> */
    public static function sinks(): array
    {
        return array_map(fn (string $s) => match (trim($s)) {
            'files' => app(FileSink::class),
            'database' => app(DatabaseSink::class),
            default => throw new \InvalidArgumentException("Unknown warehouse sink [{$s}]"),
        }, (array) config('warehouse.sinks'));
    }

    /**
     * Run inside the firm (its database active).
     *
     * @param  array<string, mixed>  $firm  the dim_firm row, from the platform registry
     * @return array<string, int> rows per table
     */
    public function exportFirm(string $date, string $tenantKey, array $firm): array
    {
        $tables = ['dim_firm' => [['date' => $date, 'tenant_key' => $tenantKey] + $firm]] + $this->metrics->day($date, $tenantKey);
        $checked = [];
        foreach ($tables as $table => $rows) {
            $checked[$table] = array_map(fn ($r) => WarehouseSchema::check($table, $r), $rows);   // all checked before anything is written
        }
        foreach (self::sinks() as $sink) {
            foreach ($checked as $table => $rows) {
                $sink->write($table, $date, $tenantKey, $rows);
            }
        }

        return array_map('count', $checked);
    }
}
