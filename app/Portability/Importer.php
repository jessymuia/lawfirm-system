<?php

namespace App\Portability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * PLAT-9: loads a bundle into the ACTIVE database (a fresh single install, or a freshly provisioned tenant inside
 * TenantManager::runIn). The same path serves eject-to-self-host, import-from-self-host, tenant relocation and
 * disaster recovery.
 *
 * It restores into an EMPTY target and keeps every id exactly as exported (so foreign keys, audit references and
 * document paths stay true without remapping); the legacy migration:import tool is the one that remaps ids.
 * All-or-nothing: bundle verified first, everything loaded and re-verified in one transaction; any failure rolls
 * the database back and removes the files it wrote.
 */
class Importer
{
    /**
     * @param  array{replace_bootstrap?:bool,dry_run?:bool,files_disk?:?string}  $opts
     * @return array{tables:array<string,int>,files:int,dry_run:bool,notes:list<string>}
     */
    public function import(Bundle $bundle, array $opts = []): array
    {
        $replace = (bool) ($opts['replace_bootstrap'] ?? false);
        $dry = (bool) ($opts['dry_run'] ?? false);
        $filesDisk = $opts['files_disk'] ?? null;
        $m = $bundle->manifest;

        $problems = $bundle->verify();
        if ($problems) {
            throw new PortabilityException('The bundle failed verification: '.implode(' | ', array_slice($problems, 0, 5)));
        }
        $this->checkTarget($bundle, $replace, $filesDisk);

        $summary = ['tables' => [], 'files' => 0, 'dry_run' => $dry, 'notes' => []];
        foreach ($m['tables'] as $t => $info) {
            $summary['tables'][$t] = (int) $info['rows'];
        }
        $summary['files'] = (int) ($m['files']['count'] ?? 0);
        if (! empty($m['files']['missing'])) {
            $summary['notes'][] = "{$m['files']['missing']} document file(s) were missing at export and are not in the bundle.";
        }
        if ($dry) {
            return $summary;
        }

        $written = [];
        $driver = DB::connection()->getDriverName();

        // SQLite cannot change foreign_keys inside a transaction, so switch checks off first on every engine.
        Schema::disableForeignKeyConstraints();
        try {
            $this->copyFiles($bundle, $filesDisk, $written);
            DB::transaction(function () use ($bundle, $replace, $filesDisk) {
                if ($replace) {
                    foreach (array_reverse(TableCatalog::bootstrap()) as $t) {
                        if (Schema::hasTable($t)) {
                            DB::table($t)->delete();
                        }
                    }
                }
                foreach ($bundle->manifest['tables'] as $table => $info) {
                    $this->load($bundle, $table, $filesDisk);
                }
                $this->verifyTarget($bundle, $filesDisk);
            });
        } catch (\Throwable $e) {
            foreach ($written as [$disk, $path]) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable) {
                }
            }
            throw $e instanceof PortabilityException ? $e : new PortabilityException('Import failed and was rolled back: '.$e->getMessage(), 0, $e);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return $summary;
    }

    /** Where a document stored at $path lands here: under THIS tenant's object-store prefix (TEN-12), not the source's. */
    private function targetPath(Bundle $bundle, string $path): string
    {
        $to = 'tenants/'.app(\App\Tenancy\TenantContext::class)->key().'/';
        if (str_starts_with($path, $to)) {
            return $path;
        }

        // TEN-12: every file lands under the importing firm, whether it came from another firm's prefix or was stored
        // before per-firm prefixes existed; never at a shared or foreign key
        $rest = preg_match('#^tenants/[^/]+/(.+)$#', $path, $m) ? $m[1] : ltrim($path, '/');

        return $to.$rest;
    }

    /** The one place a bundle row is adapted to this installation; loading and verification both use it. */
    private function transformRow(Bundle $bundle, string $table, array $row, ?string $filesDisk): array
    {
        if ($table === 'documents') {
            $filesDisk && $row['disk'] = $filesDisk;
            foreach (['full_path', 'filepath'] as $c) {
                if (isset($row[$c]) && is_string($row[$c])) {
                    $row[$c] = $this->targetPath($bundle, $row[$c]);
                }
            }
        }

        return $row;
    }

    private function checkTarget(Bundle $bundle, bool $replace, ?string $filesDisk): void
    {
        $m = $bundle->manifest;

        // 1. the target must already be at least as migrated as the source
        $have = DB::table('migrations')->pluck('migration')->all();
        $missing = array_values(array_diff((array) $m['migrations'], $have));
        if ($missing) {
            throw new PortabilityException('The target is older than the bundle (missing '.count($missing).' migration(s), first: '.$missing[0].'). Upgrade the target and run its migrations first.');
        }

        // 2. every table/column in the bundle must exist in the target
        foreach ($m['tables'] as $table => $info) {
            if (! Schema::hasTable($table)) {
                throw new PortabilityException("The target has no table {$table}.");
            }
            $gone = array_diff($info['columns'], Schema::getColumnListing($table));
            if ($gone) {
                throw new PortabilityException("Table {$table}: the target lacks column(s) ".implode(', ', $gone).'.');
            }
        }

        // 3. empty target (bootstrap tables may hold what a fresh install seeds, replaced only on request)
        $bootstrap = TableCatalog::bootstrap();
        $busy = [];
        foreach ($m['tables'] as $table => $info) {
            if (DB::table($table)->count() === 0) {
                continue;
            }
            if (in_array($table, $bootstrap, true) && $replace) {
                continue;
            }
            $busy[] = $table;
        }
        if ($busy) {
            $hint = array_intersect($busy, $bootstrap) ? ' (users/roles/catalog rows from a first install: pass --replace-bootstrap to replace them)' : '';
            throw new PortabilityException('The target is not empty: '.implode(', ', $busy).$hint.'. An import restores into an empty install; it never merges.');
        }

        // 4. files: disk must be usable, and nothing may be overwritten
        foreach ($bundle->fileEntries() as $e) {
            if (! empty($e['missing'])) {
                continue;
            }
            $disk = $filesDisk ?: $e['disk'];
            if (! is_array(config("filesystems.disks.{$disk}")) || ! config("filesystems.disks.{$disk}.driver")) {
                throw new PortabilityException("Documents were stored on disk \"{$disk}\", which this installation does not have. Configure it, or pass --files-disk=<disk> to store them elsewhere.");
            }
            $path = $this->targetPath($bundle, $e['path']);
            if (Storage::disk($disk)->exists($path)) {
                throw new PortabilityException("A file already exists at {$path} on disk {$disk}; refusing to overwrite.");
            }
        }
    }

    /** @param list<array{0:string,1:string}> $written */
    private function copyFiles(Bundle $bundle, ?string $filesDisk, array &$written): void
    {
        foreach ($bundle->fileEntries() as $e) {
            if (! empty($e['missing'])) {
                continue;
            }
            $disk = (string) ($filesDisk ?: $e['disk']);   // manifest values are decoded JSON
            $path = $this->targetPath($bundle, $e['path']);
            $stream = fopen($bundle->path($e['bundle_path']), 'rb');
            Storage::disk($disk)->writeStream($path, $stream);
            is_resource($stream) && fclose($stream);
            $written[] = [$disk, $path];

            // read it back from where it now lives: the bytes that arrived must be the bytes that left
            $back = Storage::disk($disk)->readStream($path);
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $back);
            is_resource($back) && fclose($back);
            if (! hash_equals((string) $e['sha256'], hash_final($ctx))) {
                throw new PortabilityException("Document #{$e['document_id']} was damaged while being copied to disk {$disk}.");
            }
        }
    }

    private function load(Bundle $bundle, string $table, ?string $filesDisk): void
    {
        $batch = [];
        foreach ($bundle->rows($table) as $row) {
            $batch[] = $this->transformRow($bundle, $table, $row, $filesDisk);
            if (count($batch) >= 500) {
                DB::table($table)->insert($batch);
                $batch = [];
            }
        }
        $batch && DB::table($table)->insert($batch);
    }

    /** Re-read the target and prove it equals the bundle, table by table, plus the document bytes. */
    private function verifyTarget(Bundle $bundle, ?string $filesDisk): void
    {
        foreach ($bundle->manifest['tables'] as $table => $info) {
            $cols = $info['columns'];
            $theirs = new RowHash;
            foreach ($bundle->rows($table) as $row) {
                $theirs->add($this->transformRow($bundle, $table, $row, $filesDisk));
            }
            $mine = new RowHash;
            $q = DB::table($table)->select($cols);
            foreach ($q->cursor() as $row) {
                $mine->add((array) $row);
            }
            if ($mine->count() !== (int) $info['rows'] || $mine->result() !== $theirs->result()) {
                throw new PortabilityException("Verification failed for {$table}: the loaded rows differ from the bundle. Nothing was kept.");
            }
        }
    }
}
