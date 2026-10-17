<?php

namespace Tests\Feature;

use App\Http\Controllers\PlatformConsoleController;
use App\Models\Client;
use App\Models\User;
use App\Tenancy\CellRegistry;
use App\Tenancy\Models\Cell;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantRelocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** TEN-4 cells and placement, TEN-15 region pinning, TEN-16 relocation between cells. Real SQLite databases. */
class CellsTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/cells-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
            'tenancy.storage_disks' => ['local'],
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
    }

    protected function tearDown(): void
    {
        config(['tenancy.mode' => 'single']);
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function cell(string $name, array $attrs = []): Cell
    {
        @mkdir("{$this->dir}/{$name}");

        return Cell::create(['name' => $name, 'sqlite_directory' => "{$this->dir}/{$name}"] + $attrs);
    }

    private function provision(string $slug, array $extra = []): Tenant
    {
        return app(TenantLifecycle::class)->provision($extra + ['slug' => $slug, 'name' => $slug, 'admin_email' => "a@{$slug}.test"])['tenant'];
    }

    // ---- placement ----------------------------------------------------------------------------------------------

    public function test_without_cells_everything_goes_to_the_template_default(): void
    {
        $this->assertSame('default', app(CellRegistry::class)->place());
        $this->expectExceptionMessage('No cell serves region [eu]');
        app(CellRegistry::class)->place('eu');
    }

    public function test_a_new_firm_goes_to_the_least_loaded_accepting_cell_with_room(): void
    {
        $this->cell('a', ['capacity' => 2]);
        $this->cell('b', ['capacity' => 10]);
        $this->cell('closed', ['capacity' => 100, 'accepting' => false]);

        $first = $this->provision('one');
        $this->assertSame('a', $first->cell, 'both empty: the name breaks the tie');
        $this->assertStringStartsWith("{$this->dir}/a/", $first->database, 'the database lives on its cell');
        $this->assertSame('b', $this->provision('two')->cell, 'a is now 50% full, b 0%');

        Cell::where('name', 'b')->update(['accepting' => false]);
        $this->assertSame('a', $this->provision('three')->cell);
        $this->expectExceptionMessage('No cell is accepting firms or has room');
        $this->provision('four');   // a is full (2/2), b and closed are not accepting
    }

    public function test_region_pinning_keeps_a_firm_in_its_region(): void
    {
        $this->cell('ke-1', ['region' => 'ke']);
        $this->cell('eu-1', ['region' => 'eu']);

        $this->assertSame('eu-1', $this->provision('eurofirm', ['region' => 'eu'])->cell);
        $this->assertSame('ke-1', $this->provision('kefirm', ['region' => 'ke'])->cell);
        $this->expectExceptionMessage('No cell in region [us]');
        $this->provision('usfirm', ['region' => 'us']);
    }

    public function test_a_cell_overrides_the_server_and_never_stores_the_password(): void
    {
        config(['database.connections.mysql_tpl' => ['driver' => 'mysql', 'host' => 'db-main', 'port' => 3306, 'username' => 'app', 'password' => 'main-secret', 'database' => 'x'],
            'tenancy.tenant_connection_template' => 'mysql_tpl', 'tenancy.cell_passwords' => ['CELL_PASSWORD_EU1' => 'eu-secret']]);
        Cell::create(['name' => 'eu-1', 'host' => 'db-eu-1', 'port' => 3307, 'password_env' => 'CELL_PASSWORD_EU1']);
        $tenant = new Tenant(['slug' => 'f', 'cell' => 'eu-1', 'database' => 'tenant_f']);

        $c = app(CellRegistry::class)->connectionConfig($tenant);

        $this->assertSame(['db-eu-1', 3307, 'app', 'eu-secret', 'tenant_f'], [$c['host'], $c['port'], $c['username'], $c['password'], $c['database']]);
        $this->assertNull(Cell::where('name', 'eu-1')->value('password'));
        $this->assertSame('db-main', app(CellRegistry::class)->connectionConfig(new Tenant(['cell' => 'default', 'database' => 'd']))['host']);

        Cell::create(['name' => 'broken', 'password_env' => 'CELL_PASSWORD_MISSING']);
        $this->expectExceptionMessage('CELL_PASSWORD_MISSING is not set');
        app(CellRegistry::class)->serverConfig('broken');
    }

    public function test_cells_are_managed_from_the_command_line(): void
    {
        $this->assertSame(0, Artisan::call('cells', ['action' => 'add', 'name' => 'eu-1', '--host' => 'db-eu-1', '--region' => 'eu', '--capacity' => 400, '--password-env' => 'CELL_PASSWORD_EU1']));
        $this->assertSame(1, Artisan::call('cells', ['action' => 'add', 'name' => 'eu-1']), 'no duplicates');
        $this->assertSame(1, Artisan::call('cells', ['action' => 'add', 'name' => 'x-1', '--password-env' => 'DB_PASSWORD']), 'only CELL_PASSWORD_* names');
        $this->assertSame(0, Artisan::call('cells', ['action' => 'update', 'name' => 'eu-1', '--accepting' => '0']));
        $this->assertFalse(Cell::where('name', 'eu-1')->first()->accepting);
        $this->assertSame(0, Artisan::call('cells', ['action' => 'list']));
        $this->assertStringContainsString('eu-1', Artisan::output());
    }

    // ---- relocation -----------------------------------------------------------------------------------------------

    public function test_a_firm_moves_to_another_cell_with_its_data_and_keeps_working(): void
    {
        $this->cell('a');
        $this->cell('b');
        config(['tenancy.mode' => 'multi']);
        $tenant = $this->provision('mover', ['cell' => 'a']);
        $manager = app(TenantManager::class);
        $manager->runIn($tenant, fn () => Client::factory()->count(3)->create());
        $users = $manager->runIn($tenant, fn () => DB::table('users')->orderBy('id')->pluck('email')->all());
        $oldDb = $tenant->database;

        $r = app(TenantRelocator::class)->relocate($tenant->fresh(), 'b');

        $tenant = $tenant->fresh();
        $this->assertSame(['b', null], [$tenant->cell, $tenant->read_only_reason]);
        $this->assertStringStartsWith("{$this->dir}/b/", $tenant->database);
        $this->assertSame(3, $manager->runIn($tenant, fn () => Client::count()), 'the data came along');
        $this->assertFileExists($oldDb, 'the old database is kept as the rollback');
        $this->assertFalse($r['old_dropped']);
        $this->assertSame($users, $manager->runIn($tenant, fn () => DB::table('users')->orderBy('id')->pluck('email')->all()), 'its people came along, so they can still sign in');
    }

    public function test_a_failed_move_is_rolled_back_to_the_untouched_old_database(): void
    {
        $this->cell('a');
        $this->cell('b');
        config(['tenancy.mode' => 'multi']);
        $tenant = $this->provision('stay', ['cell' => 'a']);
        app(TenantManager::class)->runIn($tenant, fn () => Client::factory()->create(['name' => 'Kept Ltd']));
        $old = [$tenant->cell, $tenant->database];
        // sabotage the target: its directory cannot hold a database file
        rmdir("{$this->dir}/b");
        file_put_contents("{$this->dir}/b", 'not a directory');

        try {
            app(TenantRelocator::class)->relocate($tenant->fresh(), 'b');
            $this->fail('the move should have failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rolled back', $e->getMessage());
        }

        $tenant = $tenant->fresh();
        $this->assertSame($old, [$tenant->cell, $tenant->database]);
        $this->assertNull($tenant->read_only_reason, 'the read-only window always closes');
        $this->assertSame('Kept Ltd', app(TenantManager::class)->runIn($tenant, fn () => Client::value('name')));
    }

    public function test_relocation_guards(): void
    {
        $this->cell('a', ['region' => 'ke']);
        $this->cell('eu', ['region' => 'eu']);
        $this->cell('full', ['region' => 'ke', 'capacity' => 1]);
        config(['tenancy.mode' => 'multi']);
        $this->provision('occupant', ['cell' => 'full', 'region' => 'ke']);
        $tenant = $this->provision('g', ['cell' => 'a', 'region' => 'ke']);
        $relocator = app(TenantRelocator::class);

        foreach ([['a', 'already in cell'], ['eu', 'must stay in [ke]'], ['full', 'is full'], ['nowhere', 'No such cell']] as [$to, $msg]) {
            try {
                $relocator->relocate($tenant->fresh(), $to);
                $this->fail("moved to {$to}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
        $this->assertSame('a', $tenant->fresh()->cell);
    }

    public function test_writes_are_refused_during_the_read_only_window_and_reads_continue(): void
    {
        config(['tenancy.mode' => 'multi', 'tenancy.base_domain' => 'app.test']);
        $this->cell('a');   // keeps the firm's database in this test's temp directory, not database/tenants/
        $tenant = $this->provision('busy', ['domain' => 'busy.app.test']);
        $tenant->update(['read_only_reason' => 'This firm is being moved to a new server.']);
        $user = app(TenantManager::class)->runIn($tenant, fn () => User::first());

        $this->actingAsOnHost($user, 'busy.app.test');
        $this->postJson('http://busy.app.test/api/v1/clients', ['name' => 'X'])->assertStatus(503)->assertJsonPath('code', 'maintenance_read_only')->assertHeader('Retry-After');
        $this->getJson('http://busy.app.test/api/v1/profile')->assertOk();
    }

    public function test_the_platform_console_lists_cells_with_their_load(): void
    {
        $this->cell('a', ['capacity' => 4, 'region' => 'ke']);
        $this->provision('x', ['cell' => 'a']);

        $rows = collect(app(PlatformConsoleController::class)->cells(app(CellRegistry::class))->getData(true)['data']);

        $this->assertSame(['name' => 'a', 'region' => 'ke', 'host' => null, 'capacity' => 4, 'accepting' => true, 'firms' => 1, 'utilisation' => 0.25, 'files_disk' => null, 'queue' => null], $rows->firstWhere('name', 'a'));
    }

    /** ENG-23: a cell names the read replica its firms' reports use; "none" takes it away. */
    public function test_a_cell_names_its_read_replica(): void
    {
        $this->assertSame(0, Artisan::call('cells', ['action' => 'add', 'name' => 'eu-1', '--host' => 'db-eu-1', '--replica-host' => 'db-eu-1-ro', '--replica-port' => 3310]));
        $this->assertSame(['db-eu-1-ro', 3310], [Cell::where('name', 'eu-1')->value('replica_host'), Cell::where('name', 'eu-1')->first()->replica_port]);
        Artisan::call('cells', ['action' => 'list']);
        $this->assertStringContainsString('db-eu-1-ro:3310', Artisan::output());

        $this->assertSame(0, Artisan::call('cells', ['action' => 'update', 'name' => 'eu-1', '--replica-host' => 'none']));
        $this->assertNull(Cell::where('name', 'eu-1')->first()->replica_host);
        $this->assertSame('db-eu-1', Cell::where('name', 'eu-1')->value('host'), 'the rest of the cell is untouched');
    }
}
