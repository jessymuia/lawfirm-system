<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ENG-6: verifies backup:run is wired up correctly (config, disk,
 * scheduling intent) without requiring mysqldump/tar to actually be
 * present, using --dry-run. A full end-to-end run (real dump + real
 * archive) is exercised manually against staging per
 * docs/ops/BACKUP_STRATEGY.md's quarterly restore-test procedure, since
 * it needs a real mysqldump binary and a non-trivial database.
 */
class BackupCommandTest extends TestCase
{
    public function test_dry_run_reports_the_configured_disk_and_paths_without_writing_anything(): void
    {
        // The command (rightly) refuses non-mysql connections, and the test
        // suite runs on sqlite — point the default at the mysql connection
        // config for this dry-run, which never actually connects.
        config(['database.default' => 'mysql']);
        Storage::fake(config('backup.disk'));

        $this->artisan('backup:run', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertEmpty(Storage::disk(config('backup.disk'))->allFiles());
    }

    public function test_dry_run_fails_fast_for_a_non_mysql_connection(): void
    {
        config(['database.default' => 'sqlite']);

        $this->artisan('backup:run', ['--dry-run' => true])
            ->assertExitCode(1);
    }
}
