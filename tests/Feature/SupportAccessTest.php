<?php

namespace Tests\Feature;

use App\Mail\SupportAccessNotice;
use App\Models\Client;
use App\Models\SupportAccessSession;
use App\Models\User;
use App\Observability\UsageMetrics;
use App\Platform\PlatformAudit;
use App\Platform\PlatformStaff;
use App\SupportAccess\SupportPolicy;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** PLAT-8: audited, time-boxed, firm-controlled platform support access. Real control plane, real SQLite tenant databases. */
class SupportAccessTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private const PLATFORM = 'http://admin.platform.test';

    private const FIRM = 'http://acme.app.test';

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.console' => true]);
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/plat8-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
            'tenancy.mode' => 'multi',
            'tenancy.platform_host' => 'admin.platform.test',
            'tenancy.base_domain' => 'app.test',
            'tenancy.storage_disks' => ['local'],
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);

        $this->provision('acme');
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        foreach (glob(database_path('tenants/*.sqlite')) as $f) {
            if (str_contains($f, 'plat8-')) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    private function provision(string $slug): Tenant
    {
        return app(TenantLifecycle::class)->provision([
            'slug' => $slug, 'name' => ucfirst($slug), 'admin_email' => "admin@{$slug}.test", 'domain' => "{$slug}.app.test",
            'database' => "{$this->dir}/plat8-{$slug}.sqlite",
        ])['tenant'];
    }

    private function inTenant(string $slug, \Closure $fn)
    {
        return app(TenantManager::class)->runIn(Tenant::where('slug', $slug)->first(), $fn, allowInactive: true);
    }

    private function firmAdmin(string $slug = 'acme'): User
    {
        return $this->inTenant($slug, fn () => User::where('email', "admin@{$slug}.test")->first());
    }

    private function staff(string $role): PlatformStaff
    {
        return PlatformStaff::where('role', $role)->first() ?? PlatformStaff::create(['name' => ucfirst($role).' Person', 'email' => "{$role}@platform.test", 'password' => Hash::make('correct-horse-battery'), 'role' => $role]);
    }

    private function staffToken(string $role = 'support'): string
    {
        $s = $this->staff($role);

        return $this->postJson(self::PLATFORM.'/api/platform/login', ['email' => $s->email, 'password' => 'correct-horse-battery'])->assertOk()->json('token');
    }

    private function platform(string $method, string $path, array $data = [], string $role = 'support')
    {
        return $this->withToken($this->staffToken($role))->json($method, self::PLATFORM.'/api/platform'.$path, $data);
    }

    private function firm(User $as, string $method, string $path, array $data = [], string $host = self::FIRM)
    {
        return $this->actingAsOnHost($as, $host)->json($method, $host.'/api/v1'.$path, $data);
    }

    private function policy(array $changes): void
    {
        $t = Tenant::where('slug', 'acme')->first();
        SupportPolicy::update($t, $changes);
    }

    /** request -> (approve) -> start; returns [sessionId, code, principal] */
    private function openSession(array $request = [], string $role = 'support'): array
    {
        $req = $this->platform('POST', '/tenants/acme/support-sessions', $request + ['reason' => 'Investigating a stuck invoice', 'minutes' => 30], $role)->assertCreated();
        $id = $req->json('data.id');
        if ($req->json('data.status') === 'pending') {
            $this->firm($this->firmAdmin(), 'POST', "/support-access/{$id}/approve")->assertOk();
        }
        $start = $this->platform('POST', "/tenants/acme/support-sessions/{$id}/start", [], $role)->assertOk();
        $principal = $this->inTenant('acme', fn () => User::find(SupportAccessSession::find($id)->user_id));

        return [$id, $start->json('code'), $principal];
    }

    // ---- policy ------------------------------------------------------------------------------------------------

    public function test_defaults_are_the_safe_ones_and_only_the_firm_can_change_them_and_the_change_is_audited(): void
    {
        $admin = $this->firmAdmin();
        $this->assertSame(['enabled' => true, 'require_approval' => true, 'allow_write' => false, 'max_minutes' => 60], $this->firm($admin, 'GET', '/support-access/policy')->assertOk()->json('data'));

        $this->firm($admin, 'PUT', '/support-access/policy', ['allow_write' => true, 'max_minutes' => 120])->assertOk();
        $this->assertSame(['enabled' => true, 'require_approval' => true, 'allow_write' => true, 'max_minutes' => 120], SupportPolicy::for(Tenant::where('slug', 'acme')->first()));
        $this->firm($admin, 'PUT', '/support-access/policy', ['max_minutes' => 99999])->assertStatus(422);

        $row = $this->inTenant('acme', fn () => DB::table('audits')->where('event', 'support_policy_updated')->first());
        $this->assertSame($admin->id, $row->user_id);
        $this->assertSame(false, json_decode($row->old_values, true)['allow_write']);
        $this->assertSame(true, json_decode($row->new_values, true)['allow_write']);

        // an ordinary firm user cannot
        $plain = $this->inTenant('acme', function () {
            $u = User::create(['name' => 'Plain', 'email' => 'plain@acme.test', 'password' => Hash::make('x'), 'phone_number' => '', 'department' => 'x', 'hire_date' => now()]);
            $u->assignRole('advocate');

            return $u;
        });
        $this->firm($plain, 'PUT', '/support-access/policy', ['enabled' => false])->assertForbidden();
        $this->firm($plain, 'GET', '/support-access')->assertForbidden();
    }

    public function test_platform_staff_cannot_change_the_policy(): void
    {
        $this->platform('PUT', '/tenants/acme/support-policy', ['enabled' => true], 'admin')->assertNotFound();
        $this->assertSame(SupportPolicy::DEFAULTS, SupportPolicy::for(Tenant::where('slug', 'acme')->first()));
    }

    // ---- request / approval ------------------------------------------------------------------------------------

    public function test_a_request_needs_a_real_reason_a_support_role_and_waits_for_approval_and_is_recorded_everywhere(): void
    {
        Mail::fake();
        $this->platform('POST', '/tenants/acme/support-sessions', ['reason' => 'short'])->assertStatus(422);
        $this->platform('POST', '/tenants/acme/support-sessions', ['reason' => 'Investigating a stuck invoice'], 'viewer')->assertForbidden();
        $this->platform('POST', '/tenants/acme/support-sessions', ['reason' => 'Investigating a stuck invoice'], 'operator')->assertForbidden();

        $res = $this->platform('POST', '/tenants/acme/support-sessions', ['reason' => 'Investigating a stuck invoice', 'minutes' => 30])->assertCreated();
        $this->assertSame('pending', $res->json('data.status'));
        $id = $res->json('data.id');

        // the firm's own audit log
        $audit = $this->inTenant('acme', fn () => DB::table('audits')->where('auditable_type', SupportAccessSession::class)->where('auditable_id', $id)->first());
        $this->assertSame('created', $audit->event);
        $this->assertSame('support-access', $audit->tags);
        $this->assertStringContainsString('Investigating a stuck invoice', $audit->new_values);
        // the platform's audit log
        $p = PlatformAudit::where('action', 'support.request')->first();
        $this->assertSame(['ok', 'support@platform.test', 'acme'], [$p->result, $p->staff_email, $p->tenant_slug]);
        // the firm's administrators are told
        Mail::assertQueued(SupportAccessNotice::class, fn ($m) => $m->hasTo('admin@acme.test'));

        // cannot start before the firm approves
        $this->platform('POST', "/tenants/acme/support-sessions/{$id}/start")->assertStatus(422);
        // one open session per person
        $this->platform('POST', '/tenants/acme/support-sessions', ['reason' => 'Second request for same firm'])->assertStatus(422);
    }

    public function test_the_firm_sees_sessions_and_can_deny(): void
    {
        $id = $this->platform('POST', '/tenants/acme/support-sessions', ['reason' => 'Investigating a stuck invoice'])->json('data.id');
        $admin = $this->firmAdmin();

        $list = $this->firm($admin, 'GET', '/support-access')->assertOk()->json('data');
        $this->assertSame($id, $list['sessions'][0]['id']);
        $this->assertSame('pending', $list['sessions'][0]['status']);
        $this->assertSame('Support Person', $list['sessions'][0]['staff_name']);

        $this->firm($admin, 'POST', "/support-access/{$id}/deny")->assertOk();
        $this->assertSame('denied', $this->inTenant('acme', fn () => SupportAccessSession::find($id)->status));
        $this->platform('POST', "/tenants/acme/support-sessions/{$id}/start")->assertStatus(422);
        $this->firm($admin, 'POST', "/support-access/{$id}/approve")->assertStatus(422);
    }

    public function test_standing_approval_policy_lets_a_request_through_immediately(): void
    {
        $this->policy(['require_approval' => false]);
        $res = $this->platform('POST', '/tenants/acme/support-sessions', ['reason' => 'Investigating a stuck invoice'])->assertCreated();
        $this->assertSame('approved', $res->json('data.status'));
        $this->platform('POST', '/tenants/acme/support-sessions/'.$res->json('data.id').'/start')->assertOk();
    }

    public function test_a_firm_can_switch_support_off_and_it_ends_whatever_is_running(): void
    {
        [$id, , $principal] = $this->openSession();
        $this->firm($this->firmAdmin(), 'PUT', '/support-access/policy', ['enabled' => false])->assertOk();

        $this->assertSame('revoked', $this->inTenant('acme', fn () => SupportAccessSession::find($id)->status));
        $this->firm($principal, 'GET', '/cases')->assertUnauthorized();
        $this->platform('POST', '/tenants/acme/support-sessions', ['reason' => 'Trying again regardless'])->assertStatus(422);
    }

    public function test_requests_beyond_the_firms_limits_are_refused(): void
    {
        $this->platform('POST', '/tenants/acme/support-sessions', ['reason' => 'Investigating a stuck invoice', 'minutes' => 120])->assertStatus(422);   // max 60
        $this->platform('POST', '/tenants/acme/support-sessions', ['reason' => 'Investigating a stuck invoice', 'mode' => 'full'])->assertStatus(422);       // read-only firm
        $this->assertSame(2, PlatformAudit::where('action', 'support.request')->where('result', 'failed')->count());
    }

    public function test_suspended_firms_cannot_be_entered(): void
    {
        Tenant::where('slug', 'acme')->update(['status' => 'suspended']);
        $this->platform('POST', '/tenants/acme/support-sessions', ['reason' => 'Investigating a stuck invoice'])->assertStatus(422);
    }

    // ---- start / exchange --------------------------------------------------------------------------------------

    public function test_start_creates_an_ephemeral_principal_that_is_not_a_billable_seat_and_cannot_use_a_password(): void
    {
        $seatsBefore = $this->inTenant('acme', fn () => app(UsageMetrics::class)->current('users'));
        [$id, $code, $principal] = $this->openSession();

        $this->assertSame(40, strlen($code));
        $this->assertNotNull($principal->platform_staff_id);
        $this->assertStringEndsWith('@support.invalid', $principal->email);
        $this->assertTrue($principal->hasRole('support-read'));
        $this->assertSame($seatsBefore, $this->inTenant('acme', fn () => app(UsageMetrics::class)->current('users')), 'support principals never use a seat');

        // no password works: the account is only reachable through a one-time code
        $this->postJson(self::FIRM.'/api/v1/login', ['email' => $principal->email, 'password' => 'anything-at-all'])->assertStatus(401);

        // the code is never written to any audit log
        $this->assertStringNotContainsString($code, json_encode(PlatformAudit::all()->toArray()));
        $this->assertStringNotContainsString($code, json_encode($this->inTenant('acme', fn () => DB::table('audits')->get())));
    }

    public function test_the_code_logs_the_principal_in_once_only_and_only_on_its_own_tenant(): void
    {
        $this->provision('globex');
        [$id, $code] = $this->openSession();

        $this->postJson('http://globex.app.test/api/v1/support/session', ['code' => $code])->assertUnauthorized();   // wrong firm
        $this->postJson(self::FIRM.'/api/v1/support/session', ['code' => str_repeat('x', 40)])->assertUnauthorized();
        $ok = $this->postJson(self::FIRM.'/api/v1/support/session', ['code' => $code])->assertOk();
        $this->assertStringStartsWith('Support: Support Person', $ok->json('data.0.name'));
        $this->postJson(self::FIRM.'/api/v1/support/session', ['code' => $code])->assertUnauthorized();   // single use
    }

    public function test_an_unused_code_expires_after_five_minutes(): void
    {
        [, $code] = $this->openSession();
        $this->travel(6)->minutes();
        $this->postJson(self::FIRM.'/api/v1/support/session', ['code' => $code])->assertUnauthorized();
        $this->travelBack();
    }

    public function test_only_the_requester_can_start_and_a_stale_approval_cannot_be_started(): void
    {
        $this->policy(['require_approval' => false]);
        $id = $this->platform('POST', '/tenants/acme/support-sessions', ['reason' => 'Investigating a stuck invoice'])->json('data.id');

        $other = PlatformStaff::create(['name' => 'Other', 'email' => 'other@platform.test', 'password' => Hash::make('correct-horse-battery'), 'role' => 'support']);
        $tok = $this->postJson(self::PLATFORM.'/api/platform/login', ['email' => 'other@platform.test', 'password' => 'correct-horse-battery'])->json('token');
        $this->withToken($tok)->postJson(self::PLATFORM."/api/platform/tenants/acme/support-sessions/{$id}/start")->assertStatus(422);

        $this->travel(25)->hours();
        $this->platform('POST', "/tenants/acme/support-sessions/{$id}/start")->assertStatus(422);
        $this->assertSame('expired', $this->inTenant('acme', fn () => SupportAccessSession::find($id)->status));
        $this->travelBack();
    }

    // ---- what the principal can do -----------------------------------------------------------------------------

    public function test_a_read_only_session_reads_but_cannot_change_anything_and_every_request_is_logged_for_the_firm(): void
    {
        [$id, , $principal] = $this->openSession();

        $this->firm($principal, 'GET', '/cases')->assertOk();
        $this->firm($principal, 'GET', '/clients')->assertOk();
        $this->firm($principal, 'POST', '/clients', ['name' => 'X', 'phone_number' => '0712345678', 'address' => 'a', 'advocate' => $principal->id])->assertForbidden();
        $this->firm($principal, 'DELETE', '/cases/1')->assertForbidden();
        $this->firm($principal, 'GET', '/export/firm-data')->assertForbidden();

        $log = $this->firm($this->firmAdmin(), 'GET', "/support-access/{$id}/log")->assertOk()->json('data');
        $this->assertSame(['GET api/v1/cases', 'GET api/v1/clients', 'POST api/v1/clients', 'DELETE api/v1/cases/1', 'GET api/v1/export/firm-data'],
            collect($log)->map(fn ($r) => $r['method'].' '.$r['path'])->all());
        $this->assertSame([200, 200, 403, 403, 403], collect($log)->pluck('status')->all());
    }

    public function test_read_only_is_enforced_by_the_guard_even_if_the_principal_somehow_holds_a_write_permission(): void
    {
        [, , $principal] = $this->openSession();
        $this->inTenant('acme', fn () => $principal->givePermissionTo(['create-clients', 'delete-cases']));
        Queue::fake();

        $this->firm($principal, 'POST', '/clients', ['name' => 'X', 'phone_number' => '0712345678', 'address' => 'a', 'advocate' => $principal->id])
            ->assertForbidden()->assertJsonPath('code', 'support_read_only');
        $this->assertSame(0, $this->inTenant('acme', fn () => Client::count()));
    }

    public function test_a_full_session_needs_the_firms_consent_and_still_cannot_touch_access_control_or_exports(): void
    {
        // Standing approval, so no other user is acted-as earlier in this test (the audit user resolver caches the first guard user).
        $this->policy(['allow_write' => true, 'require_approval' => false]);
        [, , $principal] = $this->openSession(['mode' => 'full']);
        // Creating a client sends a welcome SMS. Under the sync test queue that job re-enters the tenant and leaves the process
        // fail-closed for the rest of the request (a test-driver artefact; real queues run it in a worker).
        Queue::fake();
        $this->assertTrue($principal->hasRole('support-full'));

        $this->firm($principal, 'POST', '/clients', ['name' => 'Support Made', 'phone_number' => '0712345678', 'address' => 'Nairobi', 'advocate' => $principal->id])->assertCreated();
        $this->firm($principal, 'PUT', '/support-access/policy', ['enabled' => true])->assertForbidden();
        $this->firm($principal, 'GET', '/support-access')->assertForbidden();
        $this->firm($principal, 'GET', '/export/firm-data')->assertForbidden();
        $this->firm($principal, 'POST', '/roles', ['name' => 'sneaky'])->assertForbidden();
        $this->assertFalse($principal->checkPermissionTo('access-all-matters'));
        $this->assertFalse($principal->checkPermissionTo('manage-license'));

        // and the firm's audit log attributes the change to the support principal, not to a real user
        $created = $this->inTenant('acme', fn () => DB::table('audits')->where('auditable_type', Client::class)->where('event', 'created')->first());
        $this->assertSame($principal->id, $created->user_id);
    }

    // ---- ending ------------------------------------------------------------------------------------------------

    public function test_the_session_expires_on_time_and_the_principal_stops_existing(): void
    {
        [$id, , $principal] = $this->openSession(['minutes' => 10]);
        $this->firm($principal, 'GET', '/cases')->assertOk();

        $this->travel(11)->minutes();
        $this->firm($principal, 'GET', '/cases')->assertUnauthorized()->assertJsonPath('code', 'support_session_ended');
        $this->travelBack();

        $s = $this->inTenant('acme', fn () => SupportAccessSession::find($id));
        $this->assertSame(['expired', 'expiry'], [$s->status, $s->ended_by]);
        $this->assertNotNull($this->inTenant('acme', fn () => User::withTrashed()->find($principal->id)->deleted_at));
    }

    public function test_the_sweeper_ends_expired_sessions_without_any_request(): void
    {
        [$id, , $principal] = $this->openSession(['minutes' => 10]);
        $this->travel(11)->minutes();

        $this->artisan('support:expire')->expectsOutputToContain('Ended 1')->assertExitCode(0);
        $this->travelBack();

        $this->assertSame('expired', $this->inTenant('acme', fn () => SupportAccessSession::find($id)->status));
        $this->assertNull($this->inTenant('acme', fn () => User::find($principal->id)), 'the ephemeral user is gone from normal queries');
    }

    public function test_the_firm_can_revoke_instantly_and_staff_can_end_their_own_session(): void
    {
        [$id, , $principal] = $this->openSession();
        $this->firm($this->firmAdmin(), 'POST', "/support-access/{$id}/revoke")->assertOk();
        $this->firm($principal, 'GET', '/cases')->assertUnauthorized();
        $this->assertSame(['revoked', 'firm'], $this->inTenant('acme', fn () => [SupportAccessSession::find($id)->status, SupportAccessSession::find($id)->ended_by]));
        $this->firm($this->firmAdmin(), 'POST', "/support-access/{$id}/revoke")->assertStatus(422);

        [$id2, , $p2] = $this->openSession();
        $this->platform('POST', "/tenants/acme/support-sessions/{$id2}/end")->assertOk();
        $this->firm($p2, 'GET', '/cases')->assertUnauthorized();
        $this->assertSame('staff', $this->inTenant('acme', fn () => SupportAccessSession::find($id2)->ended_by));
    }

    public function test_staff_cannot_end_someone_elses_session_unless_admin(): void
    {
        [$id] = $this->openSession();
        $other = PlatformStaff::create(['name' => 'Other', 'email' => 'other@platform.test', 'password' => Hash::make('correct-horse-battery'), 'role' => 'support']);
        $tok = $this->postJson(self::PLATFORM.'/api/platform/login', ['email' => 'other@platform.test', 'password' => 'correct-horse-battery'])->json('token');
        $this->withToken($tok)->postJson(self::PLATFORM."/api/platform/tenants/acme/support-sessions/{$id}/end")->assertStatus(422);
        $this->platform('POST', "/tenants/acme/support-sessions/{$id}/end", [], 'admin')->assertOk();
    }

    // ---- a real user is unaffected, and the catalog reaches existing firms -------------------------------------

    public function test_ordinary_users_pass_through_the_guard_untouched_and_are_never_logged_as_support(): void
    {
        $this->firm($this->firmAdmin(), 'GET', '/cases')->assertOk();
        $this->assertSame(0, $this->inTenant('acme', fn () => DB::table('support_access_log')->count()));
    }

    public function test_the_tenant_migrator_syncs_new_permissions_into_an_existing_firms_admin_role(): void
    {
        $this->inTenant('acme', fn () => Role::findByName('admin')->revokePermissionTo('manage-support-access'));
        $this->assertFalse($this->inTenant('acme', fn () => User::where('email', 'admin@acme.test')->first()->checkPermissionTo('manage-support-access')));

        $this->artisan('tenants:migrate')->assertExitCode(0);

        $this->assertTrue($this->inTenant('acme', fn () => User::where('email', 'admin@acme.test')->first()->checkPermissionTo('manage-support-access')));
    }
}
