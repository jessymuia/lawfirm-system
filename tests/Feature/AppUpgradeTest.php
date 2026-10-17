<?php

namespace Tests\Feature;

use App\Support\ReleaseLog;
use App\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** PLAT-6: app:upgrade is idempotent, guarded against downgrades, and never leaves the site down. */
class AppUpgradeTest extends TestCase implements \Tests\OwnsTenancy
{
    use RefreshDatabase;

    /** @var list<string> */
    public static array $called = [];

    private function upgrade(array $opts = [])
    {
        return $this->artisan('app:upgrade', array_merge(['--skip-cache' => true], $opts));
    }

    public function test_upgrade_records_the_release_and_is_idempotent(): void
    {
        config(['app.version' => '1.2.0']);

        $this->upgrade()->expectsOutputToContain('Upgraded to 1.2.0')->assertExitCode(0);
        $this->upgrade()->expectsOutputToContain('Already at 1.2.0')->assertExitCode(0);

        $this->assertSame(1, DB::table('app_releases')->count(), 'second run must not add a release row');
        $this->assertSame('1.2.0', ReleaseLog::current());
    }

    public function test_release_history_keeps_previous_version(): void
    {
        config(['app.version' => '1.0.0']);
        $this->upgrade()->assertExitCode(0);
        config(['app.version' => '1.1.0']);
        $this->upgrade()->assertExitCode(0);

        $last = DB::table('app_releases')->orderByDesc('id')->first();
        $this->assertSame('1.1.0', $last->version);
        $this->assertSame('1.0.0', $last->previous_version);
    }

    public function test_it_refuses_a_downgrade_beyond_the_previous_minor_unless_forced(): void
    {
        config(['app.version' => '2.0.0']);
        $this->upgrade()->assertExitCode(0);

        config(['app.version' => '1.9.0']);
        $this->upgrade()->expectsOutputToContain('Refusing to run')->assertExitCode(1);
        $this->assertSame('2.0.0', ReleaseLog::current(), 'a refused run records nothing');

        $this->upgrade(['--allow-downgrade' => true])->assertExitCode(0);
        $this->assertSame('1.9.0', ReleaseLog::current());

        config(['app.version' => '1.9.0']);
        DB::table('app_releases')->delete();
        $this->upgrade()->assertExitCode(0);
        config(['app.version' => '1.7.0']);
        $this->upgrade()->expectsOutputToContain('more than one minor')->assertExitCode(1);
    }

    public function test_rolling_back_to_the_previous_minor_or_patch_is_allowed_with_a_warning(): void
    {
        config(['app.version' => '1.3.0']);
        $this->upgrade()->assertExitCode(0);

        config(['app.version' => '1.2.4']);
        $this->upgrade()->expectsOutputToContain('supported rollback window')->assertExitCode(0);
        $this->assertSame('1.2.4', ReleaseLog::current());

        config(['app.version' => '1.2.3']);   // patch downgrade, same minor
        $this->upgrade()->assertExitCode(0);
    }

    public function test_downgrade_risk_classification(): void
    {
        $this->assertSame('none', ReleaseLog::downgradeRisk('1.0.0', '1.0.0'));
        $this->assertSame('none', ReleaseLog::downgradeRisk('1.0.0', '1.1.0'));
        $this->assertSame('warn', ReleaseLog::downgradeRisk('1.0.0', '1.0.0-rc.1'));
        $this->assertSame('warn', ReleaseLog::downgradeRisk('1.4.0', '1.3.9'));
        $this->assertSame('block', ReleaseLog::downgradeRisk('1.4.0', '1.2.0'));
        $this->assertSame('block', ReleaseLog::downgradeRisk('2.0.0', '1.9.9'));
        $this->assertSame('none', ReleaseLog::downgradeRisk('dev', '1.0.0'));
    }

    public function test_release_candidates_sort_before_the_final_release(): void
    {
        $this->assertLessThan(0, ReleaseLog::compare('1.0.0-rc.1', '1.0.0'));
        $this->assertLessThan(0, ReleaseLog::compare('1.0.0-rc.2', '1.0.0-rc.10'));
        $this->assertGreaterThan(0, ReleaseLog::compare('1.0.1', '1.0.0'));
        $this->assertSame(0, ReleaseLog::compare('dev', '9.9.9'));
    }

