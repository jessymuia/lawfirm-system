<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** The roles and permissions API: a firm administrator defines who can do what. */
class RolesManagementTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private function manager()
    {
        return $this->userWithPermissions(['list-roles', 'create-roles', 'update-roles', 'delete-roles', 'list-permissions', 'list-clients', 'create-clients']);
    }

    public function test_a_role_is_created_with_its_permissions_listed_and_read_back(): void
    {
        $u = $this->manager();

        $id = $this->actingAs($u)->postJson('/api/v1/roles', ['name' => 'paralegal', 'permissions' => ['list-clients', 'create-clients']])
            ->assertCreated()->assertJsonPath('data.name', 'paralegal')->json('data.id');
        $this->assertEqualsCanonicalizing(['list-clients', 'create-clients'], Role::findById($id, 'web')->permissions->pluck('name')->all());

        $this->actingAs($u)->getJson('/api/v1/roles?page=1')->assertOk()->assertJsonFragment(['name' => 'paralegal']);
        $this->actingAs($u)->getJson('/api/v1/permissions/'.$id)->assertOk()
            ->assertJsonPath('data', fn ($names) => collect($names)->sort()->values()->all() === ['create-clients', 'list-clients']);
        $this->actingAs($u)->getJson('/api/v1/permissions')->assertOk()->assertJsonFragment(['name' => 'list-roles']);
    }

    public function test_a_role_without_permissions_and_invalid_input(): void
    {
        $u = $this->manager();

        $this->actingAs($u)->postJson('/api/v1/roles', ['name' => 'observer'])->assertCreated();
        $this->assertCount(0, Role::findByName('observer', 'web')->permissions);

        $this->actingAs($u)->postJson('/api/v1/roles', ['name' => 'observer'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($u)->postJson('/api/v1/roles', ['name' => 'x', 'permissions' => ['no-such-permission']])->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
    }

    public function test_updating_renames_and_replaces_the_permissions(): void
    {
        $u = $this->manager();
        $role = Role::create(['name' => 'junior', 'guard_name' => 'web']);
        $role->givePermissionTo('list-clients');

        $this->actingAs($u)->putJson('/api/v1/roles/'.$role->id, ['name' => 'associate', 'permissions' => ['create-clients']])
            ->assertOk()->assertJsonPath('data.name', 'associate');
        $this->assertSame(['create-clients'], $role->fresh()->permissions->pluck('name')->all());

        // keeping its own name is not a clash; no permissions list clears them
        $this->actingAs($u)->putJson('/api/v1/roles/'.$role->id, ['name' => 'associate'])->assertOk();
        $this->assertCount(0, $role->fresh()->permissions);
    }

    public function test_deleting_a_role_but_never_the_admin_role(): void
    {
        $u = $this->manager();
        $role = Role::create(['name' => 'temp', 'guard_name' => 'web']);

        $this->actingAs($u)->deleteJson('/api/v1/roles/temp')->assertOk()->assertJsonPath('data.id', $role->id);
        $this->assertNull(Role::where('name', 'temp')->first());

        Role::findOrCreate('admin', 'web');
        $this->actingAs($u)->deleteJson('/api/v1/roles/admin')->assertUnprocessable();
        $this->assertNotNull(Role::where('name', 'admin')->first());

        $this->actingAs($u)->deleteJson('/api/v1/roles/missing')->assertNotFound();
    }

    public function test_each_action_needs_its_permission(): void
    {
        $u = $this->userWithPermissions(['list-clients']);
        $role = Role::create(['name' => 'temp', 'guard_name' => 'web']);

        $this->actingAs($u)->getJson('/api/v1/roles')->assertForbidden();
        $this->actingAs($u)->postJson('/api/v1/roles', ['name' => 'x'])->assertForbidden();
        $this->actingAs($u)->putJson('/api/v1/roles/'.$role->id, ['name' => 'y'])->assertForbidden();
        $this->actingAs($u)->deleteJson('/api/v1/roles/temp')->assertForbidden();
        $this->actingAs($u)->getJson('/api/v1/permissions')->assertForbidden();
        $this->actingAs($u)->getJson('/api/v1/permissions/'.$role->id)->assertForbidden();
    }
}
