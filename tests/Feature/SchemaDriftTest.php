<?php

namespace Tests\Feature;

use App\Tenancy\Models\Tenant;
use App\Tenancy\SchemaDrift;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** TEN-8: schema drift is found, described and recorded. */
class SchemaDriftTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // These tests change the schema. On MySQL, DDL commits immediately and survives RefreshDatabase's rollback,
        // so make the next test start from a freshly migrated database (SQLite in-memory is rebuilt anyway).
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function drift(): array
    {
        return app(SchemaDrift::class)->check(DB::getDefaultConnection());
    }

    public function test_a_freshly_migrated_database_has_no_drift(): void
    {
        $this->assertSame([], $this->drift());
        $this->assertSame(0, Artisan::call('schema:drift'));
    }

    public function test_hand_edits_are_reported_precisely(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->string('hand_added')->nullable());
        Schema::table('courts', fn (Blueprint $t) => $t->dropUnique(['catalog_key']));
        Schema::create('support_scratch', fn (Blueprint $t) => $t->id());
        DB::statement('DROP TRIGGER IF EXISTS '.SchemaDrift::fingerprint(DB::getDefaultConnection())['__triggers'][0]);

        $problems = $this->drift();

        $this->assertContains('clients: unexpected column hand_added', $problems);
        $this->assertContains('courts: missing index unique(catalog_key)', $problems);
        $this->assertContains('unexpected table support_scratch', $problems);
        $this->assertNotEmpty(preg_grep('/^missing trigger /', $problems), 'ledger guard triggers are part of the schema');
        $this->assertSame(1, Artisan::call('schema:drift'));
        $this->assertStringContainsString('unexpected column hand_added', Artisan::output());
    }

    public function test_a_changed_column_and_an_unknown_migration_are_reported(): void
    {
        DB::table('migrations')->insert(['migration' => '2099_01_01_000000_from_somewhere_else', 'batch' => 99]);

        $this->assertContains('migration recorded but not in the code: 2099_01_01_000000_from_somewhere_else', $this->drift());

        $diff = app(SchemaDrift::class)->diff(
            ['t' => ['columns' => ['a' => 'varchar not null'], 'indexes' => [], 'foreign' => ['(x)->y(id)']], '__triggers' => []],
            ['t' => ['columns' => ['a' => 'integer null'], 'indexes' => [], 'foreign' => []], '__triggers' => []],
        );
        $this->assertSame(['t.a: is integer null, expected varchar not null', 't: missing foreign key (x)->y(id)'], $diff);
    }

    public function test_a_lagging_firm_is_compared_with_its_own_version_not_the_newest(): void
    {
        // Undo the newest migration as a self-hosted install a release behind would have it.
        $last = DB::table('migrations')->orderByDesc('id')->value('migration');
        $file = database_path("migrations/{$last}.php");
        (require $file)->down();
        DB::table('migrations')->where('migration', $last)->delete();

        $this->assertSame([], $this->drift());
    }

    public function test_on_mysql_only_the_firms_own_database_is_fingerprinted(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL lists every visible database; SQLite has only one.');
        }
        // Another firm's database on the same server must never leak into this firm's schema.
        DB::statement('CREATE DATABASE IF NOT EXISTS drift_other_firm');
        DB::statement('CREATE TABLE IF NOT EXISTS drift_other_firm.only_in_the_other_firm (id int)');
        try {
            $this->assertArrayNotHasKey('only_in_the_other_firm', SchemaDrift::fingerprint(DB::getDefaultConnection()));
            $this->assertSame([], $this->drift());
        } finally {
            DB::statement('DROP DATABASE IF EXISTS drift_other_firm');
        }
    }

    public function test_every_firm_is_checked_and_the_result_is_recorded_for_the_console(): void
    {
        $original = config('database.default');
        $dir = sys_get_temp_dir().'/drift-'.uniqid();
        mkdir($dir);
        touch("{$dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
            'tenancy.storage_disks' => ['local'],
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);

        try {
            foreach (['clean', 'edited'] as $slug) {
                app(TenantLifecycle::class)->provision(['slug' => $slug, 'name' => $slug, 'admin_email' => "a@{$slug}.test", 'database' => "{$dir}/{$slug}.sqlite"]);
            }
            config(['tenancy.mode' => 'multi']);
            app(TenantManager::class)->runIn(Tenant::where('slug', 'edited')->first(), fn () => Schema::connection(TenantManager::TENANT_CONNECTION)->table('cases', fn (Blueprint $t) => $t->string('x')->nullable()));

            $this->assertSame(1, Artisan::call('schema:drift'));

            $this->assertNull(Tenant::where('slug', 'clean')->first()->schema_drift);
            $this->assertNotNull(Tenant::where('slug', 'clean')->first()->drift_checked_at);
            $this->assertSame(['cases: unexpected column x'], Tenant::where('slug', 'edited')->first()->schema_drift);
        } finally {
            config(['tenancy.mode' => 'single']);
            DB::setDefaultConnection($original);
            app(TenantContext::class)->activate('default');
        }
    }
}
