<?php

namespace Tests\Feature;

use App\Jobs\RunTenantBackup;
use App\Platform\PlatformAudit;
use App\Platform\PlatformStaff;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantMigrator;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** PLAT-7: platform-staff console. Real control-plane DB and real SQLite tenant databases. */
class PlatformConsoleTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private const HOST = 'http://admin.platform.test';

    private string $dir;

    private string $originalDefault;

    /** @var list<string> */
    private array $createdDbs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/plat7-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");

        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
            'tenancy.mode' => 'multi',
            'tenancy.platform_host' => 'admin.platform.test',
            'tenancy.base_domain' => 'app.test',
            'tenancy.retention_days' => 30,
            'tenancy.storage_disks' => ['local'],
            'portability.directory' => $this->dir,
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        foreach (glob(database_path('tenants/*.sqlite')) as $f) {
            if (str_contains($f, 'plat7-')) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    private function staff(string $role = 'admin', ?string $email = null): PlatformStaff
    {
        return PlatformStaff::create(['name' => ucfirst($role), 'email' => $email ?? "{$role}@platform.test", 'password' => Hash::make('correct-horse-battery'), 'role' => $role]);
    }

    private function token(string $role = 'admin'): string
    {
        $s = PlatformStaff::where('role', $role)->first() ?? $this->staff($role);

        return $this->postJson(self::HOST.'/api/platform/login', ['email' => $s->email, 'password' => 'correct-horse-battery'])->assertOk()->json('token');
    }

    private function api(string $method, string $path, array $data = [], ?string $token = null)
    {
        return $this->withToken($token ?? $this->token())->json($method, self::HOST.'/api/platform'.$path, $data);
    }

    private function tenant(string $slug, array $attrs = []): Tenant
    {
        touch("{$this->dir}/{$slug}.sqlite");

        return Tenant::create($attrs + ['slug' => $slug, 'name' => ucfirst($slug), 'status' => 'active', 'database' => "{$this->dir}/{$slug}.sqlite", 'schema_version' => '2026_09_30_170000_x', 'migration_status' => 'ok']);
    }

    // ---- where it exists ---------------------------------------------------------------------------------------

    public function test_it_does_not_exist_on_tenant_hosts_when_unconfigured_or_in_single_mode(): void
    {
        $this->staff();
        $this->postJson('http://firm.app.test/api/platform/login', ['email' => 'admin@platform.test', 'password' => 'correct-horse-battery'])->assertNotFound();

        config(['tenancy.platform_host' => null]);
        $this->postJson(self::HOST.'/api/platform/login', ['email' => 'admin@platform.test', 'password' => 'correct-horse-battery'])->assertNotFound();

        config(['tenancy.platform_host' => 'admin.platform.test', 'tenancy.mode' => 'single']);
        $this->postJson(self::HOST.'/api/platform/login', ['email' => 'admin@platform.test', 'password' => 'correct-horse-battery'])->assertNotFound();
    }

    public function test_the_platform_host_serves_nothing_but_the_console(): void
    {
        $home = $this->get(self::HOST.'/')->assertRedirect();
        $this->assertStringEndsWith('/console', (string) $home->headers->get('Location'));
        $this->getJson(self::HOST.'/api/v1/cases')->assertNotFound();
    }

    public function test_firm_login_links_to_operator_console_only_when_the_admin_host_is_configured(): void
    {
        $firm = $this->tenant('lawfirm');
        $firm->domains()->create(['domain' => 'firm.app.test']);
        $this->withoutVite();

        $this->get('http://firm.app.test/login')->assertOk()
            ->assertSee('data-platform-login-url="https://admin.platform.test/console"', false);

        config(['tenancy.platform_host' => null]);

        $this->get('http://firm.app.test/login')->assertOk()
            ->assertDontSee('data-platform-login-url=', false);
    }

    public function test_the_console_can_share_the_apps_own_address_for_local_use_but_never_in_production(): void
    {
        $firm = $this->tenant('lawfirm');
        $firm->domains()->create(['domain' => 'firm.app.test']);
        $this->staff();
        $this->withoutVite();
        $login = ['email' => 'admin@platform.test', 'password' => 'correct-horse-battery'];

        // off by default: a firm's address never serves the console
        $this->get('http://firm.app.test/console')->assertNotFound();
        $this->postJson('http://firm.app.test/api/platform/login', $login)->assertNotFound();

        // on (local/demo): the console and its API answer there, and the sign-in page offers Firm / Operator in place of a link
        config(['tenancy.console_on_app_host' => true]);
        $this->get('http://firm.app.test/console')->assertOk();
        $this->postJson('http://firm.app.test/api/platform/login', $login)->assertOk()->assertJsonStructure(['token']);
        $this->get('http://firm.app.test/login')->assertOk()
            ->assertSee('data-operator-login="1"', false)
            ->assertDontSee('data-platform-login-url=', false);

        // production ignores the switch: the console stays on the admin host alone
        $this->app['env'] = 'production';
        $this->get('http://firm.app.test/console')->assertNotFound();
        $this->get(self::HOST.'/console')->assertOk();
    }

    // ---- authentication ----------------------------------------------------------------------------------------

    public function test_login_gives_a_token_stored_only_as_a_hash_and_logout_kills_it(): void
    {
        $this->staff();
        $token = $this->postJson(self::HOST.'/api/platform/login', ['email' => 'ADMIN@platform.test', 'password' => 'correct-horse-battery'])->assertOk()->json('token');

        $this->assertSame(0, DB::connection('control')->table('platform_tokens')->where('token_hash', $token)->count());
        $this->assertSame(1, DB::connection('control')->table('platform_tokens')->where('token_hash', hash('sha256', $token))->count());
        $this->withToken($token)->getJson(self::HOST.'/api/platform/me')->assertOk()->assertJsonPath('role', 'admin');

        $this->withToken($token)->postJson(self::HOST.'/api/platform/logout')->assertOk();
        $this->withToken($token)->getJson(self::HOST.'/api/platform/me')->assertUnauthorized();
    }

    public function test_bad_credentials_are_one_generic_answer_and_five_failures_lock_the_account(): void
    {
        $this->staff();
        $unknown = $this->postJson(self::HOST.'/api/platform/login', ['email' => 'nobody@platform.test', 'password' => 'x'.str_repeat('y', 12)])->assertUnauthorized()->json('message');
        $wrong = $this->postJson(self::HOST.'/api/platform/login', ['email' => 'admin@platform.test', 'password' => 'wrong-password-1'])->assertUnauthorized()->json('message');
        $this->assertSame($unknown, $wrong, 'no hint whether the email exists');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson(self::HOST.'/api/platform/login', ['email' => 'admin@platform.test', 'password' => 'wrong-password-1'])->assertUnauthorized();
        }
        // locked: even the right password is refused
        $this->postJson(self::HOST.'/api/platform/login', ['email' => 'admin@platform.test', 'password' => 'correct-horse-battery'])->assertUnauthorized();

        $this->travel(16)->minutes();
        $this->postJson(self::HOST.'/api/platform/login', ['email' => 'admin@platform.test', 'password' => 'correct-horse-battery'])->assertOk();
        $this->travelBack();
    }

    public function test_missing_expired_and_disabled_tokens_are_refused(): void
    {
        $this->getJson(self::HOST.'/api/platform/tenants')->assertUnauthorized();
        $this->withToken('nonsense')->getJson(self::HOST.'/api/platform/tenants')->assertUnauthorized();

        $token = $this->token();
        $this->travel(9)->hours();
        $this->withToken($token)->getJson(self::HOST.'/api/platform/tenants')->assertUnauthorized();
        $this->travelBack();

        $token = $this->token();
        PlatformStaff::where('role', 'admin')->update(['active' => false]);
        $this->withToken($token)->getJson(self::HOST.'/api/platform/tenants')->assertUnauthorized();
    }

    public function test_a_tenant_user_token_is_not_a_platform_token(): void
    {
        $plain = '1|'.Str::random(40);   // the shape of a tenant (Sanctum) token
        $this->withToken($plain)->getJson(self::HOST.'/api/platform/tenants')->assertUnauthorized();
    }

    // ---- roles -------------------------------------------------------------------------------------------------

    public function test_roles_limit_what_staff_can_do_and_denials_are_audited(): void
    {
        $this->tenant('acme');
        $viewer = $this->token('viewer');
        $operator = $this->token('operator');

        $this->api('GET', '/tenants', [], $viewer)->assertOk();
        $this->api('POST', '/tenants/acme/suspend', ['reason' => 'non payment'], $viewer)->assertForbidden();
        $this->assertSame('active', Tenant::where('slug', 'acme')->value('status'));
        $this->assertSame(1, PlatformAudit::where('result', 'denied')->where('staff_email', 'viewer@platform.test')->where('tenant_slug', 'acme')->count());

        $this->api('POST', '/tenants/acme/suspend', ['reason' => 'non payment'], $operator)->assertOk();
        $this->api('POST', '/tenants/acme/resume', ['reason' => 'paid in full'], $operator)->assertOk();
        $this->api('POST', '/tenants/acme/offboard', ['confirm' => 'acme', 'reason' => 'customer left'], $operator)->assertForbidden();
        $this->api('POST', '/tenants/acme/purge', ['confirm' => 'acme', 'reason' => 'customer left'], $operator)->assertForbidden();
        $this->api('POST', '/tenants/acme/legal-hold', ['hold' => true, 'reason' => 'litigation'], $operator)->assertForbidden();
        $this->assertSame('active', Tenant::where('slug', 'acme')->value('status'));
    }

    // ---- read --------------------------------------------------------------------------------------------------

    public function test_tenant_list_and_detail_show_status_schema_version_and_usage_without_business_data(): void
    {
        $a = $this->tenant('acme', ['plan' => 'professional']);
        $b = $this->tenant('globex', ['status' => 'suspended', 'schema_version' => '2026_08_01_old', 'migration_status' => 'failed', 'migration_error' => 'boom']);
        $a->domains()->create(['domain' => 'acme.app.test']);
        foreach ([['2026-09-29', 'users', 3], ['2026-09-30', 'users', 5], ['2026-09-30', 'matters', 40]] as [$day, $metric, $v]) {
            DB::connection('control')->table('tenant_usage_daily')->insert(['tenant_id' => $a->id, 'day' => $day, 'metric' => $metric, 'value' => $v, 'created_at' => now(), 'updated_at' => now()]);
        }

        $list = $this->api('GET', '/tenants')->assertOk()->json('data');
        $this->assertSame(['acme', 'globex'], collect($list)->pluck('slug')->all());
        $acme = collect($list)->firstWhere('slug', 'acme');
        $this->assertSame(['users' => 5, 'matters' => 40], $acme['usage'], 'latest value per metric');
        $this->assertSame(['acme.app.test'], $acme['domains']);
        $this->assertSame('2026_09_30_170000_x', $acme['schema_version']);

        $this->assertSame(['globex'], collect($this->api('GET', '/tenants?status=suspended')->json('data'))->pluck('slug')->all());
        $this->assertSame(['acme'], collect($this->api('GET', '/tenants?q=cm')->json('data'))->pluck('slug')->all());

        $detail = $this->api('GET', '/tenants/acme')->assertOk()->json('data');
        $this->assertSame([3, 5], collect($detail['usage_history']['users'])->pluck('value')->all());
        $this->api('GET', '/tenants/nope')->assertNotFound();

        $fleet = $this->api('GET', '/fleet')->assertOk()->json('data');
        $this->assertSame(2, $fleet['tenants']);
        $this->assertSame('boom', $fleet['failed_migrations'][0]['error']);
        $this->assertSame(['active' => 1, 'suspended' => 1], $fleet['by_status']);
    }

    // ---- provision / lifecycle ---------------------------------------------------------------------------------

    public function test_provisioning_creates_a_working_tenant_shows_the_password_once_and_audits_without_it(): void
    {
        $res = $this->api('POST', '/tenants', ['slug' => 'plat7-newco', 'name' => 'Newco LLP', 'admin_email' => 'owner@newco.test', 'plan' => 'starter'])->assertCreated();

        $this->assertNotEmpty($res->json('admin_password'));
        $this->assertSame('active', $res->json('data.status'));
        $this->assertSame(['plat7-newco.app.test'], $res->json('data.domains'));
        $this->assertSame('active', Tenant::where('slug', 'plat7-newco')->value('status'));

        $row = PlatformAudit::where('action', 'tenant.provision')->first();
        $this->assertSame('ok', $row->result);
        $this->assertSame('admin@platform.test', $row->staff_email);
        $this->assertStringNotContainsString($res->json('admin_password'), json_encode($row->toArray()));
        $this->assertArrayNotHasKey('admin_password', (array) $row->params);
        $this->assertSame('owner@newco.test', $row->params['admin_email']);

        $this->api('POST', '/tenants', ['slug' => 'plat7-newco', 'name' => 'Again', 'admin_email' => 'x@newco.test'])->assertStatus(422);
        $this->assertSame(1, PlatformAudit::where('action', 'tenant.provision')->where('result', 'failed')->count());
        $this->api('POST', '/tenants', ['slug' => 'Bad Slug!', 'name' => 'x', 'admin_email' => 'x@y.test'])->assertStatus(422);
    }

    public function test_suspend_and_resume_need_a_reason_take_effect_on_the_tenants_own_host_and_are_audited(): void
    {
        $t = $this->tenant('acme');
        $t->domains()->create(['domain' => 'acme.app.test']);

        $this->api('POST', '/tenants/acme/suspend', [])->assertStatus(422);
        $this->assertSame('active', $t->fresh()->status);

        $this->api('POST', '/tenants/acme/suspend', ['reason' => 'invoice 90 days overdue'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->getJson('http://acme.app.test/api/v1/user')->assertForbidden();   // the firm's own host refuses service

        $this->api('POST', '/tenants/acme/suspend', ['reason' => 'again again'])->assertStatus(422);   // not active any more
        $this->api('POST', '/tenants/acme/resume', ['reason' => 'paid in full'])->assertOk();
        $this->assertSame('active', $t->fresh()->status);

        $rows = PlatformAudit::where('tenant_slug', 'acme')->orderBy('id')->get();
        $this->assertSame(['tenant.suspend', 'tenant.suspend', 'tenant.resume'], $rows->pluck('action')->all());
        $this->assertSame(['ok', 'failed', 'ok'], $rows->pluck('result')->all());
        $this->assertSame('invoice 90 days overdue', $rows[0]->params['reason']);
    }

    public function test_migrate_reports_success_and_failure(): void
    {
        $good = $this->tenant('plat7-good', ['schema_version' => null, 'migration_status' => 'pending', 'database' => "{$this->dir}/good.sqlite"]);
        touch("{$this->dir}/good.sqlite");
        $bad = $this->tenant('bad', ['database' => '/nonexistent-dir/bad.sqlite']);

        $ok = $this->api('POST', '/tenants/plat7-good/migrate')->assertOk()->json('data');
        $this->assertTrue($ok['ok']);
        $this->assertNotEmpty($ok['schema_version']);
        $this->assertSame('ok', $good->fresh()->migration_status);

        $fail = $this->api('POST', '/tenants/bad/migrate')->assertStatus(500)->json('data');
        $this->assertFalse($fail['ok']);
        $this->assertSame('failed', $bad->fresh()->migration_status);
        $this->assertSame(['ok', 'failed'], PlatformAudit::where('action', 'tenant.migrate')->orderBy('id')->pluck('result')->all());
    }

    public function test_migrating_a_firm_that_is_already_migrating_is_a_conflict(): void
    {
        config(['cache.default' => 'file']);
        $t = $this->tenant('plat7-busy', ['migration_status' => 'pending']);
        $held = app(TenantManager::class)->runIn($t, fn () => tap(Cache::lock('tenant-migrate', 60))->get());

        try {
            $this->api('POST', '/tenants/plat7-busy/migrate')->assertStatus(409)->assertJsonPath('code', 'migration_running');
        } finally {
            $held->release();
        }
        $this->assertSame('pending', $t->fresh()->migration_status);
        $this->assertSame('busy', PlatformAudit::where('action', 'tenant.migrate')->value('result'));
    }

    public function test_the_fleet_view_shows_where_firms_stand_against_this_release(): void
    {
        $latest = TenantMigrator::latestVersion();
        $this->tenant('current', ['schema_version' => $latest]);
        $this->tenant('old');
        $this->tenant('fresh', ['schema_version' => null, 'migration_status' => 'pending']);
        $this->tenant('broken', ['migration_status' => 'failed', 'migration_error' => 'boom']);
        $this->tenant('left', ['status' => 'offboarded']);

        $d = $this->api('GET', '/fleet')->assertOk()->json('data');

        $this->assertSame($latest, $d['latest_version']);
        $this->assertSame(3, $d['behind'], 'old, fresh and broken (offboarded firms do not count)');
        $this->assertEquals(['ok' => 2, 'pending' => 1, 'failed' => 1], $d['by_migration_status']);
        $this->assertSame(1, $d['schema_versions'][$latest]);
        $this->assertSame([['slug' => 'broken', 'error' => 'boom']], $d['failed_migrations']);
    }

    public function test_the_dashboard_page_is_served_on_the_platform_host_only_and_holds_no_data(): void
    {
        $this->tenant('secretfirm');
        $page = $this->get(self::HOST.'/api/platform/dashboard')->assertOk();

        $this->assertStringContainsString('Fleet migrations', $page->getContent());
        $this->assertStringNotContainsString('secretfirm', $page->getContent());
        $csp = (string) $page->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'nonce-[A-Za-z0-9+\/=]+'/", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString('nonce="', $page->getContent());

        $this->get('http://firm.app.test/api/platform/dashboard')->assertNotFound();
    }

    public function test_the_staff_console_has_its_own_login_and_is_never_served_on_a_firm_host(): void
    {
        $this->tenant('secretfirm');
        $page = $this->get(self::HOST.'/console')->assertOk();

        $this->assertStringContainsString('id="platform-root"', $page->getContent());
        $this->assertStringContainsString('platform-console.js', $page->getContent());
        $this->assertStringNotContainsString('secretfirm', $page->getContent());
        $csp = (string) $page->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));

        $this->get('http://firm.app.test/console')->assertNotFound();
    }

    public function test_backup_is_queued_and_the_outcome_lands_in_the_audit_log(): void
    {
        $this->tenant('acme');
        Queue::fake();
        $this->api('POST', '/tenants/acme/backup')->assertStatus(202);
        Queue::assertPushed(RunTenantBackup::class, fn ($j) => $j->slug === 'acme');
        $this->assertSame(1, PlatformAudit::where('action', 'tenant.backup.requested')->count());

        foreach ([0 => 'ok', 1 => 'failed'] as $exit => $expected) {
            Artisan::registerCommand(new class($exit) extends Command
            {
                protected $signature = 'backup:run {--tenant=} {--dry-run}';

                public function __construct(private int $exit)
                {
                    parent::__construct();
                }

                public function handle(): int
                {
                    $this->line($this->exit ? 'dump failed' : 'written');

                    return $this->exit;
                }
            });
            (new RunTenantBackup('acme', PlatformStaff::first()?->id ?? $this->staff()->id, 'admin@platform.test'))->handle();
            $this->assertSame($expected, PlatformAudit::where('action', 'tenant.backup.finished')->orderByDesc('id')->value('result'));
        }
    }

    public function test_backup_command_refuses_unknown_tenants_and_single_mode(): void
    {
        $this->artisan('backup:run', ['--tenant' => 'ghost'])->expectsOutputToContain('existing tenant')->assertExitCode(1);
        config(['tenancy.mode' => 'single']);
        $this->artisan('backup:run', ['--tenant' => 'ghost'])->expectsOutputToContain('multi-tenant mode')->assertExitCode(1);
    }

    // ---- destructive -------------------------------------------------------------------------------------------

    public function test_offboard_and_purge_need_admin_a_typed_slug_and_a_reason_and_respect_retention_and_legal_hold(): void
    {
        $t = $this->tenant('acme');

        $this->api('POST', '/tenants/acme/offboard', ['confirm' => 'wrong', 'reason' => 'customer left'])->assertStatus(422);
        $this->api('POST', '/tenants/acme/offboard', ['confirm' => 'acme'])->assertStatus(422);
        $this->assertSame('active', $t->fresh()->status);
        $this->assertSame(1, PlatformAudit::where('action', 'tenant.offboard')->where('result', 'denied')->count());

        $this->api('POST', '/tenants/acme/offboard', ['confirm' => 'acme', 'reason' => 'customer left'])->assertOk()->assertJsonPath('data.status', 'offboarded');
        $this->assertNotNull($t->fresh()->purge_after);

        $this->api('POST', '/tenants/acme/purge', ['confirm' => 'acme', 'reason' => 'retention over'])->assertStatus(422);   // inside the window
        $this->api('POST', '/tenants/acme/legal-hold', ['hold' => true, 'reason' => 'litigation hold'])->assertOk()->assertJsonPath('data.legal_hold', true);

        $this->travel(31)->days();
        $this->api('POST', '/tenants/acme/purge', ['confirm' => 'acme', 'reason' => 'retention over'])->assertStatus(422);   // legal hold wins
        $this->assertSame('offboarded', $t->fresh()->status);

        $this->api('POST', '/tenants/acme/legal-hold', ['hold' => false, 'reason' => 'hold released'])->assertOk();
        // TEN-9: this firm's database was never set up, so its final export failed, and purge waits for one
        $this->assertNotNull($t->fresh()->final_export_error);
        $refused = $this->api('POST', '/tenants/acme/purge', ['confirm' => 'acme', 'reason' => 'retention over'])->assertStatus(422);
        $this->assertStringContainsString('no final export', $refused->json('message'));
        $this->api('POST', '/tenants/acme/purge', ['confirm' => 'acme', 'reason' => 'retention over', 'without_export' => 'empty test firm, nothing to export'])
            ->assertOk()->assertJsonPath('data.status', 'purged')->assertJsonPath('data.purge_report.final_export', 'skipped: empty test firm, nothing to export');
        $this->travelBack();
        $this->assertSame(['ok', 'ok', 'ok'], PlatformAudit::whereIn('action', ['tenant.offboard', 'tenant.purge', 'tenant.legal_hold.clear'])->where('result', 'ok')->pluck('result')->all());
    }

    // ---- audit -------------------------------------------------------------------------------------------------

    public function test_audit_log_is_filterable_and_append_only(): void
    {
        $this->tenant('acme');
        $this->tenant('globex');
        $this->api('POST', '/tenants/acme/suspend', ['reason' => 'reason one'])->assertOk();
        $this->api('POST', '/tenants/globex/suspend', ['reason' => 'reason two'])->assertOk();

        $rows = $this->api('GET', '/audit?tenant_slug=acme&action=tenant.suspend')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('admin@platform.test', $rows[0]['staff_email']);

        $entry = PlatformAudit::first();
        foreach ([fn () => $entry->update(['result' => 'x']), fn () => $entry->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('mutated');
            } catch (\LogicException) {
            }
        }
    }

    public function test_the_audit_log_itself_strips_secrets_whatever_the_caller_passes(): void
    {
        $row = PlatformAudit::record(null, 'x', 'acme', ['password' => 'p', 'admin_password' => 'a', 'token' => 't', 'secret' => 's', 'keep' => 1], 'ok', null, 'cli');

        $this->assertSame(['keep' => 1], $row->fresh()->params);
    }

    // ---- staff management (CLI) --------------------------------------------------------------------------------

    public function test_staff_cli_creates_changes_role_and_disabling_kills_live_tokens(): void
    {
        $this->artisan('platform:staff', ['action' => 'create', 'email' => 'new@platform.test', '--role' => 'operator', '--password' => 'short'])->assertExitCode(1);
        $this->artisan('platform:staff', ['action' => 'create', 'email' => 'new@platform.test', '--role' => 'god', '--password' => 'long-enough-pass'])->assertExitCode(1);
        $this->artisan('platform:staff', ['action' => 'create', 'email' => 'new@platform.test', '--role' => 'operator', '--password' => 'long-enough-pass'])->assertExitCode(0);
        $this->artisan('platform:staff', ['action' => 'create', 'email' => 'new@platform.test', '--password' => 'long-enough-pass'])->assertExitCode(1);

        $token = $this->postJson(self::HOST.'/api/platform/login', ['email' => 'new@platform.test', 'password' => 'long-enough-pass'])->assertOk()->json('token');
        $this->withToken($token)->getJson(self::HOST.'/api/platform/me')->assertOk()->assertJsonPath('role', 'operator');

        $this->artisan('platform:staff', ['action' => 'role', 'email' => 'new@platform.test', '--role' => 'viewer'])->assertExitCode(0);
        $this->withToken($token)->getJson(self::HOST.'/api/platform/me')->assertJsonPath('role', 'viewer');

        $this->artisan('platform:staff', ['action' => 'disable', 'email' => 'new@platform.test'])->assertExitCode(0);
        $this->withToken($token)->getJson(self::HOST.'/api/platform/me')->assertUnauthorized();
        $this->assertSame(0, DB::connection('control')->table('platform_tokens')->count());
        $this->assertSame(['staff.create', 'staff.role', 'staff.disable'], PlatformAudit::where('action', 'like', 'staff.%')->orderBy('id')->pluck('action')->all());
    }

    // ---- "Run system updates": which firms the console offers it for -------------------------------------------

    public function test_a_firm_is_flagged_for_updates_when_it_is_behind_or_its_last_update_failed(): void
    {
        $latest = TenantMigrator::latestVersion();
        $this->tenant('plat7-current', ['schema_version' => $latest, 'migration_status' => 'ok']);
        $this->tenant('plat7-behind', ['schema_version' => '2020_01_01_000000_old', 'migration_status' => 'ok']);
        $this->tenant('plat7-never', ['schema_version' => null, 'migration_status' => 'pending']);
        $this->tenant('plat7-failed', ['schema_version' => $latest, 'migration_status' => 'failed']);
        $this->tenant('plat7-gone', ['schema_version' => '2020_01_01_000000_old', 'status' => 'offboarded']);

        $needs = collect($this->api('GET', '/tenants')->assertOk()->json('data'))->pluck('update_needed', 'slug')->all();

        $this->assertFalse($needs['plat7-current']);
        $this->assertTrue($needs['plat7-behind']);
        $this->assertTrue($needs['plat7-never']);
        $this->assertTrue($needs['plat7-failed']);
        $this->assertFalse($needs['plat7-gone'], 'a closed firm is never offered an update');
    }

    public function test_running_the_update_clears_the_flag_and_the_fleet_count(): void
    {
        $this->tenant('plat7-behind', ['schema_version' => '2020_01_01_000000_old', 'migration_status' => 'ok']);
        $this->assertSame(1, $this->api('GET', '/fleet')->assertOk()->json('data.behind'));

        $this->api('POST', '/tenants/plat7-behind/migrate')->assertOk();

        $this->assertSame(0, $this->api('GET', '/fleet')->json('data.behind'));
        $row = collect($this->api('GET', '/tenants')->json('data'))->firstWhere('slug', 'plat7-behind');
        $this->assertFalse($row['update_needed']);
        $this->assertSame(TenantMigrator::latestVersion(), $row['schema_version']);
    }

    public function test_updating_one_firm_after_another_as_the_overview_button_does(): void
    {
        foreach (['plat7-a', 'plat7-b'] as $slug) {
            $this->tenant($slug, ['schema_version' => null, 'migration_status' => 'pending', 'database' => "{$this->dir}/{$slug}.sqlite"]);
        }
        $token = $this->token();
        $todo = collect($this->api('GET', '/tenants', [], $token)->json('data'))->where('update_needed', true)->pluck('slug')->sort()->values()->all();
        $this->assertSame(['plat7-a', 'plat7-b'], $todo);

        foreach ($todo as $slug) {
            $this->api('POST', "/tenants/{$slug}/migrate", [], $token)->assertOk();
        }

        $this->assertSame([], collect($this->api('GET', '/tenants', [], $token)->json('data'))->where('update_needed', true)->all());
        $this->assertSame(2, PlatformAudit::where('action', 'tenant.migrate')->where('result', 'ok')->count());
    }

    public function test_a_viewer_cannot_run_updates(): void
    {
        $this->tenant('plat7-behind', ['schema_version' => null, 'migration_status' => 'pending']);
        $viewer = $this->token('viewer');

        $this->api('GET', '/tenants', [], $viewer)->assertOk();   // sees that an update is due
        $this->api('POST', '/tenants/plat7-behind/migrate', [], $viewer)->assertForbidden();
        $this->assertSame('pending', Tenant::where('slug', 'plat7-behind')->value('migration_status'));
    }

    public function test_the_console_offers_the_buttons_in_system_update_wording(): void
    {
        $js = (string) file_get_contents(public_path('platform-console.js'));
        $this->assertStringContainsString('data-tenant-action="migrate"', $js);
        $this->assertStringContainsString('data-fleet-action="update-all"', $js);
        $this->assertStringContainsString('Run system updates', $js);
        $this->assertStringNotContainsStringIgnoringCase('database updates', $js, 'the console speaks of system updates, not databases');
    }
}
