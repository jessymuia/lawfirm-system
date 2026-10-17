<?php

namespace Tests\Feature;

use App\Models\CourtType;
use App\Models\ExpenseCategory;
use App\Models\HearingType;
use App\Models\User;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** TEN-9: provisioning and lifecycle, exercised against real SQLite tenant databases. */
class TenantLifecycleTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/tenlife-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");

        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
            'tenancy.retention_days' => 30,
            'tenancy.storage_disks' => ['local'],
            'portability.directory' => $this->dir,   // TEN-9 final exports stay in this test's temp directory
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function lifecycle(): TenantLifecycle
    {
        return app(TenantLifecycle::class);
    }

    private function provision(string $slug, array $extra = []): array
    {
        return $this->lifecycle()->provision($extra + [
            'slug' => $slug,
            'name' => ucfirst($slug),
            'admin_email' => "admin@{$slug}.test",
            'domain' => "{$slug}.app.test",
            'database' => "{$this->dir}/{$slug}.sqlite",
        ]);
    }

    private function inTenant(Tenant $t, \Closure $fn)
    {
        return app(TenantManager::class)->runIn($t, $fn, allowInactive: true);
    }

    public function test_provision_creates_a_working_tenant_end_to_end(): void
    {
        $r = $this->provision('firm-a');
        $t = $r['tenant'];

        $this->assertSame('active', $t->status);
        $this->assertSame('ok', $t->migration_status);
        $this->assertSame(['firm-a.app.test'], $t->domains->pluck('domain')->all());
        $this->assertNotEmpty($r['admin_password']);

        $this->inTenant($t, function () use ($r) {
            $admin = User::where('email', 'admin@firm-a.test')->first();
            $this->assertNotNull($admin);
            $this->assertTrue($admin->hasRole('admin'));
            $this->assertTrue(Hash::check($r['admin_password'], $admin->password));
            $this->assertSame(1, CourtType::where('name', 'High Court')->count());
            $this->assertGreaterThan(0, HearingType::count());
            $this->assertGreaterThan(0, ExpenseCategory::count());
        });
    }

    public function test_supplied_admin_password_is_not_echoed_back(): void
    {
        $r = $this->provision('firm-a', ['admin_password' => 'Sup3r-secret-pw']);

        $this->assertNull($r['admin_password']);
        $this->inTenant($r['tenant'], fn () => $this->assertTrue(
            Hash::check('Sup3r-secret-pw', User::where('email', 'admin@firm-a.test')->first()->password)
        ));
    }

    public function test_tenants_are_isolated_after_provisioning(): void
    {
        $a = $this->provision('firm-a')['tenant'];
        $b = $this->provision('firm-b')['tenant'];

        $this->inTenant($a, fn () => $this->assertNull(User::where('email', 'admin@firm-b.test')->first()));
        $this->inTenant($b, fn () => $this->assertNull(User::where('email', 'admin@firm-a.test')->first()));
    }

    public function test_provisioning_is_resumable_and_idempotent(): void
    {
        $a = $this->provision('firm-a')['tenant'];

        // Simulate a run that died before the final status flip.
        $a->update(['status' => 'provisioning']);
        $this->provision('firm-a');

        $this->assertSame('active', $a->fresh()->status);
        $this->inTenant($a, function () {
            $this->assertSame(1, User::where('email', 'admin@firm-a.test')->count());
            $this->assertSame(1, CourtType::where('name', 'High Court')->count());
        });
    }

    public function test_cannot_provision_an_existing_active_tenant_or_bad_slug(): void
    {
        $this->provision('firm-a');

        try {
            $this->provision('firm-a');
            $this->fail('duplicate must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->provision('Bad Slug!');
    }

    public function test_suspend_and_resume_enforce_valid_transitions(): void
    {
        $t = $this->provision('firm-a')['tenant'];

        $this->lifecycle()->suspend($t);
        $this->assertSame('suspended', $t->fresh()->status);

        $this->lifecycle()->resume($t->fresh());
        $this->assertSame('active', $t->fresh()->status);

        $this->expectException(RuntimeException::class);
        $this->lifecycle()->resume($t->fresh()); // already active
    }

    public function test_purge_is_refused_inside_the_retention_window(): void
    {
        $t = $this->provision('firm-a')['tenant'];
        $this->lifecycle()->offboard($t);

        $this->assertSame('offboarded', $t->fresh()->status);
        $this->assertTrue($t->fresh()->purge_after->isFuture());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('retention window');
        $this->lifecycle()->purge($t->fresh());
    }

    public function test_purge_requires_offboarding_first(): void
    {
        $t = $this->provision('firm-a')['tenant'];

        $this->expectException(RuntimeException::class);
        $this->lifecycle()->purge($t);
    }

    public function test_legal_hold_blocks_deletion_even_after_retention(): void
    {
        $t = $this->provision('firm-a')['tenant'];
        $this->lifecycle()->offboard($t, 0);
        $this->lifecycle()->setLegalHold($t->fresh(), true);

        try {
            $this->lifecycle()->purge($t->fresh());
            $this->fail('purge must be refused under legal hold');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('legal hold', $e->getMessage());
        }
        $this->assertFileExists("{$this->dir}/firm-a.sqlite");
        $this->assertSame('offboarded', $t->fresh()->status);

        $this->lifecycle()->setLegalHold($t->fresh(), false);
        $this->lifecycle()->purge($t->fresh());
        $this->assertSame('purged', $t->fresh()->status);
    }

    public function test_purge_removes_database_files_and_domains(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('tenants/firm-a/cases/docs/x.pdf', 'data');
        Storage::disk('local')->put('tenants/firm-b/cases/docs/y.pdf', 'other tenant');

        $t = $this->provision('firm-a')['tenant'];
        $this->lifecycle()->offboard($t, 0);
        $this->lifecycle()->purge($t->fresh());

        $this->assertFileDoesNotExist("{$this->dir}/firm-a.sqlite");
        Storage::disk('local')->assertMissing('tenants/firm-a/cases/docs/x.pdf');
        Storage::disk('local')->assertExists('tenants/firm-b/cases/docs/y.pdf'); // other tenants untouched
        $this->assertSame(0, $t->fresh()->domains()->count());
        $this->assertNotNull($t->fresh()->purged_at);
    }

    public function test_purged_tenants_are_skipped_by_the_migration_runner(): void
    {
        $t = $this->provision('firm-a')['tenant'];
        $this->lifecycle()->offboard($t, 0);
        $this->lifecycle()->purge($t->fresh());

        $this->artisan('tenants:migrate')->expectsOutputToContain('No matching tenants')->assertExitCode(0);
    }

    public function test_database_name_is_validated_before_ddl(): void
    {
        $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS `tenant_acme`', $this->lifecycle()->createDatabaseSql('tenant_acme'));

        $this->expectException(InvalidArgumentException::class);
        $this->lifecycle()->createDatabaseSql('x`; DROP DATABASE prod; --');
    }

    public function test_console_commands_drive_the_lifecycle(): void
    {
        $this->artisan('tenants:provision', ['slug' => 'firm-c', '--admin-email' => 'a@c.test', '--domain' => 'c.app.test'])
            ->expectsOutputToContain('is active')
            ->assertExitCode(0);
        $this->artisan('tenants:provision', ['slug' => 'firm-d'])->assertExitCode(1); // no admin email

        $this->artisan('tenants:suspend firm-c')->assertExitCode(0);
        $this->artisan('tenants:resume firm-c')->assertExitCode(0);
        $this->artisan('tenants:legal-hold firm-c')->assertExitCode(0);
        $this->assertTrue(Tenant::where('slug', 'firm-c')->first()->legal_hold);
        $this->artisan('tenants:offboard firm-c --retention-days=0')->assertExitCode(0);
        $this->artisan('tenants:purge firm-c')->assertExitCode(1); // legal hold
        $this->artisan('tenants:legal-hold firm-c --release')->assertExitCode(0);
        $this->artisan('tenants:purge firm-c')->assertExitCode(0);
        $this->artisan('tenants:suspend nope')->assertExitCode(1);
    }
}
