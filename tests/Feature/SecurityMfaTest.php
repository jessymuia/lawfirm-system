<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\Mfa;
use App\Security\RecoveryCodes;
use App\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** SEC-12: two-factor authentication for firm users, and the firm's "require it" policy. */
class SecurityMfaTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-1';

    private function person(array $perms = [], string $email = null): User
    {
        $u = $this->userWithPermissions($perms);
        $u->update(['password' => Hash::make(self::PASSWORD)] + ($email ? ['email' => $email] : []));

        return $u->fresh();
    }

    private function login(User $u)
    {
        RateLimiter::clear(strtolower($u->email).'|127.0.0.1');
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');   // earlier actingAs() calls leave the default guard on sanctum

        return $this->postJson('/api/v1/login', ['email' => $u->email, 'password' => self::PASSWORD]);
    }

    /** Enrol $u through the API and return [secret, recovery codes]. */
    private function enrol(User $u): array
    {
        $secret = $this->actingAs($u)->postJson('/api/v1/mfa/setup')->assertOk()->json('data.secret');
        $codes = $this->actingAs($u)->postJson('/api/v1/mfa/enable', ['code' => Totp::code($secret, Totp::currentStep())])->assertOk()->json('data.recovery_codes');
        app('auth')->forgetGuards();

        return [$secret, $codes];
    }

    private function code(string $secret, int $offsetSteps = 0): string
    {
        return Totp::code($secret, Totp::currentStep() + $offsetSteps);
    }

    // ---- the algorithm -------------------------------------------------------------------------------------------

    public function test_totp_matches_the_rfc_6238_test_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $t => $expected) {
            $this->assertSame($expected, Totp::code($secret, intdiv($t, 30), 8), "t={$t}");
        }
        $this->assertSame('287082', Totp::code($secret, 1));
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));
    }

    public function test_verify_window_and_replay(): void
    {
        $secret = Totp::generateSecret();
        $now = Totp::currentStep();
        $this->assertSame($now, Totp::verify($secret, Totp::code($secret, $now)));
        $this->assertSame($now - 1, Totp::verify($secret, Totp::code($secret, $now - 1)), 'one step of clock drift is tolerated');
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $now - 2)), 'two steps is too old');
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $now + 2)));
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $now), $now), 'a step already used is refused');
        $this->assertNull(Totp::verify($secret, '12345'));
        $this->assertNull(Totp::verify($secret, 'abcdef'));
        $this->assertSame($now, Totp::verify($secret, ' '.substr(Totp::code($secret, $now), 0, 3).' '.substr(Totp::code($secret, $now), 3)), 'spaces from copy/paste are ignored');
    }

    public function test_recovery_codes_work_once_and_are_normalised(): void
    {
        [$plain, $hashes] = RecoveryCodes::generate();
        $this->assertCount(10, array_unique($plain));
        $this->assertMatchesRegularExpression('/^[a-z2-7]{5}-[a-z2-7]{5}$/', $plain[0]);
        $this->assertNotContains($plain[0], $hashes);

        $left = RecoveryCodes::consume($hashes, strtoupper(str_replace('-', ' ', $plain[3])));
        $this->assertCount(9, $left);
        $this->assertNull(RecoveryCodes::consume($left, $plain[3]), 'already spent');
        $this->assertNull(RecoveryCodes::consume($hashes, 'aaaaa-bbbbb'));
    }

    // ---- enrolment -----------------------------------------------------------------------------------------------

    public function test_setup_protects_nothing_until_a_working_code_proves_the_device(): void
    {
        $u = $this->person();
        $secret = $this->actingAs($u)->postJson('/api/v1/mfa/setup')->assertOk()->json('data.secret');
        $this->assertFalse(Mfa::enabled($u));
        app('auth')->forgetGuards();
        $this->login($u)->assertOk()->assertJsonPath('message', 'success');   // plain login still works

        $this->actingAs($u)->postJson('/api/v1/mfa/enable', ['code' => '000000'])->assertStatus(422);
        $this->assertFalse(Mfa::enabled($u));
        $this->actingAs($u)->postJson('/api/v1/mfa/enable', ['code' => $this->code($secret)])->assertOk()->assertJsonCount(10, 'data.recovery_codes');
        $this->assertTrue(Mfa::enabled($u));
        $this->actingAs($u)->getJson('/api/v1/mfa')->assertJsonPath('data.enabled', true)->assertJsonPath('data.recovery_codes_left', 10);
    }

    public function test_secrets_and_recovery_codes_are_never_stored_in_the_clear(): void
    {
        $u = $this->person();
        [$secret, $codes] = $this->enrol($u);
        $row = DB::table('user_mfa')->where('user_id', $u->id)->first();
        $this->assertStringNotContainsString($secret, $row->secret);
        foreach ($codes as $c) {
            $this->assertStringNotContainsString($c, $row->recovery_codes);
            $this->assertStringNotContainsString(str_replace('-', '', $c), $row->recovery_codes);
        }
        $this->assertSame($secret, \Illuminate\Support\Facades\Crypt::decryptString($row->secret));
    }

    public function test_setup_cannot_replace_a_working_device_silently(): void
    {
        $u = $this->person();
        $this->enrol($u);
        $this->actingAs($u)->postJson('/api/v1/mfa/setup')->assertStatus(422);
    }

    // ---- login ---------------------------------------------------------------------------------------------------

    public function test_a_correct_password_alone_gets_no_session_and_no_user_data(): void
    {
        $control = $this->person();
        $this->login($control)->assertOk();
        $this->getJson('/api/v1/user')->assertOk();   // proves the test would notice a leaked session
        app('auth')->forgetGuards();
        $this->flushSession();

        $u = $this->person();
        $this->enrol($u);
        $r = $this->login($u)->assertOk()->assertJsonPath('message', 'mfa_required')->assertJsonPath('data.mfa_required', true);
        $this->assertArrayNotHasKey('0', $r->json('data'), 'no user record before the second factor');
        $this->assertNotEmpty($r->json('data.challenge'));
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_the_second_step_signs_in_and_a_replayed_code_cannot_be_used_again(): void
    {
        $u = $this->person();
        [$secret] = $this->enrol($u);

        $c1 = $this->login($u)->json('data.challenge');
        $code = $this->code($secret, 1);   // a fresh step the enrolment did not consume
        $this->postJson('/api/v1/login/mfa', ['challenge' => $c1, 'code' => $code])->assertOk()->assertJsonPath('message', 'success')->assertJsonPath('data.0.id', $u->id);
        $this->getJson('/api/v1/user')->assertOk();

        // the same code, with a brand new challenge: refused (replay)
        app('auth')->forgetGuards();
        $this->flushSession();
        $c2 = $this->login($u)->json('data.challenge');
        $this->postJson('/api/v1/login/mfa', ['challenge' => $c2, 'code' => $code])->assertUnauthorized();
        // thirty seconds later a newer code works
        $this->travel(30)->seconds();
        $this->postJson('/api/v1/login/mfa', ['challenge' => $c2, 'code' => $this->code($secret, 1)])->assertOk();
        $this->travelBack();
    }

    public function test_a_challenge_is_single_use_and_expires(): void
    {
        $u = $this->person();
        [$secret, $codes] = $this->enrol($u);

        // a second, still-valid factor must not be accepted on a challenge that was already used
        $c = $this->login($u)->json('data.challenge');
        $this->postJson('/api/v1/login/mfa', ['challenge' => $c, 'code' => $codes[0]])->assertOk();
        $this->postJson('/api/v1/login/mfa', ['challenge' => $c, 'code' => $codes[1]])->assertUnauthorized();

        app('auth')->forgetGuards();
        $c = $this->login($u)->json('data.challenge');
        $this->travel(6)->minutes();
        $this->postJson('/api/v1/login/mfa', ['challenge' => $c, 'code' => $this->code($secret)])->assertUnauthorized();
        $this->travelBack();
    }

    public function test_five_wrong_codes_kill_the_challenge_even_for_the_right_code(): void
    {
        $u = $this->person();
        [$secret] = $this->enrol($u);
        $c = $this->login($u)->json('data.challenge');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login/mfa', ['challenge' => $c, 'code' => '00000'.$i])->assertUnauthorized();
        }
        $this->postJson('/api/v1/login/mfa', ['challenge' => $c, 'code' => $this->code($secret, 1)])->assertUnauthorized();
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_a_challenge_belongs_to_one_person(): void
    {
        $a = $this->person([], 'a@firm.test');
        $b = $this->person([], 'b@firm.test');
        [$secretA] = $this->enrol($a);
        $this->enrol($b);

        $cb = $this->login($b)->json('data.challenge');
        $this->postJson('/api/v1/login/mfa', ['challenge' => $cb, 'code' => $this->code($secretA, 1)])->assertUnauthorized();   // A's code, B's challenge
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        $u = $this->person();
        [, $codes] = $this->enrol($u);

        $c = $this->login($u)->json('data.challenge');
        $this->postJson('/api/v1/login/mfa', ['challenge' => $c, 'code' => $codes[0]])->assertOk();
        app('auth')->forgetGuards();
        $this->flushSession();
        $c = $this->login($u)->json('data.challenge');
        $this->postJson('/api/v1/login/mfa', ['challenge' => $c, 'code' => $codes[0]])->assertUnauthorized();
        $this->postJson('/api/v1/login/mfa', ['challenge' => $c, 'code' => $codes[1]])->assertOk();

        $this->actingAs($u)->getJson('/api/v1/mfa')->assertJsonPath('data.recovery_codes_left', 8);
    }

    public function test_asking_for_fresh_challenges_does_not_reset_the_guessing_budget(): void
    {
        $u = $this->person();
        [$secret] = $this->enrol($u);

        // two challenges' worth of wrong codes: 10 failures for this person in total
        for ($round = 0; $round < 2; $round++) {
            $c = $this->login($u)->json('data.challenge');
            for ($i = 0; $i < 5; $i++) {
                $this->postJson('/api/v1/login/mfa', ['challenge' => $c, 'code' => '99999'.$i])->assertUnauthorized();
            }
            $this->travel(61)->seconds();   // clears the per-IP route throttle, not the per-person budget
        }
        // a third challenge and the RIGHT code: refused, the person is locked out of guessing for a while
        $c = $this->login($u)->json('data.challenge');
        $this->postJson('/api/v1/login/mfa', ['challenge' => $c, 'code' => $this->code($secret, 1)])->assertUnauthorized();

        $this->travel(16)->minutes();
        $c = $this->login($u)->json('data.challenge');
        $this->postJson('/api/v1/login/mfa', ['challenge' => $c, 'code' => $this->code($secret, 1)])->assertOk();
        $this->travelBack();
    }

    // ---- turning it off / regenerating ---------------------------------------------------------------------------

    public function test_switching_it_off_needs_the_password_and_a_current_code(): void
    {
        $u = $this->person();
        [$secret] = $this->enrol($u);

        $this->actingAs($u)->postJson('/api/v1/mfa/disable', ['password' => 'wrong', 'code' => $this->code($secret, 1)])->assertStatus(422);
        $this->actingAs($u)->postJson('/api/v1/mfa/disable', ['password' => self::PASSWORD, 'code' => '000000'])->assertStatus(422);
        $this->assertTrue(Mfa::enabled($u));
        $this->actingAs($u)->postJson('/api/v1/mfa/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret, 1)])->assertOk();
        $this->assertFalse(Mfa::enabled($u));
        $this->assertSame(1, DB::table('audits')->where('event', 'mfa_disabled')->count());
    }

    public function test_new_recovery_codes_replace_the_old_ones(): void
    {
        $u = $this->person();
        [$secret, $old] = $this->enrol($u);
        $new = $this->actingAs($u)->postJson('/api/v1/mfa/recovery-codes', ['password' => self::PASSWORD, 'code' => $this->code($secret, 1)])->assertOk()->json('data.recovery_codes');
        $this->assertCount(10, $new);
        $this->assertSame([], array_intersect($old, $new));

        app('auth')->forgetGuards();
        $c = $this->login($u)->json('data.challenge');
        $this->postJson('/api/v1/login/mfa', ['challenge' => $c, 'code' => $old[0]])->assertUnauthorized();
        $this->postJson('/api/v1/login/mfa', ['challenge' => $c, 'code' => $new[0]])->assertOk();
    }

    // ---- the firm's policy ---------------------------------------------------------------------------------------

    public function test_the_firm_can_require_it_but_not_lock_itself_out(): void
    {
        $admin = $this->person(['manage-security-settings']);
        $this->actingAs($admin)->getJson('/api/v1/security/policy')->assertOk()->assertJsonPath('data.require_mfa', false);

        $this->actingAs($admin)->putJson('/api/v1/security/policy', ['require_mfa' => true])->assertStatus(422);   // no MFA of their own yet
        $this->assertFalse(Mfa::required());

        $this->enrol($admin);
        $this->actingAs($admin)->putJson('/api/v1/security/policy', ['require_mfa' => true])->assertOk()->assertJsonPath('data.require_mfa', true);
        $this->assertTrue(Mfa::required());
        $row = DB::table('audits')->where('event', 'security_policy_updated')->first();
        $this->assertSame($admin->id, (int) $row->user_id);
        $this->assertSame('{"require_mfa":false}', $row->old_values);
        $this->assertSame('{"require_mfa":true}', $row->new_values);
    }

    public function test_the_policy_needs_its_permission(): void
    {
        $u = $this->person(['list-clients']);
        $this->actingAs($u)->getJson('/api/v1/security/policy')->assertForbidden();
        $this->actingAs($u)->putJson('/api/v1/security/policy', ['require_mfa' => true])->assertForbidden();
        $this->actingAs($u)->deleteJson('/api/v1/users/'.$u->id.'/mfa')->assertForbidden();
    }

    public function test_when_required_a_user_without_it_can_only_enrol_or_sign_out(): void
    {
        $admin = $this->person(['manage-security-settings']);
        $this->enrol($admin);
        $this->actingAs($admin)->putJson('/api/v1/security/policy', ['require_mfa' => true])->assertOk();

        $worker = $this->person(['list-clients']);
        $this->actingAs($worker)->getJson('/api/v1/clients')->assertForbidden()->assertJsonPath('code', 'mfa_enrollment_required');
        $this->actingAs($worker)->getJson('/api/v1/profile')->assertForbidden()->assertJsonPath('code', 'mfa_enrollment_required');
        // but can reach what they need to comply
        $this->actingAs($worker)->getJson('/api/v1/user')->assertOk();
        $this->actingAs($worker)->getJson('/api/v1/mfa')->assertOk()->assertJsonPath('data.required_by_firm', true);
        $secret = $this->actingAs($worker)->postJson('/api/v1/mfa/setup')->assertOk()->json('data.secret');
        $this->actingAs($worker)->postJson('/api/v1/mfa/enable', ['code' => $this->code($secret)])->assertOk();
        $this->actingAs($worker)->getJson('/api/v1/clients')->assertOk();   // compliant now
        $this->actingAs($worker)->postJson('/api/v1/logout')->assertOk();
    }

    public function test_when_required_it_cannot_be_switched_off_by_the_user(): void
    {
        $admin = $this->person(['manage-security-settings']);
        [$secret] = $this->enrol($admin);
        $this->actingAs($admin)->putJson('/api/v1/security/policy', ['require_mfa' => true])->assertOk();
        $this->actingAs($admin)->postJson('/api/v1/mfa/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret, 1)])->assertForbidden();
        $this->assertTrue(Mfa::enabled($admin));
    }

    public function test_an_admin_can_reset_a_colleagues_second_factor(): void
    {
        $admin = $this->person(['manage-security-settings']);
        $lost = $this->person(['list-clients'], 'lost@firm.test');
        $this->enrol($lost);
        DB::table('personal_access_tokens')->insert(['tokenable_type' => User::class, 'tokenable_id' => $lost->id, 'name' => 't', 'token' => hash('sha256', 'x'), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->deleteJson('/api/v1/users/'.$lost->id.'/mfa')->assertOk();
        $this->assertFalse(Mfa::enabled($lost));
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $lost->id)->count());
        $this->assertSame(1, DB::table('audits')->where('event', 'mfa_reset_by_admin')->where('auditable_id', $lost->id)->count());
        app('auth')->forgetGuards();
        $this->login($lost)->assertOk()->assertJsonPath('message', 'success');   // can sign in with the password again
    }

    public function test_platform_support_principals_are_not_asked_for_a_second_factor(): void
    {
        DB::table('firm_security_settings')->insert(['require_mfa' => true, 'created_at' => now(), 'updated_at' => now()]);
        $principal = User::factory()->create();
        DB::table('users')->where('id', $principal->id)->update(['platform_staff_id' => 5]);
        $req = \Illuminate\Http\Request::create('/x');
        $req->setUserResolver(fn () => $principal->fresh());

        $this->assertSame('ok', (new \App\Http\Middleware\EnforceMfaPolicy)->handle($req, fn () => response('ok'))->getContent());

        $normal = User::factory()->create();
        $req->setUserResolver(fn () => $normal);
        $this->assertSame(403, (new \App\Http\Middleware\EnforceMfaPolicy)->handle($req, fn () => response('ok'))->getStatusCode());
    }

    // ---- what the web app is told at sign-in ------------------------------------------------------------------------

    public function test_sign_in_tells_the_web_app_when_enrolment_comes_first(): void
    {
        $plain = $this->person(['list-clients']);
        $this->login($plain)->assertOk()->assertJsonPath('data.4', false);

        $admin = $this->person(['manage-security-settings']);
        [$secret] = $this->enrol($admin);
        $this->actingAs($admin)->putJson('/api/v1/security/policy', ['require_mfa' => true])->assertOk();

        app('auth')->forgetGuards();
        $this->login($plain)->assertOk()->assertJsonPath('data.0.id', $plain->id)->assertJsonPath('data.4', true);

        // an enrolled person passes the second step and is not sent to enrolment
        $challenge = $this->login($admin)->assertOk()->json('data.challenge');
        $this->postJson('/api/v1/login/mfa', ['challenge' => $challenge, 'code' => $this->code($secret, 1)])
            ->assertOk()->assertJsonPath('data.0.id', $admin->id)->assertJsonPath('data.4', false);
    }

    public function test_the_session_endpoint_reports_who_is_signed_in_even_before_enrolment(): void
    {
        $this->getJson('/api/v1/session')->assertUnauthorized();

        $admin = $this->person(['manage-security-settings']);
        $this->enrol($admin);
        $this->actingAs($admin)->putJson('/api/v1/security/policy', ['require_mfa' => true])->assertOk();

        $worker = $this->person(['list-clients']);
        $this->actingAs($worker)->getJson('/api/v1/session')->assertOk()
            ->assertJsonPath('data.0.id', $worker->id)
            ->assertJsonPath('data.1', false)
            ->assertJsonPath('data.3', ['list-clients'])
            ->assertJsonPath('data.4', true);
    }

    public function test_the_second_factor_is_not_part_of_a_portable_bundle(): void
    {
        $this->assertArrayHasKey('user_mfa', config('portability.exclude'));
        $this->assertContains('firm_security_settings', config('portability.include'));
    }
}
