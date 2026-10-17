<?php

namespace App\Warehouse;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * ENG-26: gzip NDJSON files, partitioned the way warehouse loaders expect: <path>/<table>/dt=<date>/tenant=<key>.ndjson.gz.
 * Point BigQuery/Snowflake/Redshift (external tables or scheduled loads) at the prefix.
 */
class FileSink implements WarehouseSink
{
    public function write(string $table, string $date, string $tenantKey, array $rows): void
    {
        $file = $this->path()."/{$table}/dt={$date}/tenant={$tenantKey}.ndjson.gz";
        if (! $rows) {
            $this->disk()->delete($file);

            return;
        }
        $this->disk()->put($file, (string) gzencode(implode("\n", array_map(fn ($r) => json_encode($r, JSON_UNESCAPED_SLASHES), $rows))."\n"));
    }

    public function purge(string $tenantKey): int
    {
        $gone = array_values(array_filter($this->disk()->allFiles($this->path()), fn ($f) => str_ends_with($f, "/tenant={$tenantKey}.ndjson.gz")));
        $this->disk()->delete($gone);

        return count($gone);
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('warehouse.disk'));
    }

    private function path(): string
    {
        return trim((string) config('warehouse.path'), '/');
    }
}
