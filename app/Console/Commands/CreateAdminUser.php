<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Command\Command as CommandAlias;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-admin-here';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Admin User for the application';

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
        User::create([
            "name" => "Lawrence",
            "email" => "admin@gmail.com",
            "password" => Hash::make("pass"),
            "phone_number" => "0745321146",
            "department" => "logis",
            "hire_date" => now()
        ]);
        $customUser = User::where('email', 'admin@gmail.com')->first();

        $customUser->password = Hash::make('qwerty1234');
//        $customUser->password_expires_on = now()->addDays(90);

        $response = $customUser->save() ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
        if ($response == CommandAlias::SUCCESS) {
            $this->info('Admin user created successfully');
        } else {
            $this->error('Admin user not created successfully');
        }
        return $response;
    }
}
