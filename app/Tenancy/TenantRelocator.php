<?php

namespace App\Tenancy;

use App\Portability\Bundle;
use App\Portability\PortabilityRunner;
use App\Tenancy\Models\Tenant;
use RuntimeException;
use Throwable;

/**
 * TEN-16: move one firm's database to another cell, as a routine operation rather than a rebuild.
 *
 *   read-only window on -> export (PLAT-9 bundle, data only: documents live in object storage, which is not per cell)
 *   -> new database on the target cell -> migrate -> import + verify (row hashes) -> switch the registry -> window off
 *
 * Any failure switches the firm back to its old database (which was never touched) and removes the half-built one.
 * The old database is kept unless $dropOld: it is the instant rollback, and the operator removes it when satisfied.
 * Writes are refused during the window (503 maintenance_read_only); reads continue against the old database.
 * Queued jobs that write (webhook deliveries, exports) during the window are not migrated: run with the firm's queue
 * drained, or accept that their bookkeeping rows stay in the old database.
 */
class TenantRelocator
{
    public function __construct(private CellRegistry $cells, private TenantLifecycle $lifecycle, private TenantMigrator $migrator, private PortabilityRunner $portability)
    {
    }

    /** @return array{from:string, to:string, old_database:string, new_database:string, tables:int, old_dropped:bool} */
    public function relocate(Tenant $tenant, string $toCell, bool $dropOld = false, bool $ignoreCapacity = false): array
    {
        if (! in_array($tenant->status, ['active', 'suspended'], true)) {
            throw new RuntimeException("Tenant [{$tenant->slug}] is {$tenant->status}; only active or suspended firms can be moved.");
        }
        $from = $tenant->cell ?: 'default';
        if ($from === $toCell) {
            throw new RuntimeException("Tenant [{$tenant->slug}] is already in cell [{$toCell}].");
        }
        $target = $this->cells->find($toCell);
        if (! $target && $toCell !== 'default') {
            throw new RuntimeException("No such cell: {$toCell}.");
        }
        if ($target && ! $ignoreCapacity) {
            if (! $target->accepting) {
                throw new RuntimeException("Cell [{$toCell}] is not accepting firms (use --ignore-capacity to move anyway).");
            }
            if (($this->cells->load()[$toCell] ?? 0) >= $target->capacity) {
                throw new RuntimeException("Cell [{$toCell}] is full ({$target->capacity}); raise its capacity or use --ignore-capacity.");
            }
        }
        if ($target?->region && $tenant->region && $target->region !== $tenant->region) {
            throw new RuntimeException("Cell [{$toCell}] is in region [{$target->region}]; this firm must stay in [{$tenant->region}] (TEN-15).");
        }

        $old = ['cell' => $tenant->cell, 'database' => $tenant->database];
        $newDatabase = $this->lifecycle->defaultDatabaseName($tenant->slug, $toCell);
        // Never "move" onto the database being moved: two cells on one server would make the rollback drop the original.
        if ($this->location($from, (string) $old['database']) === $this->location($toCell, $newDatabase)) {
            throw new RuntimeException("Cells [{$from}] and [{$toCell}] resolve to the same database for this firm; nothing to move.");
        }
        $zip = storage_path('app/relocations/'.$tenant->slug.'-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(4)).'.zip');

        $tenant->update(['read_only_reason' => 'This firm is being moved to a new server.']);
        $switched = false;
        try {
            $this->portability->exportZip($tenant->slug, $zip, withFiles: false);

            // TEN-2: background work follows the firm to its new cell's queue lane; files stay where they are (object
            // storage is not per cell), so files_disk is unchanged
            $tenant->update(['cell' => $toCell, 'database' => $newDatabase, 'queue' => app(CellRegistry::class)->find($toCell)?->queue ?: $tenant->queue]);
            $switched = true;
            $this->lifecycle->createDatabase($tenant);
            if (! $this->migrator->migrate($tenant->fresh())) {
                throw new RuntimeException('migrating the new database failed: '.$tenant->fresh()->migration_error);
            }
            $result = $this->portability->import($tenant->slug, Bundle::open($zip), ['replace_bootstrap' => true]);
        } catch (Throwable $e) {
            if ($switched) {
                $half = $tenant->replicate();
                $half->forceFill(['cell' => $toCell, 'database' => $newDatabase]);
                try {
                    $this->lifecycle->dropDatabase($half);
                } catch (Throwable) {
                }
                $tenant->update($old);
            }
            $tenant->update(['read_only_reason' => null]);
            throw new RuntimeException("Moving [{$tenant->slug}] to [{$toCell}] failed and was rolled back: ".$e->getMessage(), 0, $e);
        } finally {
            @unlink($zip);
        }

        $tenant->update(['read_only_reason' => null]);

        $dropped = false;
        if ($dropOld) {
            $previous = $tenant->replicate();
            $previous->forceFill($old);
            $this->lifecycle->dropDatabase($previous);
            $dropped = true;
        }

        return ['from' => $from, 'to' => $toCell, 'old_database' => (string) $old['database'], 'new_database' => $newDatabase,
            'tables' => count($result['tables']), 'old_dropped' => $dropped];
    }

    /** Where a database physically is: server + name (SQLite: the file path). */
    private function location(string $cell, string $database): string
    {
        $server = $this->cells->serverConfig($cell);

        return ($server['driver'] ?? '') === 'sqlite'
            ? (realpath($database) ?: $database)
            : strtolower(($server['host'] ?? '').':'.($server['port'] ?? '').'/'.$database);
    }
}
