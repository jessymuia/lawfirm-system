<?php

namespace App\Backup;

use App\Tenancy\CellRegistry;
use App\Tenancy\Keys\BackupCipher;
use App\Tenancy\Keys\FirmKeys;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * TEN-14: a snapshot of one firm, taken as a pair: its database (with the binary-log position it is consistent at, so it
 * can be rolled forward), the firm's files on local disks, and a manifest (row counts, file references) that a restore
 * and a drill check the result against. Files on S3 are not copied: the bucket must have versioning on, and a restore
 * takes each file as it was at the target moment (docs/ops/FIRM_RESTORE.md).
 *
 * Layout on the backup disk: tenants/<slug>/snapshots/<id>/{database.sql.gz, files-<disk>.zip, manifest.json}, each
 * file encrypted with the firm's data key when it has one (TEN-13, `.enc`).
 */
class FirmSnapshots
{
    public function __construct(private CellRegistry $cells, private FirmKeys $keys) {}

    public function engine(array $conn): SnapshotEngine
    {
        return ($conn['driver'] ?? null) === 'sqlite' ? new SqliteSnapshotEngine : new MysqlSnapshotEngine;
    }

    public static function disk(): string
    {
        return (string) config('backup.disk');
    }

    /** @return array<string, mixed> the manifest */
    public function take(Tenant $tenant): array
    {
        $id = now()->format('Ymd\THis');
        $dir = "tenants/{$tenant->slug}/snapshots/{$id}";
        $conn = $this->cells->connectionConfig($tenant);
        $key = $this->keys->encrypter($tenant)?->getKey();
        $tmp = sys_get_temp_dir().'/snap-'.$tenant->slug.'-'.bin2hex(random_bytes(4));
        mkdir($tmp);
        try {
            $dump = $this->engine($conn)->dump($conn, "{$tmp}/database.sql.gz");
            $db = $this->probe($conn);
            $manifest = ['id' => $id, 'tenant' => $tenant->slug, 'database' => basename((string) $conn['database']), 'driver' => $conn['driver'],
                'taken_at' => now()->toIso8601String(), 'binlog' => $dump['binlog'], 'firm_key_encrypted' => $key !== null,
                'tables' => $this->counts($db), 'files' => [], 'file_references' => count(FileReferences::in($db))];
            DB::purge('backup_probe');

            $this->store("{$tmp}/database.sql.gz", "{$dir}/database.sql.gz", $key);
            foreach ($this->firmDisks($tenant) as $disk) {
                $manifest['files'][$disk] = $this->snapshotDisk($tenant, $disk, "{$tmp}/files-{$disk}.zip", "{$dir}/files-{$disk}.zip", $key);
            }
            Storage::disk(self::disk())->put("{$dir}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $manifest;
        } finally {
            array_map('unlink', glob("{$tmp}/*") ?: []);
            @rmdir($tmp);
        }
    }

    /** @return list<array<string, mixed>> the firm's snapshots, newest first */
    public function list(Tenant $tenant): array
    {
        $disk = Storage::disk(self::disk());
        $out = [];
        foreach ($disk->directories("tenants/{$tenant->slug}/snapshots") as $d) {
            if ($disk->exists("{$d}/manifest.json")) {
                $out[] = json_decode((string) $disk->get("{$d}/manifest.json"), true);
            }
        }
        usort($out, fn ($a, $b) => strcmp($b['id'], $a['id']));

        return $out;
    }

    /** The latest snapshot taken at or before $at (or the latest of all). */
    public function latest(Tenant $tenant, ?string $at = null): ?array
    {
        foreach ($this->list($tenant) as $m) {
            if ($at === null || strtotime($m['taken_at']) <= strtotime($at)) {
                return $m;
            }
        }

        return null;
    }

    /** A snapshot file, decrypted if needed, as a local temporary file the caller deletes. */
    public function fetch(Tenant $tenant, array $manifest, string $name): ?string
    {
        $disk = Storage::disk(self::disk());
        $path = "tenants/{$tenant->slug}/snapshots/{$manifest['id']}/{$name}".($manifest['firm_key_encrypted'] ? '.enc' : '');
        if (! $disk->exists($path)) {
            return null;
        }
        $local = tempnam(sys_get_temp_dir(), 'snapfile');
        file_put_contents($local, $disk->readStream($path));
        if ($manifest['firm_key_encrypted']) {
            $key = $this->keys->encrypter($tenant)?->getKey() ?? throw new RuntimeException("The snapshot of [{$tenant->slug}] is encrypted and the firm's key is gone.");
            BackupCipher::decryptFile($local, $local.'.plain', $key);
            rename($local.'.plain', $local);
        }

        return $local;
    }

    /** A Laravel connection to $conn, for reading counts and references. */
    public function probe(array $conn): Connection
    {
        config(['database.connections.backup_probe' => $conn]);
        DB::purge('backup_probe');

        return DB::connection('backup_probe');
    }

    /** @return array<string, int> table => rows */
    public function counts(Connection $db): array
    {
        $out = [];
        // this database's tables only (on MySQL the listing would otherwise cover every database on the server)
        $schema = $db->getDriverName() === 'sqlite' ? null : $db->getDatabaseName();
        foreach ($db->getSchemaBuilder()->getTableListing($schema, false) as $table) {
            $out[$table] = (int) $db->table($table)->count();
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> the disks the firm's files can be on */
    public function firmDisks(Tenant $tenant): array
    {
        return array_values(array_unique(array_filter([...(array) config('tenancy.storage_disks'), $tenant->files_disk])));
    }

    /** @return array{driver: string, files: int, archive?: string} */
    private function snapshotDisk(Tenant $tenant, string $disk, string $zipFile, string $target, ?string $key): array
    {
        $driver = (string) config("filesystems.disks.{$disk}.driver");
        if ($driver !== 'local') {
            return ['driver' => $driver, 'files' => 0];   // versioned bucket: taken at restore time from object versions
        }
        $storage = Storage::disk($disk);
        $zip = new ZipArchive;
        $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $n = 0;
        foreach ($storage->allFiles("tenants/{$tenant->slug}") as $file) {
            $zip->addFromString($file, (string) $storage->get($file));
            $n++;
        }
        $n === 0 ? $zip->addEmptyDir('tenants') : null;
        $zip->close();
        $this->store($zipFile, $target, $key);

        return ['driver' => 'local', 'files' => $n, 'archive' => basename($target)];
    }

    private function store(string $local, string $target, ?string $key): void
    {
        if ($key !== null) {
            BackupCipher::encryptFile($local, $local.'.enc', $key);
            $local .= '.enc';
            $target .= '.enc';
        }
        $stream = fopen($local, 'rb');
        Storage::disk(self::disk())->put($target, $stream);
        is_resource($stream) && fclose($stream);
        @unlink($local);
    }
}
