<?php

namespace Tests\Feature;

use App\Models\Cases;
use App\Models\Client;
use App\Models\User;
use App\Platform\PlatformAudit;
use App\Platform\PlatformStaff;
use App\Portability\Bundle;
use App\Tenancy\IdentityService;
use App\Tenancy\Models\Identity;
use App\Tenancy\Models\Tenant;
use App\Tenancy\PurgeStep;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** TEN-9: deletion is one sequence: final export -> retention window -> database, files, keys, warehouse, sign-in gone. */
class TenantDeletionTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/tendel-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
            'tenancy.retention_days' => 30,
            'tenancy.storage_disks' => ['local'],
            'portability.directory' => $this->dir,
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        config(['tenancy.mode' => 'single', 'tenancy.purge_steps' => []]);
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function lifecycle(): TenantLifecycle
    {
        return app(TenantLifecycle::class);
    }

    private function firm(string $slug, ?string $admin = null): Tenant
    {
        $t = $this->lifecycle()->provision(['slug' => $slug, 'name' => ucfirst($slug), 'admin_email' => $admin ?? "admin@{$slug}.test",
            'domain' => "{$slug}.app.test", 'database' => "{$this->dir}/{$slug}.sqlite"])['tenant'];
        app(TenantManager::class)->runIn($t, function () use ($slug) {
            $client = Client::factory()->create(['name' => "Client of {$slug}"]);
            $path = app(TenantContext::class)->storagePrefix("cases/docs/{$slug}.pdf");
            Storage::disk('local')->put($path, "document of {$slug}");
            DB::table('documents')->insert(['case_id' => Cases::factory()->create(['client_id' => $client->id])->id, 'version' => 1, 'is_current' => 1,
                'title' => 'd', 'filename' => "{$slug}.pdf", 'filepath' => $path, 'full_path' => $path, 'disk' => 'local', 'mimetype' => 'application/pdf',
                'filesize' => 20, 'checksum' => hash('sha256', "document of {$slug}"), 'extension' => 'pdf', 'created_at' => now(), 'updated_at' => now()]);
        });

        return $t;
    }

    public function test_offboarding_makes_a_verified_final_export_of_everything(): void
    {
        $t = $this->firm('acme');

        $this->lifecycle()->offboard($t);
        $t->refresh();

        $this->assertNotNull($t->final_export_at);
        $this->assertFileExists($t->final_export_path);
        $this->assertSame(hash_file('sha256', $t->final_export_path), $t->final_export_sha256);
        $bundle = Bundle::open($t->final_export_path);
        try {
            $this->assertSame([], $bundle->verify(), 'the bundle verifies');
            $this->assertContains('Client of acme', collect(iterator_to_array($bundle->rows('clients')))->pluck('name')->all());
            $this->assertSame(1, $bundle->manifest['files']['count']);
        } finally {
            $bundle->close();
        }
    }

    public function test_purge_runs_the_whole_sequence_and_records_it(): void
    {
        $t = $this->firm('acme', 'shared@people.test');
        $this->firm('other', 'shared@people.test');            // the same person also works at another firm
        app(TenantManager::class)->runIn($t, fn () => User::factory()->create(['email' => 'only-acme@people.test']));
        $only = Identity::create(['email' => 'only-acme@people.test', 'password' => Hash::make('x')]);
        app(IdentityService::class)->link($only, $t, app(TenantManager::class)->runIn($t, fn () => User::where('email', 'only-acme@people.test')->first()));
        $this->lifecycle()->offboard($t, 0);
        $export = $t->fresh()->final_export_path;

        $report = $this->lifecycle()->purge($t->fresh());

        $t->refresh();
        $this->assertSame('purged', $t->status);
        $this->assertFileDoesNotExist("{$this->dir}/acme.sqlite");
        Storage::disk('local')->assertMissing('tenants/acme/cases/docs/acme.pdf');
        Storage::disk('local')->assertExists('tenants/other/cases/docs/other.pdf');
        $this->assertFileDoesNotExist($export, "the platform's copy of the final export goes last");
        $this->assertNull($t->final_export_path);
        $this->assertSame(0, $t->domains()->count());
        $this->assertSame(0, DB::connection('control')->table('tenant_memberships')->where('tenant_id', $t->id)->count());
        $this->assertNull(Identity::where('email', 'only-acme@people.test')->first(), 'a sign-in with no firm left is deleted');
        $this->assertNotNull(Identity::where('email', 'shared@people.test')->first(), 'a person still at another firm keeps their sign-in');

        $this->assertSame($report, $t->purge_report);
        $this->assertStringStartsWith('made ', $report['final_export']);
        $this->assertSame('dropped', $report['database']);
        $this->assertSame(['local' => 'deleted'], $report['files']);
        $this->assertStringStartsWith('none: ', $report['encryption_key']);
        $this->assertStringStartsWith('none: ', $report['warehouse']);
        $this->assertSame('2 membership(s) removed, 1 identity(ies) with no other firm deleted', $report['sign_in']);
        $this->assertSame('deleted', $report['final_export_file']);
    }

    public function test_purge_refuses_a_missing_or_altered_final_export_and_needs_a_reason_to_skip_it(): void
    {
        $t = $this->firm('acme');
        $this->lifecycle()->offboard($t, 0);
        file_put_contents($t->fresh()->final_export_path, 'tampered');

        try {
            $this->lifecycle()->purge($t->fresh());
            $this->fail('purged with a damaged export');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('missing or damaged', $e->getMessage());
        }
        $this->assertFileExists("{$this->dir}/acme.sqlite", 'nothing was deleted');

        $t->update(['final_export_path' => null, 'final_export_at' => null]);
        try {
            $this->lifecycle()->purge($t->fresh(), '  ');
            $this->fail('skipped the export without a reason');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame('skipped: customer took their own export', $this->lifecycle()->purge($t->fresh(), 'customer took their own export')['final_export']);
    }

    public function test_extra_purge_steps_run_after_the_data_is_gone_and_replace_the_none_lines(): void
    {
        $step = new class implements PurgeStep
        {
            public static array $seen = [];

            public function name(): string
            {
                return 'encryption_key';
            }

            public function purge(Tenant $tenant): string
            {
                self::$seen[] = [$tenant->slug, file_exists($tenant->database)];

                return 'key k-123 destroyed';
            }
        };
        $this->app->instance('test.key-step', $step);
        config(['tenancy.purge_steps' => ['test.key-step']]);
        $t = $this->firm('acme');
        $this->lifecycle()->offboard($t, 0);

        $report = $this->lifecycle()->purge($t->fresh());

        $this->assertSame('key k-123 destroyed', $report['encryption_key']);
        $this->assertSame([['acme', false]], $step::$seen, 'it runs once the database is already gone');
    }

    public function test_purge_due_purges_only_firms_that_are_due_free_of_holds_and_exported(): void
    {
        foreach (['due', 'held', 'early', 'noexport', 'active'] as $slug) {
            $this->firm($slug);
        }
        $get = fn ($s) => Tenant::where('slug', $s)->first();
        $this->lifecycle()->offboard($get('due'), 0);
        $this->lifecycle()->offboard($get('held'), 0);
        $get('held')->update(['legal_hold' => true]);
        $this->lifecycle()->offboard($get('early'), 30);
        $this->lifecycle()->offboard($get('noexport'), 0);
        $get('noexport')->update(['final_export_at' => null, 'final_export_error' => 'disk full']);

        $this->artisan('tenants:purge-due --dry-run')->expectsOutputToContain('due: would be purged')->assertExitCode(0);
        $this->assertSame('offboarded', $get('due')->status);

        $this->artisan('tenants:purge-due')
            ->expectsOutputToContain('due: purged')
            ->expectsOutputToContain('held: waiting, legal hold')
            ->expectsOutputToContain('noexport: waiting, no final export (failed: disk full)')
            ->assertExitCode(0);

        $this->assertSame(['purged', 'offboarded', 'offboarded', 'offboarded', 'active'], array_map(fn ($s) => $get($s)->status, ['due', 'held', 'early', 'noexport', 'active']));
    }

    public function test_the_final_export_can_be_remade_and_the_purge_command_prints_its_report(): void
    {
        $t = $this->firm('acme');
        $this->assertSame(1, Artisan::call('tenants:final-export', ['slug' => 'acme']), 'only offboarded firms');
        $this->lifecycle()->offboard($t, 0);
        $first = $t->fresh()->final_export_path;
        $this->travel(2)->seconds();

        $this->assertSame(0, Artisan::call('tenants:final-export', ['slug' => 'acme']));
        $this->assertNotSame($first, $t->fresh()->final_export_path);
        $this->assertFileDoesNotExist($first, 'the older export is replaced');

        $this->assertSame(0, Artisan::call('tenants:purge', ['slug' => 'acme']));
        $out = Artisan::output();
        $this->assertStringContainsString('database           dropped', $out);
        $this->assertStringContainsString('acme purged.', $out);
    }

    public function test_platform_admins_can_download_the_final_export_and_it_is_audited(): void
    {
        config(['tenancy.mode' => 'multi', 'tenancy.platform_host' => 'admin.platform.test', 'tenancy.base_domain' => 'app.test']);
        $t = $this->firm('acme');
        $this->lifecycle()->offboard($t, 0);
        PlatformStaff::create(['name' => 'A', 'email' => 'admin@platform.test', 'password' => Hash::make('correct-horse-battery'), 'role' => 'admin']);
        PlatformStaff::create(['name' => 'O', 'email' => 'op@platform.test', 'password' => Hash::make('correct-horse-battery'), 'role' => 'operator']);
        $token = fn ($email) => $this->postJson('http://admin.platform.test/api/platform/login', ['email' => $email, 'password' => 'correct-horse-battery'])->json('token');

        $this->withToken($token('op@platform.test'))->get('http://admin.platform.test/api/platform/tenants/acme/final-export')->assertForbidden();
        $r = $this->withToken($token('admin@platform.test'))->get('http://admin.platform.test/api/platform/tenants/acme/final-export')->assertOk();

        $this->assertSame($t->fresh()->final_export_sha256, $r->headers->get('X-Content-SHA256'));
        $this->assertSame('ok', PlatformAudit::where('action', 'tenant.final_export.download')->value('result'));
        $this->withToken($token('admin@platform.test'))->getJson('http://admin.platform.test/api/platform/tenants/acme')->assertJsonPath('data.final_export.sha256', $t->fresh()->final_export_sha256);
    }
}
