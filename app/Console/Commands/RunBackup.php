<?php

namespace App\Console\Commands;

use App\Tenancy\Keys\BackupCipher;
use App\Tenancy\Keys\FirmKeys;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * ENG-6: prior to this command there was no backup mechanism at all —
 * docs/ops/BACKUP_STRATEGY.md described what *should* happen but nothing
 * automated it. This is the minimum viable implementation that document
 * calls for:
 *
 *  - a full logical `mysqldump` of the database, gzip-compressed;
 *  - an archive of storage/app (documents not yet migrated to S3, FUN-5);
 *  - both written to an off-server disk (config('backup.disk'), default
 *    'backups' locally, expected to be BACKUP_DISK=s3 in production —
 *    see the config file's own docblock for why this matters);
 *  - retention pruning so this doesn't grow unbounded.
 *
 * This intentionally shells out to `mysqldump`/`tar` rather than adding a
 * new Composer dependency (e.g. spatie/laravel-backup) — those binaries
 * are already required wherever MySQL itself is required, so there's
 * nothing extra to install on the production host.
 */
class RunBackup extends Command
{
    protected $signature = 'backup:run {--dry-run : Report what would be backed up without shelling out or writing anything} {--tenant= : PLAT-7: back up one tenant database (multi mode) under tenants/<slug>/}';

    protected $description = 'ENG-6: nightly database + document backup to an off-server disk';

    public function handle(): int
    {
        if ($slug = $this->option('tenant')) {
            $tenants = app(TenantManager::class);
            $tenant = $tenants->isMulti() ? Tenant::where('slug', $slug)->first() : null;
            if (! $tenant) {
                $this->error("--tenant needs multi-tenant mode and an existing tenant (got [{$slug}]).");

                return CommandAlias::FAILURE;
            }

            // TEN-13: a firm with its own data key gets its backups encrypted with it (so destroying the key shreds them).
            // A key that exists but cannot be used (a customer key revoked) stops the backup rather than writing it in the clear.
            $key = app(FirmKeys::class)->encrypter($tenant)?->getKey();

            // Suspended tenants are still backed up; only the default connection (this tenant's database) is dumped.
            return $tenants->runIn($tenant, fn () => $this->backupCurrent('tenants/'.$tenant->slug.'/'.now()->format('Y/m/d'), [storage_path('app/tenants/'.$tenant->slug)], $key), allowInactive: true);
        }

        return $this->backupCurrent(now()->format('Y/m/d'), config('backup.file_paths', []));
    }

    private function backupCurrent(string $prefix, array $filePaths, ?string $key = null): int
    {
        $disk = (string) config('backup.disk');
        $timestamp = now()->format('Y-m-d_His');

        $dbConnection = (string) config('database.default');
        $db = config("database.connections.{$dbConnection}");

        if (! $db || ($db['driver'] ?? null) !== 'mysql') {
            $this->error("backup:run currently only supports the mysql driver (connection '{$dbConnection}' is '".($db['driver'] ?? 'unknown')."').");

            return CommandAlias::FAILURE;
        }

        $suffix = $key === null ? '' : '.enc';   // TEN-13: `backup:decrypt` turns it back into the .gz
        $dbDumpTarget = "{$prefix}/database-{$timestamp}.sql.gz{$suffix}";
        $filesTarget = "{$prefix}/storage-app-{$timestamp}.tar.gz{$suffix}";

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Would dump database '{$db['database']}' to disk '{$disk}' at {$dbDumpTarget}.");
            foreach ($filePaths as $path) {
                $this->info("[dry-run] Would archive '{$path}' to disk '{$disk}' at {$filesTarget}.");
            }
            $this->info('[dry-run] Would prune backups older than '.config('backup.retention_days').' day(s) on disk '.$disk.'.');

            return CommandAlias::SUCCESS;
        }

        try {
            $this->backupDatabase($db, $disk, $dbDumpTarget, $key);
            $this->info("Database backup written to [{$disk}] {$dbDumpTarget}");

            $this->backupFiles($disk, $filesTarget, $filePaths, $key);
            $this->info("File backup written to [{$disk}] {$filesTarget}");
        } catch (ProcessFailedException $exception) {
            $this->error('Backup failed: '.$exception->getMessage());

            return CommandAlias::FAILURE;
        }

        $pruned = $this->pruneOldBackups($disk);
        if ($pruned > 0) {
            $this->info("Pruned {$pruned} backup file(s) older than ".config('backup.retention_days').' day(s).');
        }

        return CommandAlias::SUCCESS;
    }

    private function backupDatabase(array $db, string $disk, string $target, ?string $key = null): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'lawfirm-db-backup-').'.sql.gz';

        // --single-transaction keeps this non-blocking against InnoDB
        // tables (no long-held locks on a production database), and
        // --routines/--events capture anything beyond plain table data.
        $dumpCommand = sprintf(
            'mysqldump --single-transaction --routines --events -h%s -P%s -u%s %s | gzip > %s',
            escapeshellarg($db['host'] ?? '127.0.0.1'),
            escapeshellarg((string) ($db['port'] ?? 3306)),
            escapeshellarg($db['username'] ?? ''),
            escapeshellarg($db['database'] ?? ''),
            escapeshellarg($tempFile)
        );

        $process = Process::fromShellCommandline($dumpCommand, null, [
            // mysqldump reads the password from the environment rather
            // than the command line so it never shows up in `ps`/shell
            // history/process-list output.
            'MYSQL_PWD' => $db['password'] ?? '',
        ]);
        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($tempFile);
            throw new ProcessFailedException($process);
        }

        $this->storeAndDelete($tempFile, $disk, $target, $key);
    }

    private function backupFiles(string $disk, string $target, array $paths, ?string $key = null): void
    {
        $existingPaths = array_values(array_filter($paths, 'is_dir'));

        if (empty($existingPaths)) {
            $this->warn('No configured backup.file_paths exist on disk — skipping file archive.');

            return;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'lawfirm-files-backup-').'.tar.gz';

        $process = new Process(array_merge(['tar', '-czf', $tempFile], $existingPaths));
        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($tempFile);
            throw new ProcessFailedException($process);
        }

        $this->storeAndDelete($tempFile, $disk, $target, $key);
    }

    private function storeAndDelete(string $tempFile, string $disk, string $target, ?string $key = null): void
    {
        if ($key !== null) {
            try {
                BackupCipher::encryptFile($tempFile, $tempFile.'.enc', $key);
            } finally {
                @unlink($tempFile);   // the clear copy never leaves this machine
            }
            $tempFile .= '.enc';
        }
        $stream = fopen($tempFile, 'r');
        try {
            Storage::disk($disk)->put($target, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($tempFile);
        }
    }

    private function pruneOldBackups(string $disk): int
    {
        $retentionDays = (int) config('backup.retention_days');
        $cutoff = now()->subDays($retentionDays);
        $pruned = 0;

        foreach (Storage::disk($disk)->allFiles() as $file) {
            try {
                $lastModified = Storage::disk($disk)->lastModified($file);
            } catch (\Throwable) {
                // Flysystem v3 throws (rather than returning false, as it did
                // pre-Laravel-12) when metadata can't be read for a file —
                // skip it this pass rather than aborting the whole prune.
                continue;
            }

            if ($lastModified < $cutoff->timestamp) {
                Storage::disk($disk)->delete($file);
                $pruned++;
            }
        }

        return $pruned;
    }
}