    public function test_dry_run_lists_pending_work_and_changes_nothing(): void
    {
        config(['app.version' => '1.2.0']);
        DB::table('migrations')->where('migration', '2026_09_30_170000_enforce_append_only_ledgers')->delete();

        $this->upgrade(['--dry-run' => true])
            ->expectsOutputToContain('Pending migrations: 1')
            ->expectsOutputToContain('2026_09_30_170000_enforce_append_only_ledgers')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('migrations')->where('migration', '2026_09_30_170000_enforce_append_only_ledgers')->count());
        $this->assertNull(ReleaseLog::current());
    }

    public function test_permission_catalog_is_resynced_into_admin(): void
    {
        $this->upgrade()->assertExitCode(0);
        $admin = Role::findByName('admin');
        $admin->revokePermissionTo('manage-license');
        Permission::where('name', 'manage-license')->exists() || $this->fail('catalog should hold manage-license');

        $this->upgrade()->assertExitCode(0);

        $this->assertTrue(Role::findByName('admin')->fresh()->hasPermissionTo('manage-license'));
    }

    public function test_cache_rebuild_runs_all_four_caches_and_queue_restart_unless_skipped(): void
    {
        self::$called = [];
        foreach (['config:cache', 'route:cache', 'view:cache', 'event:cache', 'queue:restart'] as $name) {
            Artisan::registerCommand(new class($name) extends Command {
                public function __construct(private string $n)
                {
                    $this->signature = $n;
                    parent::__construct();
                }

                public function handle(): int
                {
                    AppUpgradeTest::$called[] = $this->n;

                    return 0;
                }
            });
        }

        $this->artisan('app:upgrade')->expectsOutputToContain('Caches rebuilt')->assertExitCode(0);
        $this->assertSame(['config:cache', 'route:cache', 'view:cache', 'event:cache', 'queue:restart'], self::$called);

        self::$called = [];
        $this->artisan('app:upgrade', ['--skip-cache' => true])->assertExitCode(0);
        $this->assertSame(['queue:restart'], self::$called);
    }

    public function test_failed_migration_stops_before_recording_and_lifts_maintenance(): void
    {
        config(['app.version' => '3.0.0']);
        Artisan::registerCommand(new class extends Command {
            protected $signature = 'migrate {--force} {--database=} {--path=}';

            public function handle(): int
            {
                $this->error('boom');

                return 1;
            }
        });
        foreach (['down', 'up'] as $name) {
            Artisan::registerCommand(new class($name) extends Command {
                public function __construct(private string $n)
                {
                    $this->signature = $n.' {--retry=}';
                    parent::__construct();
                }

                public function handle(): int
                {
                    AppUpgradeTest::$called[] = $this->n;

                    return 0;
                }
            });
        }
        self::$called = [];

        $this->upgrade(['--maintenance' => true])
            ->expectsOutputToContain("Upgrade failed at step 'migrate'")
            ->expectsOutputToContain('deploy/rollback.sh')
            ->assertExitCode(1);

        $this->assertSame(['down', 'up'], self::$called, 'maintenance mode must always be lifted');
        $this->assertNull(ReleaseLog::current(), 'a failed upgrade must not record the new release');
    }

    public function test_preflight_blocks_without_app_key(): void
    {
        config(['app.key' => '']);
        $this->upgrade()->expectsOutputToContain('APP_KEY is not set')->assertExitCode(1);
    }

    public function test_multi_mode_migrates_control_then_tenants_and_logs_on_control(): void
    {
        $dir = sys_get_temp_dir().'/upg-'.uniqid();
        mkdir($dir);
        touch("$dir/control.sqlite");
        $original = config('database.default');
        config([
            'tenancy.mode' => 'multi',
            'app.version' => '1.2.0',
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "$dir/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
        ]);
        DB::purge('control');

        try {
            $this->upgrade()->expectsOutputToContain('Pending migrations: '.count(glob(database_path('migrations/control/*.php'))))->assertExitCode(0);
            $this->assertSame('1.2.0', DB::connection('control')->table('app_releases')->value('version'));
            $this->assertTrue(\Illuminate\Support\Facades\Schema::connection('control')->hasTable('tenants'));

            $this->upgrade()->expectsOutputToContain('Pending migrations: none')->assertExitCode(0);
        } finally {
            config(['tenancy.mode' => 'single']);
            DB::setDefaultConnection($original);
        }
    }
}
