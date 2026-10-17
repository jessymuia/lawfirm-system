<?php

namespace Tests\Feature;

use App\Backup\FirmRestore;
use App\Backup\FirmSnapshots;
use App\Backup\RestoreDrill;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Document;
use App\Tenancy\Keys\FirmKeys;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** TEN-14: a firm restored on its own, its database and files as one consistent pair, and drills that prove it. */
class FirmRestoreTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/restore-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
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
        config(['tenancy.mode' => 'single', 'tenancy.encryption.master_key' => null]);
        app(FirmKeys::class)->forget();
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function firm(string $slug = 'acme'): Tenant
    {
        return app(TenantLifecycle::class)->provision(['slug' => $slug, 'name' => ucfirst($slug), 'admin_email' => "admin@{$slug}.test",
            'domain' => "{$slug}.app.test", 'database' => "{$this->dir}/{$slug}.sqlite"])['tenant'];
    }

    private function in(Tenant $t, \Closure $fn): mixed
    {
        return app(TenantManager::class)->runIn($t->fresh(), $fn, allowInactive: true);
    }

    /** A client with a matter and a filed document. */
    private function seedWork(Tenant $t, string $name): Document
    {
        return $this->in($t, function () use ($t, $name) {
            $client = Client::factory()->create(['name' => $name]);
            $matter = Cases::factory()->create(['client_id' => $client->id]);
            $path = "tenants/{$t->slug}/cases/docs/{$name}.pdf";
            Storage::disk('local')->put($path, "the {$name} pleading");

            return Document::create(['case_id' => $matter->id, 'title' => $name, 'filename' => "{$name}.pdf", 'filepath' => $path, 'full_path' => $path, 'disk' => 'local',
                'mimetype' => 'application/pdf', 'filesize' => 20, 'extension' => 'pdf', 'version' => 1, 'is_current' => true]);
        });
    }

    public function test_a_snapshot_is_the_database_and_the_files_together_with_a_manifest(): void
    {
        $t = $this->firm();
        $this->seedWork($t, 'Wanjiru');
        $this->seedWork($this->firm('bolt'), 'Otieno');

        $this->assertSame(0, Artisan::call('tenants:snapshot', ['slug' => 'acme']));
        $m = app(FirmSnapshots::class)->latest($t);
        $this->assertSame(['acme', 'sqlite', 1, 1, 1, false], [$m['tenant'], $m['driver'], $m['tables']['clients'], $m['files']['local']['files'], $m['file_references'], $m['firm_key_encrypted']]);
        $this->assertNull($m['binlog'], 'SQLite keeps no binary log: snapshot restores only');
        Storage::disk('backups')->assertExists("tenants/acme/snapshots/{$m['id']}/database.sql.gz");
        $this->assertSame([], Storage::disk('backups')->files('tenants/bolt'), 'one firm only');

        $zip = new \ZipArchive;
        $zip->open(Storage::disk('backups')->path("tenants/acme/snapshots/{$m['id']}/files-local.zip"));
        $this->assertSame('the Wanjiru pleading', $zip->getFromName('tenants/acme/cases/docs/Wanjiru.pdf'));
        $this->assertFalse($zip->getFromName('tenants/bolt/cases/docs/Otieno.pdf'), 'never another firm\'s files');
        $zip->close();
    }

    public function test_a_firm_is_restored_with_its_files_and_nobody_else_is_touched(): void
    {
        $t = $this->firm();
        $doc = $this->seedWork($t, 'Wanjiru');
        $bolt = $this->firm('bolt');
        $this->seedWork($bolt, 'Otieno');
        app(FirmSnapshots::class)->take($t);

        // the mistake: the client is deleted and the file with it
        $this->travel(5)->minutes();
        $this->in($t, fn () => DB::table('documents')->delete() + DB::table('cases')->delete() + DB::table('clients')->delete());
        Storage::disk('local')->delete($doc->full_path);
        $this->seedWork($bolt, 'Kamau');   // another firm keeps working meanwhile

        $this->assertSame(1, Artisan::call('tenants:restore', ['slug' => 'acme', '--switch' => true]), 'switching needs the slug confirmed');
        $this->assertSame(0, Artisan::call('tenants:restore', ['slug' => 'acme', '--switch' => true, '--confirm' => 'acme']));
        $this->assertStringContainsString('1 referenced, 0 present, 1 recoverable, 0 missing', Artisan::output());

        $t = $t->fresh();
        $this->assertStringContainsString('acme_r', $t->database);
        $this->assertSame(['Wanjiru'], $this->in($t, fn () => Client::pluck('name')->all()));
        $this->assertSame('the Wanjiru pleading', Storage::disk('local')->get($doc->full_path), 'its file is back');
        $this->assertSame('active', $t->status);
        $this->assertSame(['Otieno', 'Kamau'], $this->in($bolt, fn () => Client::pluck('name')->all()), 'the other firm is untouched');

        $row = DB::connection('control')->table('tenant_restores')->where('kind', 'switch')->first();
        $this->assertSame(['switched', "{$this->dir}/acme.sqlite"], [$row->outcome, $row->previous_database]);
        $this->assertFileExists("{$this->dir}/acme.sqlite", 'the previous database is kept for rolling back');
    }

    public function test_a_restore_whose_files_cannot_be_recovered_is_reported_and_never_switched_in(): void
    {
        $t = $this->firm();
        $doc = $this->seedWork($t, 'Wanjiru');
        $m = app(FirmSnapshots::class)->take($t);
        Storage::disk('local')->delete($doc->full_path);
        Storage::disk('backups')->delete("tenants/acme/snapshots/{$m['id']}/files-local.zip");   // and no archive to take it from

        $this->assertSame(1, Artisan::call('tenants:restore', ['slug' => 'acme', '--switch' => true, '--confirm' => 'acme']));
        $out = Artisan::output();
        $this->assertStringContainsString("missing: [local] {$doc->full_path} (documents)", $out);
        $this->assertStringContainsString('cannot be recovered; it was not switched in', $out);
        $this->assertSame("{$this->dir}/acme.sqlite", $t->fresh()->database);
        $this->assertCount(0, glob("{$this->dir}/acme_r*.sqlite"), 'the inconsistent copy was dropped');
    }

    public function test_a_point_in_time_needs_a_binary_log(): void
    {
        $t = $this->firm();
        app(FirmSnapshots::class)->take($t);
        $this->assertSame(1, Artisan::call('tenants:restore', ['slug' => 'acme', '--to' => now()->addHour()->toDateTimeString()]));
        $this->assertStringContainsString('needs the binary log', Artisan::output());
        $this->assertSame(1, Artisan::call('tenants:restore', ['slug' => 'acme', '--to' => now()->subDay()->toDateTimeString()]));
        $this->assertStringContainsString('has no snapshot taken at or before', Artisan::output());
    }

    public function test_drills_restore_check_drop_and_log_and_a_firm_without_a_snapshot_fails_its_drill(): void
    {
        $t = $this->firm();
        $this->seedWork($t, 'Wanjiru');
        $bolt = $this->firm('bolt');
        app(FirmSnapshots::class)->take($t);

        $this->assertSame(0, Artisan::call('tenants:restore-drill', ['--tenant' => 'acme']));
        $this->assertStringContainsString('acme: drill PASSED', Artisan::output());
        $row = DB::connection('control')->table('tenant_restores')->where('kind', 'drill')->first();
        $report = json_decode($row->report, true);
        $this->assertSame(['files_consistent' => true, 'tables_present' => true, 'rows_match_snapshot' => true, 'app_reads' => true], $report['checks']);
        $this->assertSame([1, 'snapshot'], [$report['clients_read'], $report['mode']]);
        $this->assertCount(0, glob("{$this->dir}/acme_r*.sqlite"), 'the drill copy is dropped');

        // the scheduler picks the firm drilled longest ago: bolt, never drilled, which has no snapshot
        $this->assertSame('bolt', app(RestoreDrill::class)->next()->slug);
        $this->assertSame(1, Artisan::call('tenants:restore-drill'));
        $this->assertStringContainsString('bolt: drill FAILED', Artisan::output());
        $this->assertSame('No snapshot to restore from.', json_decode(DB::connection('control')->table('tenant_restores')->where('tenant_id', $bolt->id)->value('report'), true)['error']);

        Artisan::call('tenants:restore-drill', ['--list' => true]);
        $this->assertStringContainsString('passed', Artisan::output());
    }

    public function test_a_firm_with_its_own_key_has_encrypted_snapshots_that_still_restore(): void
    {
        config(['tenancy.encryption.master_key' => 'base64:'.base64_encode(random_bytes(32))]);
        app(FirmKeys::class)->forget();
        $t = $this->firm();
        $this->seedWork($t, 'Wanjiru');
        $m = app(FirmSnapshots::class)->take($t);
        $this->assertTrue($m['firm_key_encrypted']);
        Storage::disk('backups')->assertExists("tenants/acme/snapshots/{$m['id']}/database.sql.gz.enc");
        Storage::disk('backups')->assertMissing("tenants/acme/snapshots/{$m['id']}/database.sql.gz");

        $r = app(FirmRestore::class)->restore($t);
        $this->assertSame(1, $r['counts']['clients']);
        app(FirmRestore::class)->discard($r);
    }

    public function test_a_self_hosted_install_has_nothing_to_snapshot_or_drill(): void
    {
        config(['tenancy.mode' => 'single']);
        $this->assertSame(0, Artisan::call('tenants:snapshot', ['--all' => true]));
        $this->assertStringContainsString('Nothing to do', Artisan::output());
        $this->assertSame(0, Artisan::call('tenants:restore-drill'));
    }
}
