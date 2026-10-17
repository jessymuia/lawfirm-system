<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Small firm-wide switches stored in the firm's own database (`firm_settings`), so they travel with the firm (PLAT-9)
 * and never leak between firms. Read on use (no process-wide memo: a worker serves many firms).
 */
class FirmSettings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $v = DB::table('firm_settings')->where('key', $key)->value('value');

        return $v === null ? $default : json_decode((string) $v, true);
    }

    public static function set(string $key, mixed $value, ?int $by = null): void
    {
        DB::table('firm_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_by' => $by, 'updated_at' => now(), 'created_at' => now()]);
    }
}
