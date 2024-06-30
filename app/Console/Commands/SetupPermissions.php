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
        //create default permissions
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        //create permissions
        //permissions for users
        Permission::findOrCreate( ModulePermissions::LIST_USERS);
        Permission::findOrCreate( ModulePermissions::CREATE_USERS);
        Permission::findOrCreate( ModulePermissions::UPDATE_USERS);
        Permission::findOrCreate( ModulePermissions::DELETE_USERS);

        //permissions for roles
        Permission::findOrCreate( ModulePermissions::LIST_ROLES);
        Permission::findOrCreate( ModulePermissions::CREATE_ROLES);
        Permission::findOrCreate( ModulePermissions::UPDATE_ROLES);
        Permission::findOrCreate( ModulePermissions::DELETE_ROLES);

        //permissions for permissions
        Permission::findOrCreate( ModulePermissions::LIST_PERMISSIONS);
        Permission::findOrCreate( ModulePermissions::CREATE_PERMISSIONS);
        Permission::findOrCreate( ModulePermissions::UPDATE_PERMISSIONS);
        Permission::findOrCreate( ModulePermissions::DELETE_PERMISSIONS);

        //permissions for case documents
        Permission::findOrCreate( ModulePermissions::LIST_CASE_DOCUMENTS);
        Permission::findOrCreate( ModulePermissions::CREATE_CASE_DOCUMENTS);
        Permission::findOrCreate( ModulePermissions::UPDATE_CASE_DOCUMENTS);
        Permission::findOrCreate( ModulePermissions::DELETE_CASE_DOCUMENTS);



        // permissions for cliens
        Permission::findOrCreate( ModulePermissions::LIST_CLIENTS);
        Permission::findOrCreate( ModulePermissions::CREATE_CLIENTS);
        Permission::findOrCreate( ModulePermissions::UPDATE_CLIENTS);
        Permission::findOrCreate( ModulePermissions::DELETE_CLIENTS);



        // permissions for suspect
        Permission::findOrCreate( ModulePermissions::LIST_SUSPECT);
        Permission::findOrCreate( ModulePermissions::CREATE_SUSPECT);
        Permission::findOrCreate( ModulePermissions::UPDATE_SUSPECT);
        Permission::findOrCreate( ModulePermissions::DELETE_SUSPECT);


        // permissions for task
        Permission::findOrCreate( ModulePermissions::LIST_TASK);
        Permission::findOrCreate( ModulePermissions::CREATE_TASK);
        Permission::findOrCreate( ModulePermissions::UPDATE_TASK);
        Permission::findOrCreate( ModulePermissions::DELETE_TASK);

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


        //confirm if all permissions are created
        $permissions = Permission::all();

        if($status = $permissions->count() == 37){
            $this->info('All permissions created successfully');
        }else{
            $this->error('Some permissions were not created');
        }
        return $status ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
    }
}
