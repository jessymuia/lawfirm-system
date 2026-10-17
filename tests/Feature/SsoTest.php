<?php

namespace Tests\Feature;

use App\Entitlements\Entitlements;
use App\Models\User;
use App\Sso\Jwt;
use App\Sso\SsoException;
use App\Sso\SsrfGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** SEC-12: per-firm OpenID Connect single sign-on, against a fake identity provider with a real RSA key. */
class SsoTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const ISSUER = 'https://idp.example.test';

    private const CLIENT = 'firm-client-id';

    private $key;

    private array $jwk;

    /** @var array<string,mixed> claim overrides the fake token endpoint applies to the next ID token */
    private array $claims = [];

    private $signWith = null;

    private ?array $header = null;

    private array $tokenRequests = [];

    private string $nonce = 'none';

    private ?array $meta = null;

    private bool $idpFaked = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sso.block_private_hosts' => false]);
        Cache::flush();
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $d = openssl_pkey_get_details($this->key);
        $this->jwk = ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'k1', 'n' => Jwt::b64e($d['rsa']['n']), 'e' => Jwt::b64e($d['rsa']['e'])];
        $this->fakeIdp();
    }

    // ---- fake identity provider ----------------------------------------------------------------------------------

    private function jwt(array $claims, $privateKey = null, ?array $header = null): string
    {
        $h = Jwt::b64e(json_encode($header ?? ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'k1']));
        $p = Jwt::b64e(json_encode($claims));
        openssl_sign("{$h}.{$p}", $sig, $privateKey ?? $this->key, OPENSSL_ALGO_SHA256);

        return "{$h}.{$p}.".Jwt::b64e($sig);
    }

    /** Replace the discovery document the fake provider serves (Http::fake stubs cannot be re-registered: the first match wins). */
    private function fakeIdp(?array $meta = null): void
    {
        $this->meta = $meta;
        Cache::flush();
        if ($this->idpFaked) {
            return;
        }
        $this->idpFaked = true;
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => fn () => Http::response($this->meta ?? [
                'issuer' => self::ISSUER, 'authorization_endpoint' => self::ISSUER.'/authorize', 'token_endpoint' => self::ISSUER.'/token', 'jwks_uri' => self::ISSUER.'/jwks',
            ]),
            self::ISSUER.'/jwks' => fn () => Http::response(['keys' => [$this->jwk]]),
            self::ISSUER.'/token' => function (HttpRequest $req) {
                $this->tokenRequests[] = $req->data();
                $nonce = $this->nonce;
                $now = now()->timestamp;
                $claims = array_merge(['iss' => self::ISSUER, 'aud' => self::CLIENT, 'sub' => 'idp-user-1', 'email' => 'partner@firm.test', 'email_verified' => true,
                    'nonce' => $nonce, 'iat' => $now, 'exp' => $now + 300], $this->claims);
                $claims = array_filter($claims, fn ($v) => $v !== '__unset__');

                return Http::response(['id_token' => $this->jwt($claims, $this->signWith ? $this->signWith : null, $this->header), 'access_token' => 'x', 'token_type' => 'Bearer']);
            },
        ]);
    }

    // ---- helpers -------------------------------------------------------------------------------------------------

    private function admin(): User
    {
        $u = $this->userWithPermissions(['manage-security-settings']);
        $u->update(['password' => Hash::make('correct-horse-battery-1')]);

        return $u->fresh();
    }

    private function configure(array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin())->putJson('/api/v1/security/sso', $over + [
            'issuer' => self::ISSUER, 'client_id' => self::CLIENT, 'client_secret' => 'super-secret', 'enabled' => true,
        ]);
    }

    private function partner(string $email = 'partner@firm.test'): User
    {
        $u = $this->userWithPermissions(['list-clients']);
        $u->update(['email' => $email, 'password' => Hash::make('correct-horse-battery-1')]);

        return $u->fresh();
    }

    private function signedOut(): void
    {
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');
        $this->flushSession();
    }

    /** Begin a sign-in; returns the state and the parsed authorize URL. @return array{0:string,1:array} */
    private function begin(): array
    {
        $this->signedOut();
        $loc = $this->get('/api/v1/sso/start')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/authorize?', $loc);
        parse_str(parse_url($loc, PHP_URL_QUERY), $q);

        $this->nonce = $q['nonce'];

        return [$q['state'], $q];
    }

    private function hitCallback(string $state, string $code = 'abc')
    {
        return $this->get('/api/v1/sso/callback?'.http_build_query(['code' => $code, 'state' => $state]));
    }

    private function ssoLogin(array $claims = [], string $email = 'partner@firm.test'): \Illuminate\Testing\TestResponse
    {
        $this->partner($email);
        $this->claims = $claims;
        [$state] = $this->begin();

        return $this->hitCallback($state);
    }

    private function assertDenied(\Illuminate\Testing\TestResponse $r): void
    {
        $r->assertRedirect(config('sso.failure_redirect'));
        $this->getJson('/api/v1/user')->assertUnauthorized();
        $this->assertGreaterThan(0, DB::table('audits')->where('event', 'sso_login_denied')->count());
    }

    // ---- configuration -------------------------------------------------------------------------------------------

    public function test_the_firm_configures_its_provider_and_the_secret_is_never_shown_again(): void
    {
        $r = $this->configure()->assertOk()->assertJsonPath('data.issuer', self::ISSUER)->assertJsonPath('data.has_secret', true);
        $this->assertStringNotContainsString('super-secret', $r->getContent());
        $row = DB::table('sso_providers')->first();
        $this->assertStringNotContainsString('super-secret', $row->client_secret);
        $this->assertSame('super-secret', \Illuminate\Support\Facades\Crypt::decryptString($row->client_secret));

        $this->actingAs($this->admin())->getJson('/api/v1/security/sso')->assertOk()->assertJsonMissingPath('data.client_secret');

        // changing other fields keeps the stored secret when none is sent
        $this->configure(['client_secret' => null, 'enforce' => false, 'allowed_email_domains' => ['Firm.test']])->assertOk()->assertJsonPath('data.allowed_email_domains.0', 'firm.test');
        $this->assertSame('super-secret', \Illuminate\Support\Facades\Crypt::decryptString(DB::table('sso_providers')->value('client_secret')));
        $this->assertSame(1, DB::table('audits')->where('event', 'sso_configured')->where('new_values', 'like', '%idp.example.test%')->count() ? 1 : 0);
    }

    public function test_a_provider_that_does_not_check_out_is_refused_before_it_can_lock_anyone_out(): void
    {
        $this->fakeIdp(['issuer' => 'https://evil.example', 'authorization_endpoint' => self::ISSUER.'/authorize', 'token_endpoint' => self::ISSUER.'/token', 'jwks_uri' => self::ISSUER.'/jwks']);
        $this->configure()->assertStatus(422);
        $this->assertSame(0, DB::table('sso_providers')->count());

        $this->configure(['issuer' => 'http://idp.example.test'])->assertStatus(422);
        $this->configure(['enforce' => true, 'enabled' => false])->assertStatus(422);
        $this->actingAs($this->admin())->putJson('/api/v1/security/sso', ['issuer' => self::ISSUER, 'client_id' => 'x'])->assertStatus(422);   // no secret yet
    }

    public function test_configuring_needs_the_permission_and_the_plan(): void
    {
        $nobody = $this->userWithPermissions(['list-clients']);
        $this->configure([], $nobody)->assertForbidden();

        $this->usePlan('starter');
        $this->configure()->assertForbidden()->assertJsonPath('code', 'entitlement_required');
    }

    // ---- the sign-in ---------------------------------------------------------------------------------------------

    public function test_a_full_sign_in_with_pkce_state_and_nonce(): void
    {
        $this->configure()->assertOk();
        $partner = $this->partner();
        [$state, $q] = $this->begin();

        $this->assertSame('code', $q['response_type']);
        $this->assertSame(self::CLIENT, $q['client_id']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(rtrim(config('app.url'), '/').'/api/v1/sso/callback', $q['redirect_uri']);
        $this->assertStringContainsString('openid', $q['scope']);
        $this->assertSame(48, strlen($state));
        $this->assertNotEmpty($q['nonce']);

        // the browser lands on the login page, which finishes the sign-in from /session (SEC-12 web login)
        $this->assertSame('/login?sso=complete', config('sso.success_redirect'));
        $this->hitCallback($state)->assertRedirect(config('sso.success_redirect'));
        $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('id', $partner->id);
        $this->getJson('/api/v1/session')->assertOk()->assertJsonPath('data.0.id', $partner->id)->assertJsonPath('data.4', false);

        // what the token endpoint was sent: the verifier whose hash is the challenge, and the secret
        $sent = $this->tokenRequests[0];
        $this->assertSame(Jwt::b64e(hash('sha256', $sent['code_verifier'], true)), $q['code_challenge']);
        $this->assertSame('super-secret', $sent['client_secret']);
        $this->assertSame('authorization_code', $sent['grant_type']);
        $this->assertSame($q['redirect_uri'], $sent['redirect_uri']);

        $this->assertSame(1, DB::table('sso_links')->where('user_id', $partner->id)->where('subject', 'idp-user-1')->count());
        $this->assertSame(1, DB::table('audits')->where('event', 'sso_login')->where('user_id', $partner->id)->count());
        $this->assertSame(0, DB::table('sso_states')->count(), 'the state is consumed');
    }

    public function test_the_state_is_single_use_unknown_states_fail_and_old_ones_expire(): void
    {
        $this->configure()->assertOk();
        $this->partner();
        [$state] = $this->begin();
        $this->hitCallback($state)->assertRedirect(config('sso.success_redirect'));

        $this->signedOut();
        $this->assertDenied($this->hitCallback($state));                       // replay
        $this->assertDenied($this->hitCallback('not-a-real-state'));
        $this->assertDenied($this->get('/api/v1/sso/callback'));            // no parameters
        $this->assertDenied($this->get('/api/v1/sso/callback?error=access_denied&state=x'));

        [$state] = $this->begin();
        $this->travel(11)->minutes();
        $this->assertDenied($this->hitCallback($state));
        $this->travelBack();
    }

    /** @dataProvider badTokens */
    public function test_every_defect_in_the_id_token_is_refused(string $label, array $claims, ?string $alg = null): void
    {
        $this->configure()->assertOk();
        $this->partner();
        if ($label === 'wrong signing key') {
            $this->signWith = openssl_pkey_new(['private_key_bits' => 2048]);
        }
        if ($label === 'alg none') {
            $this->header = ['alg' => 'none', 'kid' => 'k1'];
        }
        if ($label === 'hs256 with the public key as secret') {
            $this->header = ['alg' => 'HS256', 'kid' => 'k1'];
        }
        if ($label === 'unknown kid') {
            $this->header = ['alg' => 'RS256', 'kid' => 'other'];
        }
        $this->claims = $claims;
        [$state] = $this->begin();
        $this->assertDenied($this->hitCallback($state));
    }

    public static function badTokens(): array
    {
        $past = now()->timestamp - 3600;

        return [
            'wrong signing key' => ['wrong signing key', []],
            'alg none' => ['alg none', []],
            'hs256 with the public key as secret' => ['hs256 with the public key as secret', []],
            'unknown kid' => ['unknown kid', []],
            'wrong issuer' => ['wrong issuer', ['iss' => 'https://evil.example']],
            'wrong audience' => ['wrong audience', ['aud' => 'someone-else']],
            'two audiences without azp' => ['two audiences without azp', ['aud' => [self::CLIENT, 'other']]],
            'expired' => ['expired', ['exp' => $past, 'iat' => $past - 300]],
            'not yet valid' => ['not yet valid', ['nbf' => now()->timestamp + 3600]],
            'issued in the future' => ['issued in the future', ['iat' => now()->timestamp + 3600]],
            'wrong nonce' => ['wrong nonce', ['nonce' => 'replayed-from-another-login']],
            'no nonce' => ['no nonce', ['nonce' => '__unset__']],
            'no subject' => ['no subject', ['sub' => '__unset__']],
            'empty subject' => ['empty subject', ['sub' => '']],
            'no email' => ['no email', ['email' => '__unset__']],
            'unverified email' => ['unverified email', ['email_verified' => false]],
            'email verified as a string no' => ['email verified as a string no', ['email_verified' => 'false']],
        ];
    }

    public function test_a_rotated_signing_key_is_picked_up(): void
    {
        $this->configure()->assertOk();
        $this->partner();
        // the provider's keys are cached; then it rotates to a new key
        [$state] = $this->begin();
        $this->hitCallback($state)->assertRedirect(config('sso.success_redirect'));
        $this->signedOut();

        $new = openssl_pkey_new(['private_key_bits' => 2048]);
        $d = openssl_pkey_get_details($new);
        $this->jwk = ['kty' => 'RSA', 'use' => 'sig', 'kid' => 'k2', 'n' => Jwt::b64e($d['rsa']['n']), 'e' => Jwt::b64e($d['rsa']['e'])];
        $this->signWith = $new;
        $this->header = ['alg' => 'RS256', 'kid' => 'k2'];
        [$state] = $this->begin();
        $this->hitCallback($state)->assertRedirect(config('sso.success_redirect'));
        $this->getJson('/api/v1/user')->assertOk();
    }

    // ---- who may come in -----------------------------------------------------------------------------------------

    public function test_nobody_is_created_by_an_assertion(): void
    {
        $this->configure()->assertOk();
        $before = User::count();
        $this->claims = ['email' => 'stranger@firm.test'];
        [$state] = $this->begin();
        $this->assertDenied($this->hitCallback($state));
        $this->assertSame($before, User::count());
    }

    public function test_deleted_users_and_platform_principals_cannot_come_in(): void
    {
        $this->configure()->assertOk();
        $gone = $this->partner('gone@firm.test');
        $gone->delete();
        $this->claims = ['email' => 'gone@firm.test'];
        [$state] = $this->begin();
        $this->assertDenied($this->hitCallback($state));

        $p = User::factory()->create(['email' => 'support-9-x@support.invalid']);
        DB::table('users')->where('id', $p->id)->update(['platform_staff_id' => 3]);
        $this->claims = ['email' => 'support-9-x@support.invalid'];
        [$state] = $this->begin();
        $this->assertDenied($this->hitCallback($state));
    }

    public function test_the_idp_subject_is_bound_to_the_user_on_first_login(): void
    {
        $this->configure()->assertOk();
        $this->partner();
        $this->claims = ['sub' => 'subject-A'];
        [$state] = $this->begin();
        $this->hitCallback($state)->assertRedirect(config('sso.success_redirect'));

        // the same email now arrives with a different subject (address reassigned at the IdP): refused
        $this->claims = ['sub' => 'subject-B'];
        [$state] = $this->begin();
        $this->assertDenied($this->hitCallback($state));

        // and a subject cannot be shared by a second user
        $other = $this->partner('other@firm.test');
        $this->claims = ['sub' => 'subject-A', 'email' => 'other@firm.test'];
        [$state] = $this->begin();
        $this->assertDenied($this->hitCallback($state));
        $this->assertSame(0, DB::table('sso_links')->where('user_id', $other->id)->count());
    }

    public function test_the_email_domain_allow_list(): void
    {
        $this->configure(['allowed_email_domains' => ['elsewhere.test']])->assertOk();
        $this->partner();
        [$state] = $this->begin();
        $this->assertDenied($this->hitCallback($state));

        $this->configure(['allowed_email_domains' => ['FIRM.test']])->assertOk();
        [$state] = $this->begin();
        $this->hitCallback($state)->assertRedirect(config('sso.success_redirect'));
    }

    // ---- enforcement, MFA, plan ----------------------------------------------------------------------------------

    public function test_when_enforced_passwords_work_only_for_security_administrators(): void
    {
        $admin = $this->admin();
        $this->configure(['enforce' => true], $admin)->assertOk();
        $partner = $this->partner();
        $this->signedOut();

        RateLimiter::clear($partner->email.'|127.0.0.1');
        $this->postJson('/api/v1/login', ['email' => $partner->email, 'password' => 'correct-horse-battery-1'])->assertForbidden()->assertJsonPath('code', 'sso_required');
        $this->getJson('/api/v1/user')->assertUnauthorized();

        RateLimiter::clear($admin->email.'|127.0.0.1');
        $this->postJson('/api/v1/login', ['email' => $admin->email, 'password' => 'correct-horse-battery-1'])->assertOk();   // break-glass

        // and SSO still works for the partner
        [$state] = $this->begin();
        $this->hitCallback($state)->assertRedirect(config('sso.success_redirect'));
        $this->getJson('/api/v1/user')->assertOk();

        $this->getJson('/api/v1/sso/config')->assertJsonPath('data.enforce', true)->assertJsonPath('data.available', true);
    }

    public function test_an_sso_login_satisfies_a_required_mfa_policy_only_when_the_idp_is_attested_to_enforce_it(): void
    {
        $this->configure(['idp_enforces_mfa' => false])->assertOk();
        DB::table('firm_security_settings')->insert(['require_mfa' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->partner();
        [$state] = $this->begin();
        $this->hitCallback($state)->assertRedirect(config('sso.success_redirect'));
        $this->getJson('/api/v1/clients')->assertForbidden()->assertJsonPath('code', 'mfa_enrollment_required');
        $this->getJson('/api/v1/session')->assertOk()->assertJsonPath('data.4', true);

        DB::table('sso_providers')->update(['idp_enforces_mfa' => true]);   // (the admin has no MFA of their own, so the API would refuse them under the policy)
        [$state] = $this->begin();
        $this->hitCallback($state)->assertRedirect(config('sso.success_redirect'));
        $this->getJson('/api/v1/clients')->assertOk();
        $this->getJson('/api/v1/session')->assertOk()->assertJsonPath('data.4', false);
    }

    public function test_a_plan_without_sso_switches_it_off_but_keeps_the_configuration(): void
    {
        $this->configure()->assertOk();
        $this->partner();
        $this->usePlan('starter');

        $this->signedOut();
        $this->get('/api/v1/sso/start')->assertNotFound();
        $this->getJson('/api/v1/sso/config')->assertJsonPath('data.available', false);
        $this->assertSame(1, DB::table('sso_providers')->count());
        RateLimiter::clear('partner@firm.test|127.0.0.1');
        $this->postJson('/api/v1/login', ['email' => 'partner@firm.test', 'password' => 'correct-horse-battery-1'])->assertOk();   // passwords work
    }

    public function test_the_public_config_reveals_no_secrets_and_start_is_404_when_unconfigured(): void
    {
        $this->getJson('/api/v1/sso/config')->assertOk()->assertJsonPath('data.available', false);
        $this->get('/api/v1/sso/start')->assertNotFound();
        $this->configure()->assertOk();
        $r = $this->getJson('/api/v1/sso/config')->assertOk()->assertJsonPath('data.available', true);
        $this->assertSame(['available', 'enforce'], array_keys($r->json('data')));
    }

    public function test_removing_sso_returns_everyone_to_passwords(): void
    {
        $admin = $this->admin();
        $this->configure(['enforce' => true], $admin)->assertOk();
        $this->actingAs($admin)->deleteJson('/api/v1/security/sso')->assertOk();
        $this->assertSame(0, DB::table('sso_providers')->count());
        $partner = $this->partner();
        $this->signedOut();
        RateLimiter::clear($partner->email.'|127.0.0.1');
        $this->postJson('/api/v1/login', ['email' => $partner->email, 'password' => 'correct-horse-battery-1'])->assertOk();
    }

    // ---- SSRF, JWT -----------------------------------------------------------------------------------------------

    public function test_the_server_will_not_be_pointed_at_its_own_network(): void
    {
        config(['sso.block_private_hosts' => true]);
        foreach (['http://idp.example.test', 'https://127.0.0.1/x', 'https://169.254.169.254/latest/meta-data', 'https://10.0.0.5', 'https://192.168.1.10', 'https://[::1]/', 'https://user:pw@8.8.8.8/', 'ftp://8.8.8.8', 'not a url'] as $bad) {
            try {
                SsrfGuard::check($bad);
                $this->fail("{$bad} must be refused");
            } catch (SsoException) {
                $this->assertTrue(true);
            }
        }
        SsrfGuard::check('https://8.8.8.8/.well-known/openid-configuration');   // a public address passes

        // a discovery document may not smuggle in private endpoints
        $this->fakeIdp(['issuer' => 'https://8.8.8.8', 'authorization_endpoint' => 'https://8.8.8.8/a', 'token_endpoint' => 'https://169.254.169.254/token', 'jwks_uri' => 'https://8.8.8.8/j']);
        Http::fake(['https://8.8.8.8/.well-known/openid-configuration' => Http::response(['issuer' => 'https://8.8.8.8', 'authorization_endpoint' => 'https://8.8.8.8/a', 'token_endpoint' => 'https://169.254.169.254/token', 'jwks_uri' => 'https://8.8.8.8/j'])]);
        $this->configure(['issuer' => 'https://8.8.8.8'])->assertStatus(422);
        $this->assertSame(0, DB::table('sso_providers')->count());
    }

    public function test_the_rsa_public_key_encoding_is_what_openssl_expects(): void
    {
        foreach ([2048, 3072] as $bits) {
            $k = openssl_pkey_new(['private_key_bits' => $bits]);
            $d = openssl_pkey_get_details($k);
            $this->assertSame(trim($d['key']), Jwt::rsaPem($d['rsa']['n'], $d['rsa']['e']));
        }
    }

    public function test_the_authorization_server_never_sees_the_secret_in_the_browser(): void
    {
        $this->configure()->assertOk();
        [, $q] = $this->begin();
        $this->assertStringNotContainsString('super-secret', http_build_query($q));
        $this->assertArrayNotHasKey('client_secret', $q);
        $this->assertArrayNotHasKey('code_verifier', $q);
    }

    public function test_sso_secrets_are_not_part_of_a_portable_bundle(): void
    {
        foreach (['sso_providers', 'sso_states', 'sso_links'] as $t) {
            $this->assertArrayHasKey($t, config('portability.exclude'));
        }
    }
}
