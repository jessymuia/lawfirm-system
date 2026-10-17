<?php

namespace App\Backup;

use App\Models\Cases;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * CARRY-7: proves the install's own backups (`backup:run`: database dump + storage archive) restore to something the
 * application can run on, and how long that takes.
 *
 * The newest pair on the backup disk (or the one asked for) is restored beside the live install: the dump into a
 * scratch database on the same server, the archive into a scratch directory. Then it checks what a restore must give
 * back: every table of the dump with its rows, a migration history the code recognises (`migrate:status` run by the
 * application itself against the scratch database), the people and matters an office would look for first, and every
 * document file the restored rows point to. Each phase is timed; the report is kept on the backup disk under
 * drills/. The scratch database and directory are removed afterwards unless kept. Live data is only ever read.
 */
class InstallRestoreDrill
{
    /** @var array<string, float> phase => seconds */
    private array $timings = [];

    /**
     * @return array{ok: bool, backup: string, files_backup: ?string, at: string, timings: array<string, float>, checks: array<string, mixed>, problems: list<string>, scratch: array{database: string, directory: string, kept: bool}}
     */
    public function run(?string $stamp = null, bool $keep = false): array
    {
        $conn = (array) config('database.connections.'.config('database.default'));
        if (($conn['driver'] ?? null) !== 'mysql' && ($conn['driver'] ?? null) !== 'mariadb') {
            throw new RuntimeException('The restore drill restores MySQL/MariaDB dumps written by backup:run; the default connection is '.($conn['driver'] ?? 'unknown').'.');
        }
        [$dump, $archive] = $this->pair($stamp);
        $id = 'drill_'.date('Ymd_His').'_'.bin2hex(random_bytes(2));
        $dir = storage_path("framework/drills/{$id}");
        $problems = [];
        $checks = [];
        $started = microtime(true);

        try {
            $this->timed('fetch', function () use ($dump, $archive, $dir) {
                File::ensureDirectoryExists($dir);
                $this->download($dump, "{$dir}/database.sql.gz");
                $archive && $this->download($archive, "{$dir}/files.tar.gz");
            });

            $this->timed('restore_database', function () use ($conn, $id, $dir) {
                $this->server($conn)->statement("CREATE DATABASE `{$id}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $this->shell('gunzip -c '.escapeshellarg("{$dir}/database.sql.gz").' | mysql '.$this->auth($conn).' '.escapeshellarg($id), $conn);
            });

            $this->timed('restore_files', function () use ($archive, $dir) {
                if ($archive) {
                    File::ensureDirectoryExists("{$dir}/files");
                    $this->shell('tar -xzf '.escapeshellarg("{$dir}/files.tar.gz").' -C '.escapeshellarg("{$dir}/files"), []);
                }
            });

            $this->timed('verify', function () use ($conn, $id, $dir, $archive, &$checks, &$problems) {
                config(['database.connections.restore_drill' => array_merge($conn, ['database' => $id])]);
                DB::purge('restore_drill');
                $restored = DB::connection('restore_drill');

                $tables = $restored->getSchemaBuilder()->getTableListing($id, false);   // this database only (MySQL lists every schema otherwise)
                $rows = [];
                foreach ($tables as $t) {
                    $rows[$t] = (int) $restored->table($t)->count();
                }
                $checks['tables'] = count($tables);
                $checks['rows'] = array_sum($rows);
                foreach (['migrations', 'users', 'clients', 'cases', 'documents'] as $needed) {
                    in_array($needed, $tables, true) || $problems[] = "table {$needed} is missing from the restore";
                }
                ($rows['migrations'] ?? 0) > 0 || $problems[] = 'the restored database has no migration history';
                ($rows['users'] ?? 0) > 0 || $problems[] = 'the restored database has nobody who can sign in';
                $checks['users'] = $rows['users'] ?? 0;
                $checks['matters'] = $rows['cases'] ?? 0;
                $checks['clients'] = $rows['clients'] ?? 0;

                // the application's own code must read it: its migration history against the migrations it ships, and
                // its models (relations included) on the restored rows
                $repository = app('migration.repository');
                $repository->setSource('restore_drill');
                $ran = $repository->getRan();
                $shipped = array_keys(app('migrator')->getMigrationFiles([database_path('migrations')]));
                $unknown = array_values(array_diff($ran, $shipped));
                $checks['migrations_applied'] = count($ran);
                $checks['pending_migrations'] = count(array_diff($shipped, $ran));   // a backup older than the code: allowed, upgrade applies them
                $unknown && $problems[] = 'the restore has migrations this code does not know (a newer backup?): '.implode(', ', array_slice($unknown, 0, 5));
                try {
                    User::on('restore_drill')->first();
                    Cases::on('restore_drill')->with('client')->latest('id')->first();
                    Invoice::on('restore_drill')->with('items')->latest('id')->first();
                    $checks['app_reads_data'] = true;
                } catch (\Throwable $e) {
                    $checks['app_reads_data'] = false;
                    $problems[] = 'the application could not read the restored data: '.mb_substr($e->getMessage(), 0, 300);
                }

                // every document the restored rows point to, on the local disk, is in the restored archive
                $docs = in_array('documents', $tables, true) ? $restored->table('documents')->whereNull('deleted_at')->get(['id', 'disk', 'filepath']) : collect();
                $local = $docs->filter(fn ($d) => in_array($d->disk ?: 'local', ['local'], true));
                $root = $archive ? "{$dir}/files/".ltrim(storage_path('app'), '/') : null;
                $missing = $local->filter(fn ($d) => ! $root || ! is_file("{$root}/{$d->filepath}"))->pluck('id')->values()->all();
                $checks['documents'] = $docs->count();
                $checks['documents_local'] = $local->count();
                $checks['documents_elsewhere'] = $docs->count() - $local->count();   // S3: versioned in the bucket, not in this archive
                $checks['documents_missing'] = count($missing);
                $missing && $problems[] = count($missing).' document file(s) missing from the archive (ids '.implode(', ', array_slice($missing, 0, 10)).')';
            });
        } catch (\Throwable $e) {
            $problems[] = $e->getMessage();
        } finally {
            if (! $keep) {
                $this->timed('cleanup', function () use ($conn, $id, $dir) {
                    DB::purge('restore_drill');
                    $this->server($conn)->statement("DROP DATABASE IF EXISTS `{$id}`");
                    File::deleteDirectory($dir);
                });
            }
        }
        $this->timings['total'] = round(microtime(true) - $started, 2);

        $report = ['ok' => $problems === [], 'backup' => $dump, 'files_backup' => $archive, 'at' => now()->toIso8601String(),
            'timings' => $this->timings, 'checks' => $checks, 'problems' => $problems, 'scratch' => ['database' => $id, 'directory' => $dir, 'kept' => $keep]];
        Storage::disk((string) config('backup.disk'))->put('drills/'.now()->format('Y-m-d_His').'.json', json_encode($report, JSON_PRETTY_PRINT));

        return $report;
    }

    /** @return array{0: string, 1: ?string} the dump and its archive (same timestamp) */
    private function pair(?string $stamp): array
    {
        $disk = Storage::disk((string) config('backup.disk'));
        $dumps = collect($disk->allFiles())->filter(fn ($f) => ! str_starts_with($f, 'tenants/') && preg_match('#(^|/)database-(\d{4}-\d{2}-\d{2}_\d{6})\.sql\.gz$#', $f))->sort()->values();
        $dump = $stamp ? $dumps->first(fn ($f) => str_contains($f, "database-{$stamp}.sql.gz")) : $dumps->last();
        if (! $dump) {
            throw new RuntimeException($stamp ? "No backup taken at {$stamp} on the backup disk." : 'No backup (database-*.sql.gz from backup:run) on the backup disk: there is nothing to restore.');
        }
        preg_match('#database-(\d{4}-\d{2}-\d{2}_\d{6})#', $dump, $m);
        $archive = dirname($dump).'/storage-app-'.$m[1].'.tar.gz';

        return [$dump, $disk->exists($archive) ? $archive : null];
    }

    private function download(string $path, string $to): void
    {
        $in = Storage::disk((string) config('backup.disk'))->readStream($path) ?: throw new RuntimeException("Cannot read {$path} from the backup disk.");
        $out = fopen($to, 'w');
        stream_copy_to_stream($in, $out);
        fclose($out);
        is_resource($in) && fclose($in);
    }

    private function timed(string $phase, \Closure $fn): void
    {
        $t = microtime(true);
        try {
            $fn();
        } finally {
            $this->timings[$phase] = round(microtime(true) - $t, 2);
        }
    }

    private function server(array $conn): Connection
    {
        config(['database.connections.restore_drill_server' => array_merge($conn, ['database' => 'information_schema'])]);

        return DB::connection('restore_drill_server');
    }

    private function auth(array $conn): string
    {
        return implode(' ', array_map('escapeshellarg', ['--host='.($conn['host'] ?? '127.0.0.1'), '--port='.($conn['port'] ?? 3306), '--user='.($conn['username'] ?? 'root')]));
    }

    private function shell(string $command, array $conn): void
    {
        $p = new Process(['bash', '-o', 'pipefail', '-c', $command], null, ['MYSQL_PWD' => (string) ($conn['password'] ?? '')]);   // a failed gunzip must fail the pipe
        $p->setTimeout(7200);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new RuntimeException(trim($p->getErrorOutput()) ?: 'command failed: '.strtok($command, ' '));
        }
    }
}
