<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** SEC-12: SCIM 2.0 user provisioning by a firm's identity provider, authenticated by a firm-issued token. */
class ScimTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const U = '/api/v1/scim/v2/Users';

    private function securityAdmin(): User
    {
        return $this->userWithPermissions(['manage-security-settings']);
    }

    private function signedOut(): void
    {
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');
    }

    /** @return array{0: string, 1: int} the token (shown once) and its id */
    private function issueToken(?int $roleId = null, ?User $as = null): array
    {
        $r = $this->actingAs($as ?? $this->securityAdmin())->postJson('/api/v1/security/scim-tokens', ['name' => 'Okta', 'default_role_id' => $roleId])->assertCreated();
        $this->signedOut();

        return [$r->json('data.token'), (int) $r->json('data.id')];
    }

    private function scim(string $method, string $path, string $token, array $body = [])
    {
        $this->signedOut();

        return $this->withToken($token)->json($method, $path, $body);
    }

    private function newUser(string $token, string $email = 'new.person@firm.test', array $over = [])
    {
        return $this->scim('POST', self::U, $token, $over + [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'], 'userName' => $email,
            'name' => ['givenName' => 'New', 'familyName' => 'Person'], 'active' => true,
        ]);
    }

    private function patchActive(string $token, string $id, mixed $value, bool $withPath = true)
    {
        $op = $withPath ? ['op' => 'Replace', 'path' => 'active', 'value' => $value] : ['op' => 'replace', 'value' => ['active' => $value]];

        return $this->scim('PATCH', self::U."/{$id}", $token, ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [$op]]);
    }

    // ---- the token -----------------------------------------------------------------------------------------------

    public function test_the_token_is_shown_once_and_only_its_hash_is_kept(): void
    {
        [$token, $id] = $this->issueToken();
        $this->assertMatchesRegularExpression('/^scm_[0-9a-f]{8}_[A-Za-z0-9]{40}$/', $token);
        $row = DB::table('scim_tokens')->find($id);
        $this->assertSame(hash('sha256', substr($token, 13)), $row->token_hash);
        $this->assertStringNotContainsString(substr($token, 13), json_encode($row));

        $list = $this->actingAs($this->securityAdmin())->getJson('/api/v1/security/scim-tokens')->assertOk();
        $this->assertStringNotContainsString(substr($token, 13), $list->getContent());
        $this->assertStringNotContainsString('token_hash', $list->getContent());
    }

    public function test_issuing_and_revoking_tokens_needs_the_permission_and_the_plan(): void
    {
        $nobody = $this->userWithPermissions(['list-clients']);
        $this->actingAs($nobody)->postJson('/api/v1/security/scim-tokens', ['name' => 'x'])->assertForbidden();
        $this->actingAs($nobody)->getJson('/api/v1/security/scim-tokens')->assertForbidden();

        $this->usePlan('starter');
        $this->actingAs($this->securityAdmin())->postJson('/api/v1/security/scim-tokens', ['name' => 'x'])->assertForbidden()->assertJsonPath('code', 'entitlement_required');
    }

    public function test_every_failure_to_authenticate_is_the_same_scim_401(): void
    {
        [$token, $id] = $this->issueToken();
        foreach ([null, 'garbage', 'scm_00000000_'.str_repeat('a', 40), 'scm_'.substr($token, 4, 8).'_'.str_repeat('b', 40)] as $bad) {
            $this->signedOut();
            $r = $bad === null ? $this->getJson(self::U) : $this->withToken($bad)->getJson(self::U);
            $r->assertStatus(401)->assertJsonPath('schemas.0', 'urn:ietf:params:scim:api:messages:2.0:Error');
        }

        $this->actingAs($this->securityAdmin())->deleteJson("/api/v1/security/scim-tokens/{$id}")->assertOk();
        $this->scim('GET', self::U, $token)->assertStatus(401);
    }

    public function test_a_plan_without_sso_switches_provisioning_off(): void
    {
        [$token] = $this->issueToken();
        $this->usePlan('starter');
        $this->scim('GET', self::U, $token)->assertStatus(403);
    }

    public function test_the_token_reaches_scim_and_nothing_else(): void
    {
        [$token] = $this->issueToken();
        $this->scim('GET', '/api/v1/users', $token)->assertStatus(401);
        $this->scim('GET', '/api/v1/cases', $token)->assertStatus(401);
        $this->scim('GET', self::U, $token)->assertOk();
    }

    // ---- creating and reading ------------------------------------------------------------------------------------

    public function test_a_person_is_created_with_the_role_chosen_on_the_token(): void
    {
        $role = Role::create(['name' => 'associate-'.uniqid(), 'guard_name' => 'web']);
        [$token] = $this->issueToken($role->id);

        $r = $this->newUser($token)->assertCreated()->assertJsonPath('userName', 'new.person@firm.test')->assertJsonPath('active', true)
            ->assertJsonPath('name.givenName', 'New')->assertJsonPath('name.familyName', 'Person');
        $user = User::findOrFail((int) $r->json('id'));
        $this->assertTrue($user->hasRole($role->name));
        $this->assertSame('New Person', $user->name);
        $this->assertSame(1, DB::table('audits')->where('event', 'scim_user_created')->count());
    }

    public function test_creating_twice_or_with_nonsense_is_refused_in_scim_terms(): void
    {
        [$token] = $this->issueToken();
        $this->newUser($token)->assertCreated();
        $this->newUser($token, 'NEW.person@firm.test')->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');
        $this->newUser($token, 'not-an-email')->assertStatus(400)->assertJsonPath('schemas.0', 'urn:ietf:params:scim:api:messages:2.0:Error');
    }

    public function test_a_person_created_inactive_is_a_deactivated_account(): void
    {
        [$token] = $this->issueToken();
        $id = $this->newUser($token, 'late@firm.test', ['active' => false])->assertCreated()->assertJsonPath('active', false)->json('id');
        $this->assertNotNull(User::withTrashed()->find($id)->deleted_at);
    }

    public function test_listing_filtering_and_paging(): void
    {
        [$token] = $this->issueToken();
        $this->newUser($token, 'a@firm.test');
        $this->newUser($token, 'b@firm.test');

        $all = $this->scim('GET', self::U, $token)->assertOk();
        $this->assertGreaterThanOrEqual(3, $all->json('totalResults'));   // the token's creator plus two

        $one = $this->scim('GET', self::U.'?filter='.urlencode('userName eq "B@firm.test"'), $token)->assertOk();
        $this->assertSame(1, $one->json('totalResults'));
        $this->assertSame('b@firm.test', $one->json('Resources.0.userName'));

        $page = $this->scim('GET', self::U.'?startIndex=1&count=1', $token)->assertOk();
        $this->assertSame(1, $page->json('itemsPerPage'));

        $this->scim('GET', self::U.'?filter='.urlencode('userName co "x"'), $token)->assertStatus(400)->assertJsonPath('scimType', 'invalidFilter');
        $this->scim('GET', self::U.'/999999', $token)->assertStatus(404);
    }

    public function test_discovery_documents_are_served(): void
    {
        [$token] = $this->issueToken();
        $this->scim('GET', '/api/v1/scim/v2/ServiceProviderConfig', $token)->assertOk()->assertJsonPath('patch.supported', true)->assertJsonPath('bulk.supported', false);
        $this->scim('GET', '/api/v1/scim/v2/ResourceTypes', $token)->assertOk()->assertJsonPath('Resources.0.name', 'User');
    }

    // ---- changing and deactivating -------------------------------------------------------------------------------

    public function test_both_patch_styles_deactivate_and_reactivate(): void
    {
        [$token] = $this->issueToken();
        $id = $this->newUser($token)->json('id');

        // Entra: path + a string value
        $this->patchActive($token, $id, 'False')->assertOk()->assertJsonPath('active', false);
        $this->assertNotNull(User::withTrashed()->find($id)->deleted_at);
        // Okta: no path, an object
        $this->patchActive($token, $id, true, withPath: false)->assertOk()->assertJsonPath('active', true);
        $this->assertNull(User::withTrashed()->find($id)->deleted_at);

        $this->assertSame(1, DB::table('audits')->where('event', 'scim_user_deactivated')->count());
        $this->assertSame(1, DB::table('audits')->where('event', 'scim_user_reactivated')->count());
    }

    public function test_a_name_can_be_changed_by_patch_and_put(): void
    {
        [$token] = $this->issueToken();
        $id = $this->newUser($token)->json('id');

        $this->scim('PATCH', self::U."/{$id}", $token, ['Operations' => [['op' => 'replace', 'path' => 'name.givenName', 'value' => 'Renamed']]])
            ->assertOk()->assertJsonPath('displayName', 'Renamed Person');
        $this->scim('PUT', self::U."/{$id}", $token, ['userName' => 'new.person@firm.test', 'name' => ['formatted' => 'Fully Replaced']])
            ->assertOk()->assertJsonPath('displayName', 'Fully Replaced');
        $this->assertSame('Fully Replaced', User::find($id)->name);
    }

    public function test_the_email_address_cannot_be_changed_through_scim(): void
    {
        [$token] = $this->issueToken();
        $id = $this->newUser($token)->json('id');

        $this->scim('PUT', self::U."/{$id}", $token, ['userName' => 'someone.else@firm.test'])->assertStatus(400)->assertJsonPath('scimType', 'mutability');
        $this->scim('PATCH', self::U."/{$id}", $token, ['Operations' => [['op' => 'replace', 'path' => 'userName', 'value' => 'someone.else@firm.test']]])
            ->assertStatus(400)->assertJsonPath('scimType', 'mutability');
        $this->assertSame('new.person@firm.test', User::find($id)->email);
    }

    public function test_unsupported_attributes_are_ignored_not_fatal(): void
    {
        [$token] = $this->issueToken();
        $id = $this->newUser($token)->json('id');
        $this->scim('PATCH', self::U."/{$id}", $token, ['Operations' => [
            ['op' => 'add', 'path' => 'emails[type eq "work"].value', 'value' => 'new.person@firm.test'],
            ['op' => 'remove', 'path' => 'phoneNumbers'],
            ['op' => 'replace', 'path' => 'externalId', 'value' => 'abc'],
        ]])->assertOk();
    }

    public function test_delete_deactivates_and_the_record_remains_readable(): void
    {
        [$token] = $this->issueToken();
        $u = $this->newUser($token)->json('id');
        User::find($u)->createToken('api');
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $u)->count());

        $this->scim('DELETE', self::U."/{$u}", $token)->assertNoContent();
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $u)->count());   // API tokens stop with the person
        $this->scim('GET', self::U."/{$u}", $token)->assertOk()->assertJsonPath('active', false);
        $this->assertNotNull(User::withTrashed()->find($u)->deleted_at);   // soft delete: history stays attributable
    }

    // ---- what the identity provider may not do -------------------------------------------------------------------

    public function test_the_last_security_administrator_cannot_be_deactivated_from_the_idp(): void
    {
        $admin = $this->securityAdmin();
        [$token] = $this->issueToken(null, $admin);

        $this->patchActive($token, (string) $admin->id, false)->assertStatus(409);
        $this->scim('DELETE', self::U."/{$admin->id}", $token)->assertStatus(409);
        $this->assertNull(User::withTrashed()->find($admin->id)->deleted_at);

        // with a second security administrator it is allowed
        $this->securityAdmin();
        $this->patchActive($token, (string) $admin->id, false)->assertOk()->assertJsonPath('active', false);
    }

    public function test_platform_support_principals_are_invisible_to_scim(): void
    {
        [$token] = $this->issueToken();
        $p = User::factory()->create(['email' => 'support-9-x@support.invalid']);
        DB::table('users')->where('id', $p->id)->update(['platform_staff_id' => 3]);

        $this->scim('GET', self::U."/{$p->id}", $token)->assertStatus(404);
        $this->patchActive($token, (string) $p->id, false)->assertStatus(404);
        $this->scim('GET', self::U.'?filter='.urlencode('userName eq "support-9-x@support.invalid"'), $token)->assertOk()->assertJsonPath('totalResults', 0);
        $this->newUser($token, 'support-9-x@support.invalid')->assertStatus(409);   // the address is taken, even though it cannot be seen
    }
}
