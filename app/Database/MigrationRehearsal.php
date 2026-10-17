<?php

namespace App\Database;

use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * DATA-13: run migrations against a database at real volume and time each one, so a migration that would lock a large
 * firm out is caught before it ships. Used by `migrations:rehearse` against the largest firm's latest snapshot (SaaS),
 * or against synthetic volume (CI).
 */
class MigrationRehearsal
{
    public function __construct(private Migrator $migrator) {}

    /** @return list<string> migration files (full paths) of the firm schema, oldest first */
    public function files(): array
    {
        return array_values($this->migrator->getMigrationFiles([database_path('migrations')]));
    }

    /** @return list<string> files not yet run on $connection */
    public function pending(string $connection): array
    {
        return $this->migrator->usingConnection($connection, function () {
            $repo = $this->migrator->getRepository();
            $ran = $repo->repositoryExists() ? $repo->getRan() : [];

            return array_values(array_filter($this->files(), fn ($f) => ! in_array($this->migrator->getMigrationName($f), $ran, true)));
        });
    }

    /** Bring $connection up to (but not including) $files, untimed. */
    public function prepare(string $connection, array $files): void
    {
        $this->migrator->usingConnection($connection, function () use ($files) {
            if (! $this->migrator->repositoryExists()) {
                $this->migrator->getRepository()->createRepository();
            }
            $this->migrator->requireFiles($files);
            $this->migrator->runPending($files);
        });
    }

    /**
     * Run $files on $connection, timing each.
     *
     * @return list<array{migration: string, seconds: float}>
     */
    public function run(string $connection, array $files): array
    {
        $timings = [];
        $names = array_map(fn ($f) => $this->migrator->getMigrationName($f), $files);
        $i = 0;
        $start = 0.0;
        Event::listen(MigrationStarted::class, function () use (&$start) {
            $start = microtime(true);
        });
        Event::listen(MigrationEnded::class, function () use (&$timings, &$i, &$start, $names) {
            $timings[] = ['migration' => $names[$i++] ?? '?', 'seconds' => round(microtime(true) - $start, 3)];
        });
        $this->migrator->usingConnection($connection, function () use ($files) {
            $this->migrator->requireFiles($files);
            $this->migrator->runPending($files);
        });
        DB::purge($connection);

        return $timings;
    }
}
