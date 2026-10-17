<?php

namespace Database\Seeders;

use App\Constants\ModulePermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Bug fix: UserSeeder calls Role::all()->pluck('id')->random(1) to
     * assign a random role to every factory user, but nothing anywhere in
     * the seeding pipeline ever created a single Role record — the
     * `law:setup-permissions` artisan command only creates Permission
     * rows, never Roles, and DatabaseSeeder never called it at all. A
     * fresh `php artisan db:seed` would crash immediately on an empty
     * roles table (Collection::random() throws on an empty collection).
     *
     * This seeder must run after permissions exist and before UserSeeder.
     */
    public function run()
    {
        // Reuse the existing permission-creation logic rather than
        // duplicating the full permission list here.
        Artisan::call('law:setup-permissions');

        $admin = Role::findOrCreate('admin', 'web');
        $admin->syncPermissions(Permission::all());

        // 'advocate' is referenced by name in ClientFactory, CasesFactory,
        // and TaskFactory — it must exist with this exact name for those
        // factories (and therefore every other seeder that depends on
        // them) to work.
        $advocate = Role::findOrCreate('advocate', 'web');
        $advocate->syncPermissions([
            ModulePermissions::VIEW_DASHBOARD,
            ModulePermissions::LIST_CLIENTS, ModulePermissions::CREATE_CLIENTS, ModulePermissions::UPDATE_CLIENTS, ModulePermissions::DELETE_CLIENTS,
            ModulePermissions::LIST_CASES, ModulePermissions::CREATE_CASES, ModulePermissions::UPDATE_CASES, ModulePermissions::DELETE_CASES,
            ModulePermissions::LIST_HEARINGS, ModulePermissions::CREATE_HEARINGS, ModulePermissions::UPDATE_HEARINGS, ModulePermissions::DELETE_HEARINGS,
            ModulePermissions::LIST_DOCUMENTS, ModulePermissions::CREATE_DOCUMENTS, ModulePermissions::VIEW_DOCUMENTS,
            ModulePermissions::LIST_EXPENSES, ModulePermissions::CREATE_EXPENSES, ModulePermissions::UPDATE_EXPENSES, ModulePermissions::DELETE_EXPENSES,
            ModulePermissions::LIST_INVOICES, ModulePermissions::CREATE_INVOICES, ModulePermissions::UPDATE_INVOICES,
            ModulePermissions::RUN_CONFLICT_CHECKS,   // FUN-9: advocates open clients and matters, so they run the check
            ModulePermissions::MANAGE_CLIENT_PORTAL,  // FUN-10: advocates share documents with their clients and answer them
            // Bug fix (found via a real E2E run): an advocate could
            // create, submit, and update their own invoices but not
            // view payment history against them at all — the invoice
            // preview page's payments panel 403'd for every advocate,
            // and there'd be no way to see a payment an admin recorded
            // reflected back. Advocates still can't record a payment
            // themselves (that stays admin-only, per the workflow this
            // spec tests) — this only grants read access to payments
            // already recorded.
            ModulePermissions::LIST_PAYMENTS,
            ModulePermissions::LIST_TASK, ModulePermissions::CREATE_TASK, ModulePermissions::UPDATE_TASK,
            ModulePermissions::LIST_TIME_ENTRIES, ModulePermissions::CREATE_TIME_ENTRIES, ModulePermissions::UPDATE_TIME_ENTRIES,
            ModulePermissions::LIST_COURTS,
        ]);

        // A read-only front-desk/clerk role: can see clients and cases
        // but not create/edit/delete anything, and has no access at all
        // to invoices, expenses, or documents. Used by the RBAC E2E test
        // to prove restricted modules are actually blocked, not just
        // hidden from navigation.
        $clerk = Role::findOrCreate('clerk', 'web');
        $clerk->syncPermissions([
            ModulePermissions::VIEW_DASHBOARD,
            ModulePermissions::LIST_CLIENTS,
            ModulePermissions::LIST_CASES,
        ]);
    }
}
