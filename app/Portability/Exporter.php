<?php

namespace App\Portability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * PLAT-9: writes one self-describing, checksummed bundle of the ACTIVE database (single install, or a tenant inside
 * TenantManager::runIn). Layout:
 *   manifest.json   format, version, app version, source, migrations, per-table file/rows/checksums, file index summary
 *   manifest.sha256 seal over manifest.json
 *   data/<table>.ndjson   one JSON row per line, id order
 *   files/index.ndjson, files/<document id>   document bytes + sha256 per file
 * Read inside one transaction, so on MySQL (REPEATABLE READ) the data is one consistent snapshot.
 */
class Exporter
{
    /** @return array<string,mixed> the manifest */
    public function export(string $dir, bool $withFiles = true): array
    {
        $unclassified = TableCatalog::unclassified();
        if ($unclassified) {
            throw new \RuntimeException('Tables not classified in config/portability.php (include or exclude): '.implode(', ', $unclassified));
        }
        if (is_dir($dir) && count(array_diff(scandir($dir) ?: [], ['.', '..'])) > 0) {
            throw new \RuntimeException("Output directory is not empty: {$dir}");
        }
        foreach ([$dir.'/data', $dir.'/files'] as $d) {
            if (! is_dir($d) && ! mkdir($d, 0700, true) && ! is_dir($d)) {
                throw new \RuntimeException("Cannot create {$d}");
            }
        }

        return DB::transaction(function () use ($dir, $withFiles) {
            $tables = [];
            foreach (TableCatalog::included() as $table) {
                $tables[$table] = $this->writeTable($dir, $table);
            }
            $files = $withFiles ? $this->writeFiles($dir) : ['index' => null, 'count' => 0, 'bytes' => 0, 'missing' => 0, 'sha256' => null, 'included' => false];

            $manifest = [
                'format' => Format::NAME,
                'version' => Format::VERSION,
                'app_version' => (string) config('app.version', ''),
                'exported_at' => now()->utc()->toIso8601String(),
                'source' => [
                    'driver' => DB::connection()->getDriverName(),
                    'tenant' => app(\App\Tenancy\TenantContext::class)->key(),
                ],
                'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
                'tables' => $tables,
                'files' => $files,
                'excluded' => (array) config('portability.exclude'),
                'omitted' => ['support principals (platform-staff accounts and their roles) are never exported'],
            ];
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            file_put_contents($dir.'/manifest.json', $json);
            file_put_contents($dir.'/manifest.sha256', hash('sha256', $json)."  manifest.json\n");

            return $manifest;
        });
    }

    private function writeTable(string $dir, string $table): array
    {
        $path = "{$dir}/data/{$table}.ndjson";
        $h = fopen($path, 'wb');
        $hash = new RowHash;
        $columns = null;
        foreach (TableCatalog::rows($table) as $row) {
            $columns ??= array_keys($row);
            fwrite($h, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            $hash->add($row);
        }
        fclose($h);

        return [
            'file' => "data/{$table}.ndjson", 'rows' => $hash->count(),
            'columns' => $columns ?? TableCatalog::columns($table),
            'sha256' => hash_file('sha256', $path), 'row_checksum' => $hash->result(),
        ];
    }

    private function writeFiles(string $dir): array
    {
        $index = fopen($dir.'/files/index.ndjson', 'wb');
        $count = $bytes = $missing = 0;
        $purged = array_flip(DB::table('retention_disposals')->pluck('document_id')->all());   // SEC-13: disposed on purpose, not lost
        foreach (TableCatalog::rows('documents') as $doc) {
            $disk = $doc['disk'] ?: 'local';
            $path = $doc['full_path'] ?? null;
            $entry = ['document_id' => $doc['id'], 'disk' => $disk, 'path' => $path];
            try {
                $stream = $path ? Storage::disk($disk)->readStream($path) : null;
            } catch (\Throwable) {
                $stream = null;
            }
            if (! $stream) {
                $entry += ['missing' => true];
                if (isset($purged[$doc['id']])) {
                    $entry += ['purged' => true];
                } else {
                    $missing++;
                }
            } else {
                $local = "{$dir}/files/{$doc['id']}";
                $out = fopen($local, 'wb');
                $ctx = hash_init('sha256');
                $size = 0;
                while (! feof($stream)) {
                    $buf = fread($stream, 1 << 20);
                    if ($buf === false || $buf === '') {
                        break;
                    }
                    hash_update($ctx, $buf);
                    fwrite($out, $buf);
                    $size += strlen($buf);
                }
                fclose($out);
                is_resource($stream) && fclose($stream);
                $sha = hash_final($ctx);
                $entry += ['bundle_path' => "files/{$doc['id']}", 'size' => $size, 'sha256' => $sha];
                if (! empty($doc['checksum']) && $doc['checksum'] !== $sha) {
                    $entry['recorded_checksum_mismatch'] = true;
                }
                $count++;
                $bytes += $size;
            }
            fwrite($index, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        }
        fclose($index);

        return ['index' => 'files/index.ndjson', 'count' => $count, 'bytes' => $bytes, 'missing' => $missing,
            'sha256' => hash_file('sha256', $dir.'/files/index.ndjson'), 'included' => true];
    }
}
