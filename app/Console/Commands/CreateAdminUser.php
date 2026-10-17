<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
        $name = $this->ask('Admin full name', 'Admin User');
        $email = $this->ask('Admin email address');
        $phone = $this->ask('Admin phone number');

        if (! $email || User::where('email', $email)->exists()) {
            $this->error('A valid, unused email address is required.');

            return CommandAlias::FAILURE;
        }

        $password = $this->secret('Enter a password for the admin account (leave blank to auto-generate)');

        if (! $password) {
            // Str::password() only exists from Laravel 10 — Str::random()
            // is the 9.x-compatible equivalent (same fix as UserController).
            $password = Str::random(16);
            $generated = true;
        }

        $customUser = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'phone_number' => $phone,
            'department' => 'logis',
            'hire_date' => now(),
        ]);

        $response = $customUser->exists ? CommandAlias::SUCCESS : CommandAlias::FAILURE;

        if ($response == CommandAlias::SUCCESS) {
            $this->info('Admin user created successfully.');
            if (! empty($generated)) {
                $this->warn('Generated password (shown once, not logged): '.$password);
            }
        } else {
            $this->error('Admin user not created successfully');
        }

        return $response;
    }
}
