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
        // create the permission (create() throws if it cannot, it never returns false)
        Permission::create([
            'name' => strtolower($permission),
            'guard_name' => strtolower($guard),
        ]);

        return CommandAlias::SUCCESS;
    }
}
