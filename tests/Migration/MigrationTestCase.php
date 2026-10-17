<?php

namespace Tests\Migration;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Tests\CreatesApplication;

abstract class MigrationTestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * §3.3/§6 Phase 3: these tests compare a live 'source' (pre-migration)
     * database against the live default ('destination') connection — they
     * cannot run against the app's normal in-memory sqlite test database,
     * and deliberately do NOT use RefreshDatabase (that would wipe the
     * very data being verified).
     *
     * Outside an actual migration event there is no source database to
     * compare against, so every test in this suite skips itself with a
     * clear message rather than failing — a green run of `php artisan
     * test` (the Unit/Feature suites) must never depend on this suite,
     * and this suite must never silently report false confidence when
     * it hasn't actually checked anything.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! config('database.connections.source.database')) {
            $this->markTestSkipped(
                'No source database configured (DB_SOURCE_DATABASE) — this suite only runs '.
                'during an actual migration event (§6 Phase 3), pointed at the real pre-migration '.
                'database. See docs/ops/DEPLOYMENT.md and the migration plan\'s §6 for how this fits '.
                'into the overall procedure.'
            );
        }
    }

    protected function sourceConnection(): Connection
    {
        return DB::connection('source');
    }

    protected function destinationConnection(): Connection
    {
        // The app's own default connection — the freshly-migrated
        // "new system" database.
        return DB::connection(config('database.default'));
    }
}
