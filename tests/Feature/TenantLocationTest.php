<?php

namespace Tests\Feature;

use App\Tenancy\FirmStorage;
use App\Tenancy\Models\Cell;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** TEN-2: a firm's registry row says where its data, files and background work live, and the app follows it. */
class TenantLocationTest extends TestCase implements \Tests\OwnsTenancy
{
    use RefreshDatabase;

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/ten2-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
            'tenancy.storage_disks' => ['local'],
            'portability.directory' => $this->dir,
            'filesystems.disks.eu-bucket' => ['driver' => 'local', 'root' => "{$this->dir}/eu-bucket"],
            'queue.default' => 'database',
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
    }

    protected function tearDown(): void
    {
        config(['tenancy.mode' => 'single']);
        app(TenantManager::class)->failClosed();
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

    public function test_provisioning_records_files_and_queue_from_the_cell_or_the_platform_default(): void
    {
        $this->cell('eu-1', ['region' => 'eu', 'files_disk' => 'eu-bucket', 'queue' => 'eu']);
        $this->cell('ke-1', ['region' => 'ke']);

        $eu = $this->provision('eurofirm', ['region' => 'eu']);
        $ke = $this->provision('kefirm', ['region' => 'ke']);

        $this->assertSame(['eu-1', 'eu-bucket', 'eu'], [$eu->cell, $eu->files_disk, $eu->queue]);
        $this->assertSame(['ke-1', FirmStorage::defaultDisk(), FirmStorage::defaultQueue()], [$ke->cell, $ke->files_disk, $ke->queue]);
        $this->assertStringStartsWith("{$this->dir}/eu-1/", $eu->database, 'and its data on its cell');
    }

    public function test_while_a_firm_is_active_its_files_disk_and_queue_are_the_ones_in_its_row(): void
    {
        $this->cell('eu-1', ['files_disk' => 'eu-bucket', 'queue' => 'eu', 'capacity' => 1]);
        $this->cell('main');
        $eu = $this->provision('eurofirm');
        $home = $this->provision('homefirm');
        config(['tenancy.mode' => 'multi']);
        $manager = app(TenantManager::class);
        $manager->useControlPlaneQueue();   // what boot does in multi mode
        $queueOf = fn () => DB::connection('control')->table('jobs')->orderByDesc('id')->value('queue');

        $manager->activate($eu);
        $this->assertSame('eu-bucket', FirmStorage::disk());
        dispatch(new \App\Jobs\SendSmsJob('0700000000', 'x'));
        $this->assertSame('eu', $queueOf(), "the firm's background work goes to its lane");
        dispatch(new \App\Jobs\BackfillPublicIds);
        $this->assertSame('eu-bulk', $queueOf(), 'and its heavy work to the bulk lane next to it (ENG-20)');

        $manager->activate($home);
        $this->assertSame(FirmStorage::defaultDisk(), FirmStorage::disk());
        dispatch(new \App\Jobs\SendSmsJob('0700000000', 'x'));
        $this->assertSame('default', $queueOf());
        dispatch(new \App\Jobs\BackfillPublicIds);
        $this->assertSame('bulk', $queueOf());

        $manager->activate($eu);
        $manager->failClosed();
        $this->assertSame(FirmStorage::defaultDisk(), FirmStorage::disk(), 'no firm, no firm disk');
        $this->assertSame('default', config('queue.connections.database.queue'));
    }

    public function test_an_upload_lands_on_the_firms_disk_under_its_prefix(): void
    {
        $this->cell('eu-1', ['files_disk' => 'eu-bucket']);
        $firm = $this->provision('eurofirm');
        config(['tenancy.mode' => 'multi', 'tenancy.base_domain' => 'app.test']);
        $firm->domains()->create(['domain' => 'eurofirm.app.test']);
        $manager = app(TenantManager::class);
        $user = $manager->runIn($firm, fn () => \App\Models\User::first());
        $case = $manager->runIn($firm, fn () => \App\Models\Cases::factory()->create());

        $this->actingAsOnHost($user, 'eurofirm.app.test');
        $id = $this->postJson('http://eurofirm.app.test/api/v1/documents', [
            'title' => 'Engagement letter', 'case_id' => $case->id,
            'file' => \Illuminate\Http\UploadedFile::fake()->create('letter.pdf', 10, 'application/pdf'),
        ])->assertSuccessful()->json('data.id');

        $doc = $manager->runIn($firm, fn () => DB::table('documents')->find($id));
        $this->assertSame('eu-bucket', $doc->disk);
        $this->assertStringStartsWith('tenants/eurofirm/', $doc->full_path);
        Storage::disk('eu-bucket')->assertExists($doc->full_path);
    }

    public function test_purge_clears_the_firms_own_disk_and_relocation_moves_its_queue_but_not_its_files(): void
    {
        $this->cell('eu-1', ['files_disk' => 'eu-bucket', 'queue' => 'eu']);
        $this->cell('eu-2', ['queue' => 'eu-2']);
        config(['tenancy.mode' => 'multi']);
        app(TenantManager::class)->useControlPlaneQueue();
        $firm = $this->provision('mover', ['cell' => 'eu-1']);

        app(\App\Tenancy\TenantRelocator::class)->relocate($firm->fresh(), 'eu-2');
        $firm->refresh();
        $this->assertSame(['eu-2', 'eu-2', 'eu-bucket'], [$firm->cell, $firm->queue, $firm->files_disk]);

        Storage::disk('eu-bucket')->put('tenants/mover/cases/docs/x.pdf', 'x');
        Storage::disk('eu-bucket')->put('tenants/other/cases/docs/y.pdf', 'y');
        app(TenantLifecycle::class)->offboard($firm, 0);   // queues the final export on the platform queue, with no firm active
        $this->assertTrue(DB::connection('control')->table('jobs')->where('payload', 'like', '%ExportOffboardedTenant%')->exists());
        app(TenantLifecycle::class)->purge($firm->fresh(), 'test firm');

        Storage::disk('eu-bucket')->assertMissing('tenants/mover/cases/docs/x.pdf');
        Storage::disk('eu-bucket')->assertExists('tenants/other/cases/docs/y.pdf');
        $this->assertSame('deleted', $firm->fresh()->purge_report['files']['eu-bucket']);
    }

    public function test_cells_take_a_files_disk_and_a_queue(): void
    {
        $this->assertSame(0, Artisan::call('cells', ['action' => 'add', 'name' => 'eu-1', '--files-disk' => 'eu-bucket', '--queue' => 'eu']));
        $this->assertSame(['eu-bucket', 'eu'], [Cell::where('name', 'eu-1')->value('files_disk'), Cell::where('name', 'eu-1')->value('queue')]);
        $this->assertSame(1, Artisan::call('cells', ['action' => 'update', 'name' => 'eu-1', '--files-disk' => 'nope']), 'an unknown disk is refused');
        $this->assertSame(1, Artisan::call('cells', ['action' => 'update', 'name' => 'eu-1', '--queue' => 'Bad Name']));
        Artisan::call('cells', ['action' => 'list']);
        $this->assertStringContainsString('eu-bucket', Artisan::output());
    }
}
