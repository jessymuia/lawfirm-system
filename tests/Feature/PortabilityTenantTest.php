<?php

namespace Tests\Feature;

use App\Jobs\RunTenantEject;
use App\Models\Cases;
use App\Models\Client;
use App\Models\User;
use App\Platform\PlatformAudit;
use App\Platform\PlatformStaff;
use App\Portability\Bundle;
use App\Portability\PortabilityException;
use App\Portability\PortabilityRunner;
use App\Tenancy\Models\Identity;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** PLAT-9 in SaaS mode: eject a firm, import into another (relocation / restore / import-from-self-host), platform eject. */
class PortabilityTenantTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private const HOST = 'http://admin.platform.test';

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/plat9t-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
            'tenancy.mode' => 'multi', 'tenancy.platform_host' => 'admin.platform.test', 'tenancy.base_domain' => 'app.test',
            'tenancy.storage_disks' => ['local'],
            'filesystems.disks.docs' => ['driver' => 'local', 'root' => "{$this->dir}/docs"],
            'portability.directory' => "{$this->dir}/out",
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        is_dir($this->dir) && Bundle::rmrf($this->dir);
        parent::tearDown();
    }

    private function provision(string $slug, string $email): Tenant
    {
        return app(TenantLifecycle::class)->provision(['slug' => $slug, 'name' => ucfirst($slug), 'admin_email' => $email, 'admin_password' => 'a-long-password-1', 'database' => "{$this->dir}/{$slug}.sqlite"])['tenant'];
    }

    private function in(string $slug, \Closure $fn): mixed
    {
        return app(TenantManager::class)->runIn(Tenant::where('slug', $slug)->firstOrFail(), $fn);
    }

    /** @return array{case:int,bytes:string} */
    private function seedTenant(string $slug): array
    {
        return $this->in($slug, function () {
            $advocate = User::factory()->create(['email' => 'advocate@alpha.test', 'password' => Hash::make('advocate-pass-1')]);
            $client = Client::factory()->create();
            $case = Cases::factory()->create(['client_id' => $client->id]);
            $bytes = random_bytes(2048);
            Storage::disk('docs')->put('tenants/alpha/cases/docs/f.bin', $bytes);
            DB::table('documents')->insert(['case_id' => $case->id, 'version' => 1, 'is_current' => 1, 'title' => 't', 'filename' => 'f.bin', 'filepath' => 'tenants/alpha/cases/docs/f.bin',
                'full_path' => 'tenants/alpha/cases/docs/f.bin', 'disk' => 'docs', 'mimetype' => 'application/octet-stream', 'filesize' => 2048,
                'checksum' => hash('sha256', $bytes), 'extension' => 'bin', 'created_at' => now(), 'updated_at' => now()] + $this->docDefaults());

            return ['case' => $case->id, 'bytes' => $bytes];
        });
    }

    private function docDefaults(): array
    {
        $extra = [];
        foreach (Schema::getColumns('documents') as $c) {
            if (! $c['nullable'] && $c['default'] === null && ! $c['auto_increment'] && ! in_array($c['name'], ['case_id', 'version', 'is_current', 'title', 'filename', 'filepath', 'full_path', 'disk', 'mimetype', 'filesize', 'checksum', 'extension', 'created_at', 'updated_at'], true)) {
                $extra[$c['name']] = 1;
            }
        }

        return $extra;
    }

    public function test_a_firm_can_be_relocated_into_another_tenant_with_logins_intact(): void
    {
        $this->provision('alpha', 'owner@alpha.test');
        $this->provision('beta', 'owner@beta.test');
        $seed = $this->seedTenant('alpha');
        $alphaCounts = $this->in('alpha', fn () => [DB::table('users')->count(), DB::table('cases')->count(), DB::table('clients')->count(), DB::table('audits')->count()]);

        $runner = app(PortabilityRunner::class);
        $zip = "{$this->dir}/alpha.zip";
        $r = $runner->exportZip('alpha', $zip);
        $this->assertSame(64, strlen($r['sha256']));

        $bundle = Bundle::open($zip);
        $this->assertSame([], $bundle->verify());
        $this->assertNotContains('global_identity_id', $bundle->manifest['tables']['users']['columns'], 'a control-plane id never travels');
        $res = $runner->import('beta', $bundle, ['replace_bootstrap' => true]);
        $bundle->close();

        $this->assertStringContainsString('linked to sign-in identities', implode(' ', $res['notes']));
        $this->in('beta', function () use ($alphaCounts, $seed) {
            $this->assertSame($alphaCounts, [DB::table('users')->count(), DB::table('cases')->count(), DB::table('clients')->count(), DB::table('audits')->count()]);
            $this->assertSame($seed['case'], (int) DB::table('cases')->value('id'));
            $this->assertSame(0, User::whereNull('global_identity_id')->count(), 'every person is linked to an identity');
            // beta's documents live under beta's own object-store prefix (TEN-12), never alpha's
            $this->assertSame('tenants/beta/cases/docs/f.bin', DB::table('documents')->value('full_path'));
            $this->assertSame('tenants/beta/cases/docs/f.bin', DB::table('documents')->value('filepath'));
            $this->assertSame($seed['bytes'], Storage::disk('docs')->get('tenants/beta/cases/docs/f.bin'));
        });
        $this->assertSame($seed['bytes'], Storage::disk('docs')->get('tenants/alpha/cases/docs/f.bin'), 'the source copy is untouched');

        // the advocate can still sign in with the same password, in beta, through the identity
        $identity = Identity::where('email', 'advocate@alpha.test')->first();
        $this->assertNotNull($identity);
        $this->assertTrue(Hash::check('advocate-pass-1', $identity->password));
        $beta = Tenant::where('slug', 'beta')->first();
        $this->assertSame(1, TenantMembership::where('identity_id', $identity->id)->where('tenant_id', $beta->id)->count());
        // alpha is untouched
        $this->assertSame($alphaCounts, $this->in('alpha', fn () => [DB::table('users')->count(), DB::table('cases')->count(), DB::table('clients')->count(), DB::table('audits')->count()]));
    }

    public function test_tenant_flag_must_match_the_deployment_mode(): void
    {
        $this->provision('alpha', 'owner@alpha.test');
        $runner = app(PortabilityRunner::class);

        try {
            $runner->within(null, fn () => 1);
            $this->fail('multi mode needs --tenant');
        } catch (PortabilityException $e) {
            $this->assertStringContainsString('--tenant', $e->getMessage());
        }
        try {
            $runner->within('nobody', fn () => 1);
            $this->fail('unknown tenant');
        } catch (PortabilityException $e) {
            $this->assertStringContainsString('No such tenant', $e->getMessage());
        }
        config(['tenancy.mode' => 'single']);
        try {
            $runner->within('alpha', fn () => 1);
            $this->fail('single mode has no tenants');
        } catch (PortabilityException $e) {
            $this->assertStringContainsString('only meaningful', $e->getMessage());
        }
    }

    public function test_a_second_firm_cannot_be_imported_over_a_firm_that_already_has_data(): void
    {
        $this->provision('alpha', 'owner@alpha.test');
        $this->provision('beta', 'owner@beta.test');
        $this->seedTenant('alpha');
        $this->in('beta', fn () => Client::factory()->create());
        $zip = "{$this->dir}/alpha.zip";
        app(PortabilityRunner::class)->exportZip('alpha', $zip);

        $this->expectException(PortabilityException::class);
        $this->expectExceptionMessage('not empty');
        app(PortabilityRunner::class)->import('beta', Bundle::open($zip), ['replace_bootstrap' => true]);
    }

    // ---- platform console ----------------------------------------------------------------------------------------

    private function token(string $role): string
    {
        $s = PlatformStaff::create(['name' => $role, 'email' => "{$role}@platform.test", 'password' => Hash::make('correct-horse-battery'), 'role' => $role]);

        return $this->postJson(self::HOST.'/api/platform/login', ['email' => $s->email, 'password' => 'correct-horse-battery'])->assertOk()->json('token');
    }

    public function test_only_an_admin_can_eject_and_it_needs_a_reason_and_is_audited(): void
    {
        $this->provision('alpha', 'owner@alpha.test');
        Queue::fake();
        $url = self::HOST.'/api/platform/tenants/alpha/eject';

        $this->withToken($this->token('operator'))->postJson($url, ['reason' => 'customer request'])->assertForbidden();
        $admin = $this->token('admin');
        $this->withToken($admin)->postJson($url, [])->assertStatus(422);
        Queue::assertNothingPushed();

        $this->withToken($admin)->postJson($url, ['reason' => 'customer asked for their data'])->assertStatus(202);
        Queue::assertPushed(RunTenantEject::class, fn ($j) => $j->slug === 'alpha');
        $row = PlatformAudit::where('action', 'tenant.eject.requested')->first();
        $this->assertSame('alpha', $row->tenant_slug);
        $this->assertSame('customer asked for their data', $row->params['reason']);
    }

    public function test_the_eject_job_writes_a_verified_bundle_and_records_where_in_the_audit_log(): void
    {
        $this->provision('alpha', 'owner@alpha.test');
        $this->seedTenant('alpha');

        (new RunTenantEject('alpha', null, 'admin@platform.test'))->handle(app(PortabilityRunner::class));

        $row = PlatformAudit::where('action', 'tenant.eject.finished')->first();
        $this->assertSame('ok', $row->result);
        $this->assertFileExists($row->params['path']);
        $this->assertSame(hash_file('sha256', $row->params['path']), $row->params['sha256']);
        $this->assertSame(1, $row->params['documents']);
        $b = Bundle::open($row->params['path']);
        $this->assertSame([], $b->verify());
        $this->assertSame('alpha', $b->manifest['source']['tenant']);
        $b->close();
    }

    public function test_a_failed_eject_is_recorded_as_failed(): void
    {
        (new RunTenantEject('ghost', null, 'admin@platform.test'))->failed(new \RuntimeException('boom'));
        $this->assertSame('failed', PlatformAudit::where('action', 'tenant.eject.finished')->first()->result);
    }
}
