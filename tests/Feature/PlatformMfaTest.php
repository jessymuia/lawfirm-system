<?php

namespace Tests\Feature;

use App\Platform\PlatformAudit;
use App\Platform\PlatformAuth;
use App\Platform\PlatformStaff;
use App\Security\Totp;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** SEC-12: platform-staff second factor (control plane), and the "required" rollout mode. */
class PlatformMfaTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private const HOST = 'http://admin.platform.test';

    private const PW = 'correct-horse-battery';

    private string $dir;

    private string $originalDefault;

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/plmfa-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.mode' => 'multi', 'tenancy.platform_host' => 'admin.platform.test', 'tenancy.base_domain' => 'app.test',
            'tenancy.platform_mfa' => 'optional',
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
    }

    private function staff(string $role = 'admin'): PlatformStaff
    {
        return PlatformStaff::create(['name' => $role, 'email' => "{$role}@platform.test", 'password' => Hash::make(self::PW), 'role' => $role]);
    }

    private function api(string $path, array $data = [], ?string $token = null)
    {
        return ($token ? $this->withToken($token) : $this)->postJson(self::HOST.'/api/platform'.$path, $data);
    }

    private function login(PlatformStaff $s)
    {
        return $this->api('/login', ['email' => $s->email, 'password' => self::PW]);
    }

    /** Enrol through the API; returns [token, secret, recovery codes]. */
    private function enrol(PlatformStaff $s): array
    {
        $token = $this->login($s)->assertOk()->json('token');
        $secret = $this->api('/mfa/setup', [], $token)->assertOk()->json('secret');
        $codes = $this->api('/mfa/enable', ['code' => Totp::code($secret, Totp::currentStep())], $token)->assertOk()->json('recovery_codes');

        return [$token, $secret, $codes];
    }

    public function test_optional_mode_lets_staff_without_a_second_factor_sign_in_as_before(): void
    {
        $s = $this->staff();
        $token = $this->login($s)->assertOk()->assertJsonMissingPath('mfa_enrollment_required')->json('token');
        $this->withToken($token)->getJson(self::HOST.'/api/platform/fleet')->assertOk();
    }

    public function test_required_mode_limits_a_new_login_to_enrolling_then_lifts(): void
    {
        config(['tenancy.platform_mfa' => 'required']);
        $s = $this->staff();
        $r = $this->login($s)->assertOk()->assertJsonPath('mfa_enrollment_required', true);
        $token = $r->json('token');

        $this->withToken($token)->getJson(self::HOST.'/api/platform/fleet')->assertForbidden()->assertJsonPath('code', 'mfa_enrollment_required');
        $this->withToken($token)->getJson(self::HOST.'/api/platform/tenants')->assertForbidden();
        $this->withToken($token)->postJson(self::HOST.'/api/platform/tenants/x/suspend')->assertForbidden();
        $this->withToken($token)->getJson(self::HOST.'/api/platform/me')->assertOk();

        $secret = $this->api('/mfa/setup', [], $token)->assertOk()->json('secret');
        $this->api('/mfa/enable', ['code' => '000000'], $token)->assertStatus(422);
        $this->withToken($token)->getJson(self::HOST.'/api/platform/fleet')->assertForbidden();
        $this->api('/mfa/enable', ['code' => Totp::code($secret, Totp::currentStep())], $token)->assertOk();
        $this->withToken($token)->getJson(self::HOST.'/api/platform/fleet')->assertOk();   // the same token is now a normal one

        $this->assertSame(1, PlatformAudit::where('action', 'mfa.enabled')->count());
    }

    public function test_with_a_second_factor_the_password_alone_gets_no_token(): void
    {
        $s = $this->staff();
        [$tok, $secret] = $this->enrol($s);

        $r = $this->login($s)->assertOk()->assertJsonPath('mfa_required', true);
        $this->assertArrayNotHasKey('token', $r->json());
        $challenge = $r->json('challenge');

        $this->api('/login/mfa', ['challenge' => $challenge, 'code' => '123456'])->assertUnauthorized();
        $token = $this->api('/login/mfa', ['challenge' => $challenge, 'code' => Totp::code($secret, Totp::currentStep() + 1)])->assertOk()->json('token');
        $this->withToken($token)->getJson(self::HOST.'/api/platform/fleet')->assertOk();
        $this->api('/login/mfa', ['challenge' => $challenge, 'code' => Totp::code($secret, Totp::currentStep())])->assertUnauthorized();   // single use
    }

    public function test_a_recovery_code_works_once_for_staff(): void
    {
        $s = $this->staff();
        [, , $codes] = $this->enrol($s);
        $c = $this->login($s)->json('challenge');
        $this->api('/login/mfa', ['challenge' => $c, 'code' => $codes[0]])->assertOk()->assertJsonStructure(['token']);
        $c = $this->login($s)->json('challenge');
        $this->api('/login/mfa', ['challenge' => $c, 'code' => $codes[0]])->assertUnauthorized();
    }

    public function test_wrong_second_factor_attempts_lock_the_account_like_wrong_passwords(): void
    {
        $s = $this->staff();
        [, $secret] = $this->enrol($s);
        for ($i = 0; $i < 5; $i++) {
            app('cache')->flush();   // the route throttle is not what is under test here
            $c = $this->login($s)->json('challenge');
            $this->api('/login/mfa', ['challenge' => $c, 'code' => '00000'.$i])->assertUnauthorized();
        }
        $this->assertNotNull($s->fresh()->locked_until);
        // locked: even the password step is refused now
        $this->login($s)->assertUnauthorized();
        $this->assertGreaterThanOrEqual(5, PlatformAudit::where('action', 'login.mfa')->where('result', 'denied')->count());
    }

    public function test_it_cannot_be_switched_off_when_required_and_needs_proof_otherwise(): void
    {
        $s = $this->staff();
        [$tok, $secret] = $this->enrol($s);

        $this->api('/mfa/disable', ['password' => 'wrong-password-1', 'code' => Totp::code($secret, Totp::currentStep() + 1)], $tok)->assertStatus(422);
        config(['tenancy.platform_mfa' => 'required']);
        $this->api('/mfa/disable', ['password' => self::PW, 'code' => Totp::code($secret, Totp::currentStep() + 1)], $tok)->assertForbidden();
        config(['tenancy.platform_mfa' => 'optional']);
        $this->api('/mfa/disable', ['password' => self::PW, 'code' => Totp::code($secret, Totp::currentStep() + 1)], $tok)->assertOk();
        $this->assertFalse(app(PlatformAuth::class)->mfa()->enabled($s->id));
        $this->assertSame(1, PlatformAudit::where('action', 'mfa.disabled')->count());
    }

    public function test_the_cli_can_reset_a_lost_second_factor_and_ends_live_sessions(): void
    {
        $s = $this->staff();
        [$tok] = $this->enrol($s);
        $this->withToken($tok)->getJson(self::HOST.'/api/platform/me')->assertOk();

        $this->artisan('platform:staff', ['action' => 'mfa-reset', 'email' => $s->email])->assertSuccessful();
        $this->assertFalse(app(PlatformAuth::class)->mfa()->enabled($s->id));
        $this->withToken($tok)->getJson(self::HOST.'/api/platform/me')->assertUnauthorized();
        $this->assertSame(1, PlatformAudit::where('action', 'staff.mfa-reset')->count());
        $this->artisan('platform:staff', ['action' => 'mfa-reset', 'email' => 'ghost@platform.test'])->assertFailed();
    }

    public function test_the_secret_is_encrypted_at_rest_and_the_default_is_required(): void
    {
        $s = $this->staff();
        [, $secret] = $this->enrol($s);
        $stored = DB::connection('control')->table('platform_staff_mfa')->where('staff_id', $s->id)->value('secret');
        $this->assertStringNotContainsString($secret, $stored);

        // the shipped default is `required`; phpunit.xml relaxes it for the rest of the suite
        $this->assertStringContainsString("env('PLATFORM_MFA', 'required')", file_get_contents(base_path('config/tenancy.php')));
    }
}
