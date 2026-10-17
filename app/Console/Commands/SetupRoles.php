<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SetupRoles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:setup-roles';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup initial roles';

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
        // fetch all permissions
        $allPermissions = Permission::all();

        Role::create(['name' => 'admin']);
        $role = Role::findById(1);
        $role->syncPermissions($allPermissions);

        // check if role has all permissions
        $rolePermissions = $role->permissions;

        Role::create(['name' => 'advocate']);
        if ($rolePermissions->count() == $allPermissions->count()) {
            // assign role to admin user
            $user = User::find(1);
            $user->assignRole($role);

            $this->info('Admin role created successfully');

            return Command::SUCCESS;
        } else {
            $this->error('Admin role not created successfully');

            return Command::FAILURE;
        }
    }
}
