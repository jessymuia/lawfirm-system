<?php

namespace Tests;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

trait CreatesTestUsers
{
    /**
     * Create a user with exactly the given permissions (no admin role).
     * Used to test that permission checks actually gate access, rather
     * than every test running as an all-powerful admin and never
     * exercising the SEC-3 permission checks at all.
     */
    protected function userWithPermissions(array $permissions = []): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role = Role::create(['name' => 'test-role-'.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }

    /**
     * Create an admin user. Several controllers (invoices, roles) check
     * hasRole('admin') directly rather than a specific permission.
     */
    protected function adminUser(): User
    {
        // Create the app's full permission set first — on a fresh test
        // database Permission::all() is empty, which would produce an
        // "admin" with no permissions at all.
        Artisan::call('law:setup-permissions');

        $user = User::factory()->create();
        $role = Role::findOrCreate('admin', 'web');
        $role->syncPermissions(Permission::all());
        $user->assignRole($role);

        return $user;
    }
}
