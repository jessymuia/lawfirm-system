<?php

namespace Tests\Feature;

use App\Entitlements\Entitlements;
use App\Flags\FlagOverrides;
use App\Flags\Flags;
use App\Platform\PlatformAudit;
use App\Platform\PlatformStaff;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** PLAT-3: feature flags, resolved per tenant and per edition, plus the "flag, never a branch" policy guards. */
class FeatureFlagsTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase, \Tests\CreatesTestUsers;

    private const HOST = 'http://admin.platform.test';

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/plat3-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
            'flags.flags' => [
                'plain' => ['description' => 'd', 'type' => 'ops', 'owner' => 't', 'default' => false],
                'on_by_default' => ['description' => 'd', 'type' => 'ops', 'owner' => 't', 'default' => true],
                'pro_only' => ['description' => 'd', 'type' => 'customer', 'owner' => 't', 'default' => false, 'editions' => ['professional' => true]],
                'half' => ['description' => 'd', 'type' => 'release', 'owner' => 't', 'expires' => '2099-01-01', 'rollout' => 50],
                'hearing_sms_reminders' => config('flags.flags.hearing_sms_reminders'),
            ],
            'flags.overrides' => '', 'flags.killed' => '',
        ]);
        app(Flags::class)->forget();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function flags(): Flags
    {
        app(Flags::class)->forget();

        return app(Flags::class);
    }

    // ---- resolution order ----------------------------------------------------------------------------------------

    public function test_default_and_unregistered(): void
    {
        $f = $this->flags();
        $this->assertFalse($f->enabled('plain'));
        $this->assertTrue($f->enabled('on_by_default'));
        $this->assertSame('default', $f->explain('plain')['source']);
        $this->assertFalse($f->enabled('never_registered'));
        $this->assertSame('unregistered', $f->explain('never_registered')['source']);
    }

    public function test_edition_decides_by_plan_name(): void
    {
        config(['entitlements.single_tenant_plan' => 'starter']);
        app(Entitlements::class)->forget();
        $this->assertFalse($this->flags()->enabled('pro_only'));

        config(['entitlements.single_tenant_plan' => 'professional']);
        app(Entitlements::class)->forget();
        $f = $this->flags();
        $this->assertTrue($f->enabled('pro_only'));
        $this->assertSame('edition', $f->explain('pro_only')['source']);
    }

    public function test_deployment_override_beats_edition_and_default(): void
    {
        config(['entitlements.single_tenant_plan' => 'professional', 'flags.overrides' => 'pro_only=off, plain=on ,on_by_default=false,garbage,x=maybe']);
        app(Entitlements::class)->forget();
        $f = $this->flags();
        $this->assertFalse($f->enabled('pro_only'));
        $this->assertTrue($f->enabled('plain'));
        $this->assertFalse($f->enabled('on_by_default'));
        $this->assertSame('deployment', $f->explain('plain')['source']);
    }

    public function test_kill_switch_beats_everything(): void
    {
        config(['flags.overrides' => 'on_by_default=on', 'flags.killed' => 'on_by_default']);
        $f = $this->flags();
        $this->assertFalse($f->enabled('on_by_default'));
        $this->assertSame('killed', $f->explain('on_by_default')['source']);
    }

    public function test_rollout_is_stable_per_tenant_and_roughly_the_stated_share(): void
    {
        $on = 0;
        $tenants = 400;
        for ($i = 0; $i < $tenants; $i++) {
            $key = "firm{$i}";
            $first = (crc32('half|'.$key) % 100) < 50;
            $this->assertSame($first, (crc32('half|'.$key) % 100) < 50);
            $on += $first ? 1 : 0;
        }
        $this->assertGreaterThan(150, $on);
        $this->assertLessThan(250, $on);

        // The real resolver uses exactly that rule for the active tenant key.
        $key = app(TenantContext::class)->key();
        $this->assertSame((crc32('half|'.$key) % 100) < 50, $this->flags()->enabled('half'));
        $this->assertSame('rollout', $this->flags()->explain('half')['source']);

        config(['flags.flags.half.rollout' => 100]);
        $this->assertTrue($this->flags()->enabled('half'));
        config(['flags.flags.half.rollout' => 0]);
        $this->assertFalse($this->flags()->enabled('half'));
    }

    // ---- per tenant (SaaS) ---------------------------------------------------------------------------------------

    private function multi(): array
    {
        config(['tenancy.mode' => 'multi', 'tenancy.platform_host' => 'admin.platform.test', 'tenancy.base_domain' => 'app.test']);
        // the override source is chosen at container build time, so rebuild it for multi mode
        app()->forgetInstance(FlagOverrides::class);
        app()->forgetInstance(Flags::class);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
        $staff = PlatformStaff::create(['name' => 'Op', 'email' => 'op@platform.test', 'password' => Hash::make('correct-horse-battery'), 'role' => 'operator']);
        $token = $this->postJson(self::HOST.'/api/platform/login', ['email' => $staff->email, 'password' => 'correct-horse-battery'])->assertOk()->json('token');
        foreach (['alpha', 'beta'] as $s) {
            touch("{$this->dir}/{$s}.sqlite");
            Tenant::create(['slug' => $s, 'name' => ucfirst($s), 'status' => 'active', 'plan' => 'starter', 'database' => "{$this->dir}/{$s}.sqlite", 'schema_version' => 'x', 'migration_status' => 'ok']);
        }

        return [$token];
    }

    private function in(string $slug, \Closure $fn)
    {
        return app(TenantManager::class)->runIn(Tenant::where('slug', $slug)->firstOrFail(), function () use ($fn) {
            app(Flags::class)->forget();

            return $fn(app(Flags::class));
        });
    }

    public function test_a_tenant_override_changes_one_firm_only_and_is_audited(): void
    {
        [$token] = $this->multi();

        $this->withToken($token)->putJson(self::HOST.'/api/platform/tenants/alpha/flags', ['flags' => ['plain' => true, 'on_by_default' => false], 'reason' => 'pilot with Alpha'])
            ->assertOk()->assertJsonPath('data.0.flag', 'plain')->assertJsonPath('data.0.tenant_override', true);

        $this->assertTrue($this->in('alpha', fn ($f) => $f->enabled('plain')));
        $this->assertFalse($this->in('alpha', fn ($f) => $f->enabled('on_by_default')));
        $this->assertSame('tenant', $this->in('alpha', fn ($f) => $f->explain('plain')['source']));
        $this->assertFalse($this->in('beta', fn ($f) => $f->enabled('plain')), 'another firm is untouched');
        $this->assertTrue($this->in('beta', fn ($f) => $f->enabled('on_by_default')));

        $row = PlatformAudit::where('action', 'tenant.flags')->first();
        $this->assertSame('alpha', $row->tenant_slug);
        $this->assertSame(true, $row->params['flags']['plain']);
        $this->assertSame('pilot with Alpha', $row->params['reason']);
    }

    public function test_answers_are_never_shared_between_tenants_in_one_process(): void
    {
        [$token] = $this->multi();
        $this->withToken($token)->putJson(self::HOST.'/api/platform/tenants/alpha/flags', ['flags' => ['plain' => true], 'reason' => 'pilot'])->assertOk();
        app(Flags::class)->forget();

        // No forget() between the two: a long-lived worker serving alpha then beta must not leak alpha's answer.
        $alpha = app(TenantManager::class)->runIn(Tenant::where('slug', 'alpha')->first(), fn () => app(Flags::class)->enabled('plain'));
        $beta = app(TenantManager::class)->runIn(Tenant::where('slug', 'beta')->first(), fn () => app(Flags::class)->enabled('plain'));
        $this->assertTrue($alpha);
        $this->assertFalse($beta);
    }

    public function test_rollout_buckets_each_tenant_separately(): void
    {
        $this->multi();
        $a = crc32('half|alpha') % 100;
        $b = crc32('half|beta') % 100;
        $this->assertNotSame($a, $b, 'test data: pick other slugs if these two collide');
        config(['flags.flags.half.rollout' => min($a, $b) + 1]);   // exactly the lower bucket is in

        $this->assertSame($a < $b, $this->in('alpha', fn ($f) => $f->enabled('half')));
        $this->assertSame($b < $a, $this->in('beta', fn ($f) => $f->enabled('half')));
    }

    public function test_null_removes_an_override_and_unknown_flags_are_refused(): void
    {
        [$token] = $this->multi();
        $url = self::HOST.'/api/platform/tenants/alpha/flags';
        $this->withToken($token)->putJson($url, ['flags' => ['plain' => true], 'reason' => 'trial it'])->assertOk();
        $this->withToken($token)->putJson($url, ['flags' => ['plain' => null], 'reason' => 'done trialling'])->assertOk();
        $this->assertFalse($this->in('alpha', fn ($f) => $f->enabled('plain')));
        $this->assertNull(Tenant::where('slug', 'alpha')->first()->flags);

        $this->withToken($token)->putJson($url, ['flags' => ['typo_flag' => true], 'reason' => 'oops typo'])->assertStatus(422);
        $this->assertSame('failed', PlatformAudit::where('action', 'tenant.flags')->latest('id')->first()->result);
    }

    public function test_changing_flags_needs_operate_and_a_reason(): void
    {
        [$token] = $this->multi();
        $viewer = PlatformStaff::create(['name' => 'V', 'email' => 'v@platform.test', 'password' => Hash::make('correct-horse-battery'), 'role' => 'viewer']);
        $vt = $this->postJson(self::HOST.'/api/platform/login', ['email' => $viewer->email, 'password' => 'correct-horse-battery'])->json('token');
        $url = self::HOST.'/api/platform/tenants/alpha/flags';

        $this->withToken($vt)->getJson($url)->assertOk();                                   // viewers may look
        $this->withToken($vt)->putJson($url, ['flags' => ['plain' => true], 'reason' => 'let me in'])->assertForbidden();
        $this->withToken($token)->putJson($url, ['flags' => ['plain' => true]])->assertStatus(422);   // no reason
        $this->assertNull(Tenant::where('slug', 'alpha')->first()->flags);
    }

    public function test_a_tenant_override_does_not_beat_the_kill_switch(): void
    {
        [$token] = $this->multi();
        $this->withToken($token)->putJson(self::HOST.'/api/platform/tenants/alpha/flags', ['flags' => ['plain' => true], 'reason' => 'pilot'])->assertOk();
        config(['flags.killed' => 'plain']);
        $this->assertFalse($this->in('alpha', fn ($f) => $f->enabled('plain')));
    }

    // ---- wired behaviour -----------------------------------------------------------------------------------------

    public function test_the_flags_endpoint_lists_resolved_values_for_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/flags')->assertUnauthorized();
        $user = $this->userWithPermissions([]);
        config(['flags.overrides' => 'plain=on']);
        app(Flags::class)->forget();
        $this->actingAs($user)->getJson('/api/v1/flags')->assertOk()
            ->assertJsonPath('data.plain', true)->assertJsonPath('data.on_by_default', true)->assertJsonPath('data.pro_only', false);
    }

    public function test_flag_middleware_hides_the_route_when_off(): void
    {
        Route::middleware(['api', 'flag:plain'])->get('/api/_flagprobe', fn () => response()->json(['ok' => true]));
        $this->getJson('/api/_flagprobe')->assertNotFound();
        config(['flags.overrides' => 'plain=on']);
        app(Flags::class)->forget();
        $this->getJson('/api/_flagprobe')->assertOk();
    }

    public function test_the_sms_kill_switch_stops_reminders(): void
    {
        Queue::fake();
        config(['flags.overrides' => 'hearing_sms_reminders=off']);
        app(Flags::class)->forget();
        $this->artisan('hearings:send-reminders')->expectsOutputToContain('hearing_sms_reminders is off')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    // ---- the policy: a flag, never a branch ----------------------------------------------------------------------

    public function test_the_real_registry_is_well_formed_and_nothing_has_overstayed(): void
    {
        $registry = require base_path('config/flags.php');
        foreach ($registry['flags'] as $name => $d) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $name);
            $this->assertNotEmpty($d['description'] ?? null, "$name needs a description");
            $this->assertNotEmpty($d['owner'] ?? null, "$name needs an owner");
            $this->assertContains($d['type'] ?? null, ['release', 'experiment', 'ops', 'customer'], "$name has a bad type");
            if (in_array($d['type'], ['release', 'experiment'], true)) {
                $this->assertNotEmpty($d['expires'] ?? null, "$name is temporary and needs `expires`");
                $this->assertGreaterThanOrEqual(now()->toDateString(), $d['expires'], "$name has expired: remove the flag and its branches");
            }
        }
    }

    /** @return array<string,list<string>> flag => files using it */
    private function usages(): array
    {
        $found = [];
        foreach (['app', 'routes'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir))) as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'app/Flags/')) {
                    continue;
                }
                $src = file_get_contents($file->getPathname());
                preg_match_all('/Flags::class\)->enabled\(\s*[\'"]([\w]+)[\'"]|\$\w*[fF]lags?->(?:enabled|explain)\(\s*[\'"]([\w]+)[\'"]|[\'"]flag:([\w]+)[\'"]/', $src, $m, PREG_SET_ORDER);
                foreach ($m as $hit) {
                    $found[$hit[3] ?? $hit[2] ?? $hit[1] ?: ($hit[2] ?: $hit[3])][] = $file->getFilename();
                }
            }
        }

        return $found;
    }

    public function test_every_flag_used_in_code_is_registered_and_every_registered_flag_is_used(): void
    {
        $registry = array_keys((require base_path('config/flags.php'))['flags']);
        $used = $this->usages();

        $this->assertSame([], array_values(array_diff(array_keys($used), $registry)), 'code uses a flag that is not in config/flags.php');
        $this->assertSame([], array_values(array_diff($registry, array_keys($used))), 'registered flag is never used: delete it');
        $this->assertArrayHasKey('hearing_sms_reminders', $used);
    }

    public function test_no_code_branches_on_who_the_customer_is(): void
    {
        $offenders = [];
        foreach (['app', 'routes'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir))) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $rel = str_replace(base_path().'/', '', $file->getPathname());
                if (str_starts_with($rel, 'app/Tenancy/') || str_starts_with($rel, 'app/Console/Commands/Tenants')) {
                    continue;
                }
                // a tenant slug/key/name/domain compared with a literal
                if (preg_match('/(?:->slug|->key\(\)|tenant->name|->domain|Host\(\))\s*(?:===?|!==?)\s*[\'"]|in_array\([^)]*(?:->slug|->key\(\))/', file_get_contents($file->getPathname()))) {
                    $offenders[] = $rel;
                }
            }
        }
        $this->assertSame([], $offenders, 'customer-specific behaviour is a flag in config/flags.php, never a branch');
    }
}
