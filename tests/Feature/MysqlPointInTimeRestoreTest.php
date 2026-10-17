<?php

namespace Tests\Feature;

use App\Backup\FirmRestore;
use App\Backup\FirmSnapshots;
use App\Models\Client;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TEN-14: point-in-time restore of one firm on a real MySQL/MariaDB server: snapshot, more work, a mistake, then the
 * firm brought back to the moment before the mistake by replaying its own binary-log events. Runs where the test
 * database is MySQL/MariaDB with the binary log on and mysqldump/mysqlbinlog installed (the backend-mysql CI job);
 * skipped elsewhere.
 */
class MysqlPointInTimeRestoreTest extends TestCase implements \Tests\OwnsTenancy
{
    private string $dir;

    private string $originalDefault;

    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs the test database on MySQL/MariaDB.');
        }
        if (strtoupper((string) (DB::selectOne("SHOW VARIABLES LIKE 'log_bin'")->Value ?? '')) !== 'ON' || ! trim((string) shell_exec('command -v mysqlbinlog'))) {
            $this->markTestSkipped('Needs the binary log on and mysqlbinlog installed.');
        }
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/pitr-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => $this->originalDefault,
            'tenancy.storage_disks' => ['local'],
            'backup.disk' => 'backups',
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
        Storage::fake('local');
        Storage::fake('backups');
        config(['tenancy.mode' => 'multi']);
    }

    protected function tearDown(): void
    {
        if (isset($this->originalDefault)) {
            config(['tenancy.mode' => 'single']);
            DB::setDefaultConnection($this->originalDefault);
            app(TenantContext::class)->activate('default');
        }
        if ($this->tenant) {
            foreach (DB::connection($this->originalDefault)->select("SHOW DATABASES LIKE 'pitr\\_firm%'") as $row) {
                DB::connection($this->originalDefault)->statement('DROP DATABASE IF EXISTS `'.array_values((array) $row)[0].'`');
            }
        }
        parent::tearDown();
    }

    private function in(\Closure $fn): mixed
    {
        return app(TenantManager::class)->runIn($this->tenant->fresh(), $fn, allowInactive: true);
    }

    public function test_a_firm_is_brought_back_to_the_moment_before_a_mistake(): void
    {
        $this->tenant = app(TenantLifecycle::class)->provision(['slug' => 'pitr', 'name' => 'PITR', 'admin_email' => 'admin@pitr.test',
            'domain' => 'pitr.app.test', 'database' => 'pitr_firm_'.substr(uniqid(), -6)])['tenant'];
        $this->in(fn () => Client::factory()->create(['name' => 'Before the snapshot']));
        $snapshot = app(FirmSnapshots::class)->take($this->tenant);
        $this->assertNotNull($snapshot['binlog'], 'the dump records where in the binary log it is consistent');

        sleep(1);
        $this->in(fn () => Client::factory()->create(['name' => 'After the snapshot']));
        sleep(1);
        $beforeMistake = now()->toDateTimeString();
        sleep(1);
        $this->in(fn () => DB::table('clients')->delete());   // the mistake
        $this->assertSame(0, $this->in(fn () => Client::count()));

        $restore = app(FirmRestore::class);
        $r = $restore->restore($this->tenant, $beforeMistake);
        $this->assertTrue($r['pitr']);
        $this->assertSame(2, $r['counts']['clients'], 'the work after the snapshot is replayed, the mistake is not');

        $restore->switchTo($this->tenant, $r);
        $this->assertSame(['Before the snapshot', 'After the snapshot'], $this->in(fn () => Client::orderBy('id')->pluck('name')->all()));

        // to the snapshot alone: only what was there then
        $only = $restore->restore($this->tenant->fresh(), null, $snapshot['id']);
        $this->assertSame(1, $only['counts']['clients']);
        $restore->discard($only);

        // and the weekly drill exercises the same path: latest snapshot, rolled forward to now
        $this->assertSame(0, Artisan::call('tenants:restore-drill', ['--tenant' => 'pitr']), Artisan::output().DB::connection('control')->table('tenant_restores')->where('kind', 'drill')->value('report'));
        $report = json_decode(DB::connection('control')->table('tenant_restores')->where('kind', 'drill')->value('report'), true);
        $this->assertSame(['point-in-time', true, true, true], [$report['mode'], $report['checks']['files_consistent'], $report['checks']['tables_present'], $report['checks']['app_reads']]);
    }
}
