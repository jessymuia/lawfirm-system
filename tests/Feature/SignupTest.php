<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Entitlements\EntitlementSet;
use App\Entitlements\TenantPlanSource;
use App\Jobs\ProvisionSignup;
use App\Mail\SignupVerification;
use App\Models\User;
use App\Platform\PlatformStaff;
use App\Tenancy\IdentityService;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable as C;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** PLAT-13: self-service signup -> verified email -> provisioned firm on a trial -> conversion or lapse. */
class SignupTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private const HOST = 'http://join.platform.test';

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/plat13-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.mode' => 'multi', 'tenancy.tenant_connection_template' => 'sqlite', 'tenancy.storage_disks' => ['local'],
            'tenancy.signup_host' => 'join.platform.test', 'tenancy.base_domain' => 'app.test',
            'tenancy.platform_host' => 'admin.platform.test', 'tenancy.platform_mfa' => 'optional',
            'billing.trial.days' => 14, 'billing.trial.currency' => 'KES', 'billing.trial.offboard_after_days' => 60, 'billing.tax_rate_bp' => 0,
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
        DB::connection('control')->table('cells')->insert(['name' => 'default', 'sqlite_directory' => $this->dir, 'created_at' => now(), 'updated_at' => now()]);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function form(array $over = []): array
    {
        return $over + [
            'firm_name' => 'Wanjiru & Associates', 'slug' => 'wanjiru', 'name' => 'Grace Wanjiru', 'email' => 'grace@wanjiru.co.ke',
            'password' => 'long-enough-pass-1', 'password_confirmation' => 'long-enough-pass-1', 'accept_terms' => true,
        ];
    }

    /** Submit the form and return the token from the emailed link. */
    private function submit(array $over = []): string
    {
        $this->postJson(self::HOST.'/api/signup', $this->form($over))->assertStatus(202);
        $link = null;
        Mail::assertSent(SignupVerification::class, function ($m) use (&$link) {
            $link = $m->link;

            return true;
        });
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);

        return $q['token'];
    }

    private function ctl()
    {
        return DB::connection('control');
    }

    // ---- the journey ------------------------------------------------------------------------------------------------

    public function test_signup_verify_and_the_firm_is_ready_on_a_trial(): void
    {
        $this->get(self::HOST.'/signup')->assertOk()->assertSee('Start your free trial')
            ->assertHeader('Content-Security-Policy');

        $token = $this->submit();
        // nothing exists until the link is opened; only hashes are kept
        $this->assertSame(0, Tenant::count());
        $row = $this->ctl()->table('signups')->first();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertTrue(Hash::check('long-enough-pass-1', $row->password));
        $this->assertStringNotContainsString('long-enough-pass-1', json_encode($row));
        Mail::assertSent(SignupVerification::class, fn ($m) => $m->hasTo('grace@wanjiru.co.ke') && str_starts_with($m->link, 'https://join.platform.test/signup/verify?token='));

        $this->postJson(self::HOST.'/api/signup/verify', ['token' => $token])->assertOk();   // the job runs inline (sync queue)
        $this->getJson(self::HOST.'/api/signup/status?token='.$token)->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.login_url', 'https://wanjiru.app.test/login');

        $tenant = Tenant::where('slug', 'wanjiru')->firstOrFail();
        $this->assertSame('active', $tenant->status);
        $this->assertSame('trial', $tenant->plan);
        $this->assertSame('Wanjiru & Associates', $tenant->name);
        $this->assertTrue($tenant->domains()->where('domain', 'wanjiru.app.test')->exists());
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $tenant->trial_ends_at->timestamp, 5);
        $sub = app(Billing::class)->subscription($tenant);
        $this->assertSame(['trialing', 'trial', 'grace@wanjiru.co.ke'], [$sub->status, $sub->plan, $sub->billing_email]);

        // the person signs in with the password they chose, as the firm's administrator
        app(TenantManager::class)->runIn($tenant, function () use ($tenant) {
            $this->assertNotNull(app(IdentityService::class)->attempt('grace@wanjiru.co.ke', 'long-enough-pass-1', $tenant));
            $u = User::where('email', 'grace@wanjiru.co.ke')->firstOrFail();
            $this->assertSame('Grace Wanjiru', $u->name);
            $this->assertTrue($u->hasRole('admin'));
        });
        // the password hash is not kept once the firm exists; opening the link again changes nothing
        $this->assertNull($this->ctl()->table('signups')->value('password'));
        $this->postJson(self::HOST.'/api/signup/verify', ['token' => $token])->assertOk()->assertJsonPath('data.status', 'ready');
        $this->assertSame(1, Tenant::count());
    }

    public function test_signup_exists_only_on_its_own_host_and_in_multi_mode(): void
    {
        $this->get('http://wanjiru.app.test/signup')->assertNotFound();
        $this->postJson('http://admin.platform.test/api/signup', $this->form())->assertNotFound();

        config(['tenancy.base_domain' => null]);   // nowhere to give a firm its address: off
        $this->get(self::HOST.'/signup')->assertNotFound();
        config(['tenancy.base_domain' => 'app.test', 'tenancy.mode' => 'single']);
        $this->postJson(self::HOST.'/api/signup', $this->form())->assertNotFound();
        $this->assertSame(0, $this->ctl()->table('signups')->count());
    }

    // ---- what is refused --------------------------------------------------------------------------------------------

    public function test_the_address_must_be_valid_free_and_not_reserved(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);   // many submissions; the limit has its own test
        Tenant::create(['slug' => 'taken', 'name' => 'Taken', 'status' => 'active', 'database' => "{$this->dir}/taken.sqlite"]);

        foreach (['taken' => 'taken', 'admin' => 'reserved', 'a' => 'Use 3 to 32', 'Bad_Slug' => 'Use 3 to 32', 'x--y' => 'Use 3 to 32', '-abc' => 'Use 3 to 32'] as $slug => $why) {
            $this->postJson(self::HOST.'/api/signup', $this->form(['slug' => $slug]))->assertUnprocessable()
                ->assertJsonPath('errors.slug.0', fn ($m) => str_contains($m, $why));
        }
        $this->getJson(self::HOST.'/api/signup/slug?slug=taken')->assertOk()->assertJsonPath('data.available', false);
        $this->getJson(self::HOST.'/api/signup/slug?slug=free-firm')->assertOk()->assertJsonPath('data.available', true);
        Mail::assertNothingSent();
    }

    public function test_an_unverified_signup_holds_its_address_from_other_people_but_not_from_its_owner(): void
    {
        $this->submit();
        $this->postJson(self::HOST.'/api/signup', $this->form(['email' => 'someone@else.test']))->assertUnprocessable()->assertJsonPath('errors.slug.0', fn ($m) => str_contains($m, 'taken'));

        // the same person may submit again; it replaces their earlier request
        $this->postJson(self::HOST.'/api/signup', $this->form(['firm_name' => 'Wanjiru Advocates']))->assertStatus(202);
        $this->assertSame(1, $this->ctl()->table('signups')->count());
        $this->assertSame('Wanjiru Advocates', $this->ctl()->table('signups')->value('firm_name'));

        // once expired, the address is free again
        $this->ctl()->table('signups')->update(['expires_at' => now()->subMinute()]);
        $this->postJson(self::HOST.'/api/signup', $this->form(['email' => 'someone@else.test']))->assertStatus(202);
    }

    public function test_form_rules_terms_password_and_the_bot_trap(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->postJson(self::HOST.'/api/signup', $this->form(['accept_terms' => false]))->assertUnprocessable()->assertJsonValidationErrors('accept_terms');
        $this->postJson(self::HOST.'/api/signup', $this->form(['password' => 'short1', 'password_confirmation' => 'short1']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson(self::HOST.'/api/signup', $this->form(['password' => 'onlyletterslong', 'password_confirmation' => 'onlyletterslong']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson(self::HOST.'/api/signup', $this->form(['password_confirmation' => 'different-pass-2']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson(self::HOST.'/api/signup', $this->form(['email' => 'not-an-email']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson(self::HOST.'/api/signup', $this->form(['website' => 'http://spam.example']))->assertUnprocessable();
        $this->assertSame(0, $this->ctl()->table('signups')->count());
        Mail::assertNothingSent();
    }

    public function test_submissions_are_rate_limited_per_address(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::HOST.'/api/signup', $this->form(['accept_terms' => false]))->assertUnprocessable();
        }
        $this->postJson(self::HOST.'/api/signup', $this->form())->assertStatus(429);
        Mail::assertNothingSent();
    }

    public function test_links_expire_and_unknown_tokens_say_nothing(): void
    {
        $token = $this->submit();
        $this->postJson(self::HOST.'/api/signup/verify', ['token' => 'nope'])->assertNotFound();
        $this->getJson(self::HOST.'/api/signup/status?token=nope')->assertNotFound();

        $this->ctl()->table('signups')->update(['expires_at' => now()->subMinute()]);
        $this->postJson(self::HOST.'/api/signup/verify', ['token' => $token])->assertStatus(410);
        $this->assertSame(0, Tenant::count());
    }

    public function test_an_address_taken_before_verification_is_reported_not_overwritten(): void
    {
        $token = $this->submit();
        Tenant::create(['slug' => 'wanjiru', 'name' => 'Someone Else', 'status' => 'active', 'database' => "{$this->dir}/other.sqlite"]);

        $this->postJson(self::HOST.'/api/signup/verify', ['token' => $token])->assertStatus(409);
        $this->assertSame('Someone Else', Tenant::where('slug', 'wanjiru')->value('name'));
        $this->getJson(self::HOST.'/api/signup/status?token='.$token)->assertJsonPath('data.status', 'failed');
    }

    public function test_a_failed_provisioning_is_retried_by_opening_the_link_again(): void
    {
        Queue::fake();
        $token = $this->submit();
        $this->postJson(self::HOST.'/api/signup/verify', ['token' => $token])->assertOk()->assertJsonPath('data.status', 'provisioning');
        Queue::assertPushed(ProvisionSignup::class);

        $this->ctl()->table('signups')->update(['status' => 'failed', 'error' => 'disk full']);
        $this->getJson(self::HOST.'/api/signup/status?token='.$token)->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', fn ($e) => ! str_contains($e, 'disk full'));   // internals stay in the log
        $this->postJson(self::HOST.'/api/signup/verify', ['token' => $token])->assertOk()->assertJsonPath('data.status', 'provisioning');
        Queue::assertPushed(ProvisionSignup::class, 2);

        (new ProvisionSignup((int) $this->ctl()->table('signups')->value('id')))->handle(app(TenantLifecycle::class), app(Billing::class));
        $this->getJson(self::HOST.'/api/signup/status?token='.$token)->assertJsonPath('data.status', 'ready');
    }

    public function test_someone_who_already_has_a_login_keeps_their_password(): void
    {
        $token = $this->submit();
        $this->postJson(self::HOST.'/api/signup/verify', ['token' => $token])->assertOk();
        $first = Tenant::where('slug', 'wanjiru')->firstOrFail();

        $token = $this->submit(['slug' => 'wanjiru-two', 'firm_name' => 'Second Firm', 'password' => 'another-pass-22', 'password_confirmation' => 'another-pass-22']);
        $this->postJson(self::HOST.'/api/signup/verify', ['token' => $token])->assertOk();
        $second = Tenant::where('slug', 'wanjiru-two')->firstOrFail();

        $ids = app(IdentityService::class);
        $tenants = app(TenantManager::class);
        $this->assertNotNull($tenants->runIn($second, fn () => $ids->attempt('grace@wanjiru.co.ke', 'long-enough-pass-1', $second)), 'one person, one login: the existing password');
        $this->assertNull($tenants->runIn($first, fn () => $ids->attempt('grace@wanjiru.co.ke', 'another-pass-22', $first)));
    }

    // ---- the trial, its end, and conversion --------------------------------------------------------------------------

    private function trialFirm(): Tenant
    {
        $token = $this->submit();
        $this->postJson(self::HOST.'/api/signup/verify', ['token' => $token])->assertOk();

        return Tenant::where('slug', 'wanjiru')->firstOrFail();
    }

    public function test_the_trial_warns_then_turns_read_only(): void
    {
        $set = new EntitlementSet('trial', ['payments' => true], ['users' => 3]);
        $ends = C::parse('2026-10-20 12:00:00');

        $this->assertNull(TenantPlanSource::withTrial($set, $ends, C::parse('2026-10-10 12:00:00'))->notice);
        $soon = TenantPlanSource::withTrial($set, $ends, C::parse('2026-10-18 12:00:00'));
        $this->assertFalse($soon->readOnly);
        $this->assertStringContainsString('2 days left', $soon->notice);
        $ended = TenantPlanSource::withTrial($set, $ends, C::parse('2026-10-20 12:00:00'));
        $this->assertTrue($ended->readOnly);
        $this->assertSame('trial_expired', $ended->code);
        $this->assertTrue($ended->has('payments'), 'features stay as they were: only writes stop');
        $this->assertSame($set, TenantPlanSource::withTrial($set, null));
    }

    public function test_an_unconverted_trial_lapses_instead_of_rolling_into_another_free_month(): void
    {
        $tenant = $this->trialFirm();
        $billing = app(Billing::class);

        $this->assertSame(0, $billing->runDue(C::now()->addDays(15)));
        $sub = $billing->subscription($tenant);
        $this->assertSame('expired', $sub->status);
        $this->assertNotNull($sub->expired_at);
        $this->assertSame(0, $this->ctl()->table('billing_invoices')->count());
        $this->assertSame(0, $billing->runDue(C::now()->addDays(60)), 'a lapsed trial is not rolled again');

        // long after it lapsed, the firm is offboarded (retention -> final export -> purge, TEN-9)
        $this->assertSame([], $billing->offboardLapsedTrials(C::now()->addDays(30)));
        $this->assertSame(['wanjiru'], $billing->offboardLapsedTrials(C::now()->addDays(80)));
        $this->assertSame('offboarded', $tenant->fresh()->status);
    }

    public function test_choosing_a_plan_mid_trial_converts_and_bills_when_the_trial_ends(): void
    {
        $tenant = $this->trialFirm();
        $billing = app(Billing::class);

        $billing->changePlan($tenant, 'starter', C::now()->addDays(3));
        $sub = $billing->subscription($tenant);
        $this->assertSame(['starter', 'trialing'], [$sub->plan, $sub->status]);
        $this->assertNotNull($sub->converted_at);
        $this->assertNull($tenant->fresh()->trial_ends_at, 'no longer a trial: never read-only for it');
        $this->assertSame(0, $this->ctl()->table('billing_invoices')->count(), 'nothing is charged during the trial');

        $this->assertSame(1, $billing->runDue(C::now()->addDays(15)));
        $this->assertSame('active', $billing->subscription($tenant)->status);
        $this->assertSame(500000, (int) $this->ctl()->table('billing_invoices')->value('total'));
    }

    public function test_choosing_a_plan_after_the_trial_lapsed_starts_and_bills_now(): void
    {
        $tenant = $this->trialFirm();
        $billing = app(Billing::class);
        $billing->runDue(C::now()->addDays(15));

        $at = C::now()->addDays(20);
        $r = $billing->changePlan($tenant, 'professional', $at);
        $sub = $billing->subscription($tenant);
        $this->assertSame(['professional', 'active'], [$sub->plan, $sub->status]);
        $this->assertNotNull($r['invoice']);
        $this->assertSame($at->toDateTimeString(), C::parse($sub->period_start)->toDateTimeString());
        $this->assertNull($tenant->fresh()->trial_ends_at);
        $this->assertSame([], $billing->offboardLapsedTrials(C::now()->addDays(200)), 'a converted firm is never offboarded as a lapsed trial');
    }

    // ---- the platform's view -----------------------------------------------------------------------------------------

    public function test_the_platform_sees_the_funnel(): void
    {
        $this->trialFirm();                                    // ready, converts
        app(Billing::class)->changePlan(Tenant::where('slug', 'wanjiru')->first(), 'starter', C::now()->addDays(2));
        $token = $this->submit(['slug' => 'lapses', 'email' => 'b@b.test']);
        $this->postJson(self::HOST.'/api/signup/verify', ['token' => $token])->assertOk();
        app(Billing::class)->runDue(C::now()->addDays(15));   // 'lapses' expires
        $this->submit(['slug' => 'never', 'email' => 'c@c.test']);   // never verified

        PlatformStaff::create(['name' => 'Ops', 'email' => 'ops@platform.test', 'password' => Hash::make('correct-horse-battery'), 'role' => 'viewer']);
        $token = $this->postJson('http://admin.platform.test/api/platform/login', ['email' => 'ops@platform.test', 'password' => 'correct-horse-battery'])->assertOk()->json('token');
        $this->getJson('http://admin.platform.test/api/platform/billing/funnel?days=30', ['Authorization' => 'Bearer '.$token])->assertOk()
            ->assertJsonPath('data.signups.submitted', 3)
            ->assertJsonPath('data.signups.verified', 2)
            ->assertJsonPath('data.signups.ready', 2)
            ->assertJsonPath('data.trials.started', 2)
            ->assertJsonPath('data.trials.converted', 1)
            ->assertJsonPath('data.trials.lapsed', 1)
            ->assertJsonPath('data.trials.conversion_rate', 0.5);
    }
}
