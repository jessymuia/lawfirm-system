<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission + built-in role catalog sync, shared by app:install and app:upgrade.
 * Idempotent: permissions are firstOrCreate'd, roles are firstOrCreate'd, and admin is ALWAYS
 * re-synced to every permission so permissions added by a release reach it.
 */
class RoleCatalog
{
    public static function sync(): void
    {
        Artisan::call('law:setup-permissions');

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::all());
        Role::firstOrCreate(['name' => 'advocate', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
