<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Console\Command\Command as CommandAlias;

class AddNewPermission extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:add-permission';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add new permission';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // prompt the user for the permission name
        $permission = $this->ask('Enter the permission name');
        // prompt the user for the guard
        $guard = $this->ask('Enter the guard');
        // create the permission
        $permission = Permission::create([
            'name' => strtolower($permission),
            'guard_name' => strtolower($guard),
        ]);

        // check if the permission was created
        if (!$permission) {
            $this->error('Permission not created');
            return CommandAlias::FAILURE;
        }

        return CommandAlias::SUCCESS;
    }
}
