<?php

namespace Tests\Feature;

use App\Backup\FirmSnapshots;
use App\Database\VolumeFiller;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** DATA-13: migrations are timed against volume before they ship, and one over its budget fails. */
class MigrationRehearsalTest extends TestCase implements OwnsTenancy
{
    public function test_the_newest_migrations_run_against_synthetic_volume_and_are_timed(): void
    {
        $before = glob(sys_get_temp_dir().'/rehearsal_*.sqlite');
        $this->assertSame(0, Artisan::call('migrations:rehearse', ['--synthetic' => 50, '--last' => 3, '--budget' => 60]));
        $out = Artisan::output();
        $this->assertStringContainsString('Rehearsing 3 migration(s) against', $out);
        $this->assertStringContainsString('2026_10_28_100000_add_saml_to_sso_providers', $out);
        $this->assertSame($before, glob(sys_get_temp_dir().'/rehearsal_*.sqlite'), 'the scratch database is dropped');
    }

    public function test_a_migration_over_budget_fails_the_rehearsal(): void
    {
        $this->assertSame(1, Artisan::call('migrations:rehearse', ['--synthetic' => 10, '--last' => 1, '--budget' => 0]));
        $this->assertStringContainsString('over the 0s budget: split it, or move the data work into a queued backfill', Artisan::output());
    }

    public function test_the_filler_writes_valid_rows_with_real_parents_and_enum_values(): void
    {
        $file = sys_get_temp_dir().'/filler-'.uniqid().'.sqlite';
        touch($file);
        config(['database.connections.filler' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]]);
        Artisan::call('migrate', ['--database' => 'filler', '--force' => true]);
        $done = (new VolumeFiller)->fill(DB::connection('filler'), 30, 7);

        $this->assertArrayHasKey('invoice_items', $done);
        foreach (['clients', 'cases', 'invoices', 'invoice_items', 'audits'] as $table) {
            $this->assertSame(30, DB::connection('filler')->table($table)->count(), $table);
        }
        $this->assertSame(['open'], DB::connection('filler')->table('cases')->distinct()->pluck('lifecycle_status')->all(), 'DATA-12 enum values');
        $this->assertSame(['high'], DB::connection('filler')->table('tasks')->distinct()->pluck('priority')->all());
        $this->assertSame(0, (int) DB::connection('filler')->selectOne('SELECT COUNT(*) AS n FROM pragma_foreign_key_check')->n, 'every foreign key points at a real row');
        DB::purge('filler');
        @unlink($file);
    }

    public function test_on_saas_it_rehearses_on_a_copy_of_the_largest_firm(): void
    {
        $dir = sys_get_temp_dir().'/rehearse-'.uniqid();
        mkdir($dir);
        touch("{$dir}/control.sqlite");
        $default = config('database.default');
        config(['database.connections.control' => ['driver' => 'sqlite', 'database' => "{$dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite', 'tenancy.storage_disks' => ['local'], 'backup.disk' => 'backups']);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
        Storage::fake('local');
        Storage::fake('backups');
        config(['tenancy.mode' => 'multi']);
        try {
            $t = app(TenantLifecycle::class)->provision(['slug' => 'big', 'name' => 'Big', 'admin_email' => 'a@big.test', 'domain' => 'big.app.test', 'database' => "{$dir}/big.sqlite"])['tenant'];
            app(FirmSnapshots::class)->take($t);
            $this->assertSame(1, Artisan::call('migrations:rehearse'));
            $this->assertStringContainsString('Choose --largest', Artisan::output());

            $this->assertSame(0, Artisan::call('migrations:rehearse', ['--largest' => true]));
            $out = Artisan::output();
            $this->assertStringContainsString("Rehearsing on a copy of big's snapshot", $out);
            $this->assertStringContainsString('Nothing to rehearse: the source is already migrated', $out);
            $this->assertSame([], glob("{$dir}/big_r*.sqlite"), 'the copy is dropped');
        } finally {
            config(['tenancy.mode' => 'single']);
            DB::setDefaultConnection($default);
            app(TenantContext::class)->activate('default');
        }
    }
}
