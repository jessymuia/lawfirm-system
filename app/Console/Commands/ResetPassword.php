<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Console\Command\Command as CommandAlias;

class ResetPassword extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:change-password';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Change password for the application';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // prompt the user for the email address
        $email = $this->ask('Enter the email address of the user');
        // get the user with the email address
        $user = User::query()->where('email', $email)->first();
        // check if the user exists
        if (! $user) {
            $this->error('User not found');

            return CommandAlias::FAILURE;
        }
        // prompt the user for the new password
        $password = $this->secret('Enter the new password');
        // update the user's password
        $user->password = bcrypt($password);
        // save the user
        $user->save();

        // return success
        return CommandAlias::SUCCESS;
    }
}
