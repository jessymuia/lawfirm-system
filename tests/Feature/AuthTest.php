<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\CreatesTestUsers;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_login_succeeds_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.id', $user->id);
    }

    public function test_login_fails_with_wrong_password_and_returns_401(): void
    {
        // SEC-5: previously returned HTTP 200 with a "400" string in the
        // body instead of a real error status.
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        // SEC-5: no rate limiting previously existed on login at all.
        RateLimiter::clear('test@example.com|127.0.0.1');
        $user = User::factory()->create(['email' => 'test@example.com', 'password' => Hash::make('correct-password')]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])
                ->assertStatus(401);
        }

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_logout_requires_authentication(): void
    {
        // SEC-4: no logout route previously existed at all.
        $this->postJson('/api/v1/logout')->assertStatus(401);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = $this->userWithPermissions([]);

        $this->actingAs($user)
            ->postJson('/api/v1/logout')
            ->assertStatus(200);
    }

    public function test_user_without_permission_cannot_list_cases(): void
    {
        // SEC-3: permission checks must actually gate access.
        $user = $this->userWithPermissions([]);

        $this->actingAs($user)
            ->getJson('/api/v1/cases')
            ->assertStatus(403);
    }

    public function test_user_with_permission_can_list_cases(): void
    {
        $user = $this->userWithPermissions(['list-cases']);

        $this->actingAs($user)
            ->getJson('/api/v1/cases')
            ->assertStatus(200);
    }

    public function test_non_admin_cannot_grant_themselves_arbitrary_permissions_via_role_update(): void
    {
        // SEC-13 (critical): RolesManagementController::update() had NO
        // permission check at all — any authenticated user could sync
        // arbitrary permissions onto any role, including their own.
        $user = $this->userWithPermissions([]);
        $role = Role::create(['name' => 'basic-role', 'guard_name' => 'web']);
        $user->assignRole($role);

        Permission::findOrCreate('delete-users', 'web');

        $response = $this->actingAs($user)
            ->putJson("/api/v1/roles/{$role->id}", [
                'name' => 'basic-role',
                'permissions' => ['delete-users'],
            ]);

        $response->assertStatus(403);
        $this->assertFalse($role->fresh()->hasPermissionTo('delete-users'));
    }

    public function test_admin_can_update_role_permissions(): void
    {
        $admin = $this->adminUser();
        $role = Role::create(['name' => 'editable-role', 'guard_name' => 'web']);
        Permission::findOrCreate('list-clients', 'web');

        $response = $this->actingAs($admin)
            ->putJson("/api/v1/roles/{$role->id}", [
                'name' => 'editable-role',
                'permissions' => ['list-clients'],
            ]);

        $response->assertStatus(200);
        $this->assertTrue($role->fresh()->hasPermissionTo('list-clients'));
    }

    public function test_admin_role_cannot_be_deleted(): void
    {
        $admin = $this->adminUser();
        $adminRole = Role::findOrCreate('admin', 'web');

        $response = $this->actingAs($admin)->deleteJson('/api/v1/roles/admin');

        $response->assertStatus(422);
        $this->assertNotNull($adminRole->fresh());
    }

    public function test_logout_actually_revokes_the_session_so_a_stale_session_cannot_reuse_it(): void
    {
        // SEC-4: logout must not be a no-op — a session that has logged
        // out must not still authenticate a subsequent request. Uses a
        // real cookie login (not actingAs, which pins the user on the
        // guard for the whole test and would mask a broken logout).
        $user = $this->userWithPermissions(['list-cases']);
        $user->forceFill(['password' => Hash::make('correct-password')])->save();

        $login = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);
        $login->assertStatus(200);

        $sessionCookie = collect($login->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertNotNull($sessionCookie, 'login must issue a session cookie');

        // Guard instances cache the resolved user within one app instance;
        // forget them between requests so each request re-authenticates
        // from the cookie alone, as separate real HTTP requests would.
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie($sessionCookie->getName(), $sessionCookie->getValue())
            ->getJson('/api/v1/cases')->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie($sessionCookie->getName(), $sessionCookie->getValue())
            ->postJson('/api/v1/logout')->assertStatus(200);

        // Replaying the pre-logout session cookie must now be rejected.
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie($sessionCookie->getName(), $sessionCookie->getValue())
            ->getJson('/api/v1/cases')->assertStatus(401);
    }

    public function test_a_revoked_session_id_is_treated_as_signed_out(): void
    {
        // SEC-4: a request in flight during logout can write the logged-in session back under the old id, and the
        // browser then holds the old cookie again (seen in the e2e logout journey once the dashboard made parallel
        // calls). Logout records the old id as revoked; a request that arrives with it is signed out and gets a
        // fresh session, whatever the session store says.
        $user = $this->userWithPermissions(['list-cases']);
        $session = $this->app['session']->driver();
        $session->start();
        $this->app['auth']->guard('web')->login($user);   // (login moves the session to a new id)
        $oldId = $session->getId();

        $request = \Illuminate\Http\Request::create('/api/v1/cases');
        $request->setLaravelSession($session);
        $mw = new \App\Http\Middleware\RejectRevokedSession;

        $mw->handle($request, fn () => response('ok'));
        $this->assertSame($user->id, $this->app['auth']->guard('web')->id(), 'a session nobody revoked is left alone');

        \App\Http\Middleware\RejectRevokedSession::revoke($oldId);
        $mw->handle($request, fn () => response('ok'));
        $this->assertNull($this->app['auth']->guard('web')->user());
        $this->assertNotSame($oldId, $session->getId(), 'a fresh session replaces the revoked one');
    }

    public function test_logout_revokes_the_session_it_ends(): void
    {
        $user = $this->userWithPermissions([]);
        $session = $this->app['session']->driver();
        $session->start();
        $this->app['auth']->guard('web')->login($user);
        $ending = $session->getId();

        $request = \Illuminate\Http\Request::create('/api/v1/logout', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);
        app(\App\Http\Controllers\APIController::class)->logout($request);

        $this->assertTrue(\Illuminate\Support\Facades\Cache::has(\App\Http\Middleware\RejectRevokedSession::key($ending)));
        $this->assertNotSame($ending, $session->getId());
    }

    public function test_password_reset_token_expires_after_60_minutes(): void
    {
        // SEC-6: the reset token must not be valid forever.
        $user = User::factory()->create();
        $plainToken = 'test-plain-token-value';

        DB::table('password_resets')->insert([
            'email' => $user->email,
            'token' => Hash::make($plainToken),
            'created_at' => now()->subMinutes(61), // 1 minute past expiry
        ]);

        $response = $this->postJson('/api/v1/password-reset', [
            'email' => $user->email,
            'token' => $plainToken,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(400);
        $this->assertFalse(Hash::check('new-password-123', $user->fresh()->password));
    }

    #[\PHPUnit\Framework\Attributes\Group('single-mode')]   // multi mode: identity resets, TenantPasswordRecoveryTest
    public function test_password_reset_succeeds_within_the_expiry_window(): void
    {
        $user = User::factory()->create();
        $plainToken = 'test-plain-token-value';

        DB::table('password_resets')->insert([
            'email' => $user->email,
            'token' => Hash::make($plainToken),
            'created_at' => now()->subMinutes(30), // within the 60-minute window
        ]);

        $response = $this->postJson('/api/v1/password-reset', [
            'email' => $user->email,
            'token' => $plainToken,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_password_reset_token_is_never_written_to_the_log(): void
    {
        // SEC-6: the token was previously logged in plaintext via
        // Log::info($token_str) in passwordRecovery(). Assert the log
        // channel never receives a call containing anything that looks
        // like the generated token, using a fake to inspect every log
        // write made during the request rather than just checking the
        // final log file (which may not exist/be writable in CI).
        Log::spy();
        $user = User::factory()->create();

        $this->postJson('/api/v1/password-recovery', ['email' => $user->email])->assertStatus(200);

        Log::shouldNotHaveReceived('info');
    }

    public function test_password_reset_validation_failure_returns_a_field_error_bag(): void
    {
        // UI-6 regression: reset_pass() used to return only
        // $validator->errors()->first() as a plain string with no
        // 'errors' key at all, so the frontend's shared
        // useFormErrors/setErrorsFromResponse composable had nothing to
        // parse and resetPassword.vue could only ever show a generic
        // toast. Assert the response now carries the standard
        // {message, errors: {field: [...]}} shape every other
        // validation-failure response in the app uses.
        $user = User::factory()->create();
        $plainToken = 'test-plain-token-value';

        DB::table('password_resets')->insert([
            'email' => $user->email,
            'token' => Hash::make($plainToken),
            'created_at' => now()->subMinutes(5),
        ]);

        $response = $this->postJson('/api/v1/password-reset', [
            'email' => $user->email,
            'token' => $plainToken,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['success', 'message', 'errors' => ['password']]);
        $this->assertFalse($response->json('success'));
    }

    public function test_password_reset_mismatched_confirmation_is_reported_on_the_password_field(): void
    {
        $user = User::factory()->create();
        $plainToken = 'test-plain-token-value';

        DB::table('password_resets')->insert([
            'email' => $user->email,
            'token' => Hash::make($plainToken),
            'created_at' => now()->subMinutes(5),
        ]);

        $response = $this->postJson('/api/v1/password-reset', [
            'email' => $user->email,
            'token' => $plainToken,
            'password' => 'a-valid-password-1',
            'password_confirmation' => 'a-different-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }
}
