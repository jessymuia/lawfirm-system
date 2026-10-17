<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;

class InitApplication extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:init {--wipe : I understand this DROPS EVERY TABLE and all data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'DESTRUCTIVE dev/demo reset (migrate:fresh). For real installs and upgrades use app:install.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // migrate:fresh drops every table. Never let that happen by accident on a live install.
        if (! $this->option('wipe')) {
            $this->error('app:init DROPS ALL DATA (migrate:fresh). For a real install or an upgrade run `php artisan app:install`.');
            $this->line('If you really mean to wipe this database (development only), re-run with --wipe.');

            return CommandAlias::FAILURE;
        }

        $this->call('migrate:fresh');
        $this->call('law:setup-permissions');
        $this->call('app:create-admin-here');
        $this->call('law:setup-permissions');
        $this->call('app:setup-roles');
        //        $this->call('db:seed');  // seed the database

        return CommandAlias::SUCCESS;
    }
}
