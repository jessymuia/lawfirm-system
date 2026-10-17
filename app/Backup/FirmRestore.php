<?php

namespace App\Backup;

use App\Tenancy\CellRegistry;
use App\Tenancy\Models\Tenant;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * TEN-14: restore one firm, without touching any other, to a snapshot or to a moment after it.
 *
 * Always into a new database next to the live one (`<database>_r<time>`), never over it:
 *  1. load the latest snapshot taken at or before the target;
 *  2. if the target is later than the snapshot, replay the firm database's binary-log events up to it (MySQL/MariaDB);
 *  3. check the pair: every file the restored database points at must exist now, or be recoverable as it was at the
 *     target (from the snapshot's archive for local disks, from object versions for S3). Anything that is not makes
 *     the restore inconsistent, and it is refused for switching.
 *
 * Switching (`tenants:restore --switch`) then suspends the firm, puts the missing files back, points the firm at the
 * restored database, and resumes it; the previous database is kept for rolling back. Everything is recorded in
 * `tenant_restores`.
 */
class FirmRestore
{
    public function __construct(private FirmSnapshots $snapshots, private CellRegistry $cells) {}

    /**
     * @return array{manifest: array, conn: array, target: ?string, pitr: bool, files: array, consistent: bool, counts: array<string, int>}
     */
    public function restore(Tenant $tenant, ?string $until = null, ?string $snapshotId = null): array
    {
        $manifest = $snapshotId
            ? collect($this->snapshots->list($tenant))->firstWhere('id', $snapshotId)
            : $this->snapshots->latest($tenant, $until);
        if (! $manifest) {
            throw new RuntimeException($until ? "[{$tenant->slug}] has no snapshot taken at or before {$until}." : "[{$tenant->slug}] has no snapshot.");
        }
        $live = $this->cells->connectionConfig($tenant);
        $engine = $this->snapshots->engine($live);
        $pitr = $until !== null && strtotime($until) > strtotime($manifest['taken_at']);
        if ($pitr && (empty($manifest['binlog']) || ! $engine->supportsPointInTime($live))) {
            throw new RuntimeException("A restore to {$until} needs the binary log, which this server or snapshot does not have: restore to snapshot {$manifest['id']} instead.");
        }
        if ($pitr && $manifest['database'] !== basename((string) $live['database'])) {
            // the firm was switched onto another database since: this snapshot's binary-log position is in the other one's history
            throw new RuntimeException("Snapshot {$manifest['id']} is of the firm's previous database ({$manifest['database']}): point-in-time restores start from a snapshot of the current one.");
        }

        $dump = $this->snapshots->fetch($tenant, $manifest, 'database.sql.gz') ?? throw new RuntimeException("Snapshot {$manifest['id']} has no database dump.");
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', basename((string) $live['database'], '.sqlite')).'_r'.now()->format('YmdHis');
        try {
            $restored = $engine->restore($live, $dump, $name);
        } finally {
            @unlink($dump);
        }
        try {
            if ($pitr) {
                $engine->replay($live, $manifest['binlog'], date('Y-m-d H:i:s', strtotime($until)), $restored);
            }
            $db = $this->snapshots->probe($restored);
            $files = $this->checkFiles($tenant, $manifest, FileReferences::in($db), $pitr ? $until : $manifest['taken_at'], false);
            $counts = $this->snapshots->counts($db);
            DB::purge('backup_probe');
        } catch (\Throwable $e) {
            $engine->drop($restored);
            throw $e;
        }

        return ['manifest' => $manifest, 'conn' => $restored, 'target' => $pitr ? $until : null, 'pitr' => $pitr, 'files' => $files,
            'consistent' => $files['missing'] === [], 'counts' => $counts];
    }

    /**
     * Point the firm at a restored database (from restore()), with its files put back. The firm is suspended meanwhile.
     *
     * @return array{previous_database: string, files_restored: int, new_snapshot: string}
     */
    public function switchTo(Tenant $tenant, array $restore): array
    {
        if (! $restore['consistent']) {
            throw new RuntimeException('The restored database points at files that cannot be recovered; it was not switched in.');
        }
        $status = $tenant->status;
        $previous = (string) $tenant->database;
        $tenant->forceFill(['status' => 'suspended'])->save();
        try {
            $db = $this->snapshots->probe($restore['conn']);
            $files = $this->checkFiles($tenant, $restore['manifest'], FileReferences::in($db), $restore['target'] ?? $restore['manifest']['taken_at'], true);
            DB::purge('backup_probe');
            $tenant->forceFill(['database' => $restore['conn']['database']])->save();
        } finally {
            $tenant->forceFill(['status' => $status])->save();
        }

        // a fresh snapshot of the database the firm now runs on, so point-in-time restores keep working from here
        $snapshot = $this->snapshots->take($tenant->fresh());

        return ['previous_database' => $previous, 'files_restored' => $files['restored'], 'new_snapshot' => $snapshot['id']];
    }

    public function discard(array $restore): void
    {
        $this->snapshots->engine($restore['conn'])->drop($restore['conn']);
    }

    /**
     * Each referenced file: present now, recoverable as it was at $at, or missing. With $write, recoverable files are put
     * back on the live disk.
     *
     * @param  list<array{disk: string, path: string, table: string}>  $refs
     * @return array{referenced: int, present: int, recoverable: int, restored: int, missing: list<array{disk: string, path: string, table: string}>}
     */
    private function checkFiles(Tenant $tenant, array $manifest, array $refs, string $at, bool $write): array
    {
        $out = ['referenced' => count($refs), 'present' => 0, 'recoverable' => 0, 'restored' => 0, 'missing' => []];
        $archives = [];
        foreach ($refs as $ref) {
            $storage = Storage::disk($ref['disk']);
            if ($storage->exists($ref['path'])) {
                $out['present']++;

                continue;
            }
            $driver = (string) config("filesystems.disks.{$ref['disk']}.driver");
            $content = null;
            if ($driver === 'local') {
                $archives[$ref['disk']] ??= $this->snapshots->fetch($tenant, $manifest, "files-{$ref['disk']}.zip") ?? false;
                if ($archives[$ref['disk']]) {
                    $zip = new ZipArchive;
                    $zip->open($archives[$ref['disk']]);
                    $content = $zip->getFromName($ref['path']);
                    $zip->close();
                    $content = $content === false ? null : $content;
                }
            } elseif ($driver === 's3') {
                $content = $this->s3VersionAt($ref['disk'], $ref['path'], $at, $write);
            }
            if ($content === null) {
                $out['missing'][] = $ref;

                continue;
            }
            $out['recoverable']++;
            if ($write) {
                if ($content !== true) {   // true: the S3 version was already copied back in place
                    $storage->put($ref['path'], $content);
                }
                $out['restored']++;
            }
        }
        foreach (array_filter($archives) as $file) {
            @unlink($file);
        }

        return $out;
    }

    /** The object as it was at $at, from the bucket's versions: true once copied back (with $write), '' as a marker if available, null if not. */
    private function s3VersionAt(string $disk, string $path, string $at, bool $write): string|bool|null
    {
        $adapter = Storage::disk($disk);
        $client = method_exists($adapter, 'getClient') ? $adapter->getClient() : null;
        if (! $client instanceof S3Client) {
            return null;
        }
        $bucket = (string) config("filesystems.disks.{$disk}.bucket");
        $key = $adapter->path($path);
        $versions = $client->listObjectVersions(['Bucket' => $bucket, 'Prefix' => $key]);
        $pick = collect($versions['Versions'] ?? [])->filter(fn ($v) => $v['Key'] === $key && strtotime((string) $v['LastModified']) <= strtotime($at))
            ->sortByDesc(fn ($v) => strtotime((string) $v['LastModified']))->first();
        if (! $pick) {
            return null;
        }
        if (! $write) {
            return '';
        }
        $client->copyObject(['Bucket' => $bucket, 'Key' => $key, 'CopySource' => rawurlencode($bucket).'/'.str_replace('%2F', '/', rawurlencode($key)).'?versionId='.$pick['VersionId']]);

        return true;
    }
}
