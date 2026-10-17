<?php

namespace App\Console\Commands;

use App\Constants\ModulePermissions;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Console\Command\Command as CommandAlias;

class SetupPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'law:setup-permissions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup application permissions';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // create default permissions
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // create permissions
        // permissions for users
        Permission::findOrCreate(ModulePermissions::LIST_USERS);
        Permission::findOrCreate(ModulePermissions::CREATE_USERS);
        Permission::findOrCreate(ModulePermissions::UPDATE_USERS);
        Permission::findOrCreate(ModulePermissions::DELETE_USERS);

        // permissions for roles
        Permission::findOrCreate(ModulePermissions::LIST_ROLES);
        Permission::findOrCreate(ModulePermissions::CREATE_ROLES);
        Permission::findOrCreate(ModulePermissions::UPDATE_ROLES);
        Permission::findOrCreate(ModulePermissions::DELETE_ROLES);

        // permissions for permissions
        Permission::findOrCreate(ModulePermissions::LIST_PERMISSIONS);
        Permission::findOrCreate(ModulePermissions::CREATE_PERMISSIONS);
        Permission::findOrCreate(ModulePermissions::UPDATE_PERMISSIONS);
        Permission::findOrCreate(ModulePermissions::DELETE_PERMISSIONS);

        // permissions for cases
        Permission::findOrCreate(ModulePermissions::LIST_CASES);
        Permission::findOrCreate(ModulePermissions::CREATE_CASES);
        Permission::findOrCreate(ModulePermissions::UPDATE_CASES);
        Permission::findOrCreate(ModulePermissions::DELETE_CASES);

        // permissions for hearings
        Permission::findOrCreate(ModulePermissions::LIST_HEARINGS);
        Permission::findOrCreate(ModulePermissions::CREATE_HEARINGS);
        Permission::findOrCreate(ModulePermissions::UPDATE_HEARINGS);
        Permission::findOrCreate(ModulePermissions::DELETE_HEARINGS);

        // permissions for documents
        Permission::findOrCreate(ModulePermissions::LIST_DOCUMENTS);
        Permission::findOrCreate(ModulePermissions::VIEW_DOCUMENTS);
        Permission::findOrCreate(ModulePermissions::CREATE_DOCUMENTS);
        Permission::findOrCreate(ModulePermissions::DELETE_DOCUMENTS);

        // permissions for expenses
        Permission::findOrCreate(ModulePermissions::LIST_EXPENSES);
        Permission::findOrCreate(ModulePermissions::CREATE_EXPENSES);
        Permission::findOrCreate(ModulePermissions::UPDATE_EXPENSES);
        Permission::findOrCreate(ModulePermissions::DELETE_EXPENSES);

        // permissions for cliens
        Permission::findOrCreate(ModulePermissions::LIST_CLIENTS);
        Permission::findOrCreate(ModulePermissions::CREATE_CLIENTS);
        Permission::findOrCreate(ModulePermissions::UPDATE_CLIENTS);
        Permission::findOrCreate(ModulePermissions::DELETE_CLIENTS);

        // permissions for suspect
        Permission::findOrCreate(ModulePermissions::LIST_SUSPECT);
        Permission::findOrCreate(ModulePermissions::CREATE_SUSPECT);
        Permission::findOrCreate(ModulePermissions::UPDATE_SUSPECT);
        Permission::findOrCreate(ModulePermissions::DELETE_SUSPECT);

        // permissions for task
        Permission::findOrCreate(ModulePermissions::LIST_TASK);
        Permission::findOrCreate(ModulePermissions::CREATE_TASK);
        Permission::findOrCreate(ModulePermissions::UPDATE_TASK);
        Permission::findOrCreate(ModulePermissions::DELETE_TASK);

        // permissions for courts
        Permission::findOrCreate(ModulePermissions::LIST_COURTS);
        Permission::findOrCreate(ModulePermissions::CREATE_COURTS);
        Permission::findOrCreate(ModulePermissions::UPDATE_COURTS);
        Permission::findOrCreate(ModulePermissions::DELETE_COURTS);

        // permissions for invoices
        Permission::findOrCreate(ModulePermissions::LIST_INVOICES);
        Permission::findOrCreate(ModulePermissions::CREATE_INVOICES);
        Permission::findOrCreate(ModulePermissions::UPDATE_INVOICES);
        Permission::findOrCreate(ModulePermissions::DELETE_INVOICES);

        Permission::findOrCreate(ModulePermissions::VIEW_DASHBOARD);

        // permissions for time entries
        Permission::findOrCreate(ModulePermissions::LIST_TIME_ENTRIES);
        Permission::findOrCreate(ModulePermissions::CREATE_TIME_ENTRIES);
        Permission::findOrCreate(ModulePermissions::UPDATE_TIME_ENTRIES);
        Permission::findOrCreate(ModulePermissions::DELETE_TIME_ENTRIES);

        // permissions for payments
        Permission::findOrCreate(ModulePermissions::LIST_PAYMENTS);
        Permission::findOrCreate(ModulePermissions::CREATE_PAYMENTS);
        Permission::findOrCreate(ModulePermissions::DELETE_PAYMENTS);

        // permissions for trust accounting
        Permission::findOrCreate(ModulePermissions::LIST_TRUST_TRANSACTIONS);
        Permission::findOrCreate(ModulePermissions::CREATE_TRUST_TRANSACTIONS);
        Permission::findOrCreate(ModulePermissions::VOID_TRUST_TRANSACTIONS);

        // permission for full-firm export
        Permission::findOrCreate(ModulePermissions::EXPORT_FIRM_DATA);

        // PLAT-2: install/replace the self-hosted license
        Permission::findOrCreate(ModulePermissions::MANAGE_LICENSE);

        // SEC-10: matter-level access
        Permission::findOrCreate(ModulePermissions::ACCESS_ALL_MATTERS);
        Permission::findOrCreate(ModulePermissions::MANAGE_MATTER_ACCESS);
        Permission::findOrCreate(ModulePermissions::MANAGE_ETHICAL_SCREENS);
        Permission::findOrCreate(ModulePermissions::MANAGE_SUPPORT_ACCESS);
        Permission::findOrCreate(ModulePermissions::MANAGE_SECURITY_SETTINGS);
        Permission::findOrCreate(ModulePermissions::MANAGE_COMPLIANCE);
        Permission::findOrCreate(ModulePermissions::MANAGE_API_ACCESS);
        Permission::findOrCreate(ModulePermissions::MANAGE_SUBSCRIPTION);
        Permission::findOrCreate(ModulePermissions::RUN_CONFLICT_CHECKS);
        Permission::findOrCreate(ModulePermissions::MANAGE_CONFLICTS);
        Permission::findOrCreate(ModulePermissions::MANAGE_ORGANISATION);
        Permission::findOrCreate(ModulePermissions::RECONCILE_TRUST_ACCOUNTS);
        Permission::findOrCreate(ModulePermissions::VIEW_PARTNER_REPORTS);
        Permission::findOrCreate(ModulePermissions::MANAGE_CLIENT_PORTAL);
        Permission::findOrCreate(ModulePermissions::MANAGE_DOCUMENT_TEMPLATES);
        Permission::findOrCreate(ModulePermissions::MANAGE_BRANDING);   // UI-13
        Permission::findOrCreate(ModulePermissions::MANAGE_FINANCE_SETTINGS);   // DATA-14

        // confirm if all permissions are created
        $permissions = Permission::all();

        if ($status = $permissions->count() == 78) {
            $this->info('All permissions created successfully');
        } else {
            $this->error('Some permissions were not created');
        }

        return $status ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
    }
}
