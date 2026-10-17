<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * PLAT-5: the safe, repeatable first-run for self-hosted installs. Unlike app:init it never wipes data:
 *   1. migrate (forward only)   2. permissions   3. roles (admin always re-synced to ALL permissions)
 *   4. the first admin, only if no user exists yet.
 * Run it on first install and again after every upgrade; running it twice changes nothing.
 */
class AppInstall extends Command
{
    protected $signature = 'app:install
        {--admin-name= : First admin full name (env INSTALL_ADMIN_NAME)}
        {--admin-email= : First admin email (env INSTALL_ADMIN_EMAIL)}
        {--admin-phone= : First admin phone (env INSTALL_ADMIN_PHONE)}
        {--admin-password= : First admin password, 12+ chars (env INSTALL_ADMIN_PASSWORD); generated and shown once if omitted}';

    protected $description = 'Migrate, set up permissions/roles and create the first admin (idempotent, non-destructive)';

    public function handle(): int
    {
        // Validate the admin input before touching the database.
        $needsAdmin = ! $this->usersExist();
        $name = $this->option('admin-name') ?: config('install.admin_name');
        $email = $this->option('admin-email') ?: config('install.admin_email');
        $phone = $this->option('admin-phone') ?: config('install.admin_phone');
        $password = $this->option('admin-password') ?: config('install.admin_password');

        if ($needsAdmin) {
            if (! $email && $this->input->isInteractive()) {
                $email = $this->ask('Admin email address');
            }
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('A valid --admin-email (or INSTALL_ADMIN_EMAIL) is required for the first install.');

                return self::FAILURE;
            }
            if ($password && strlen($password) < 12) {
                $this->error('The admin password must be at least 12 characters.');

                return self::FAILURE;
            }
        }

        // One path for first install and every upgrade: migrate, permissions/roles, release record.
        // Caches are left to the entrypoint/deploy step, so skip them here.
        if ($this->call('app:upgrade', ['--skip-cache' => true]) !== 0) {
            return self::FAILURE;
        }

        if (! $needsAdmin) {
            $this->info('Already installed: schema and roles are up to date; no admin was created.');

            return self::SUCCESS;
        }

        $generated = false;
        if (! $password) {
            $password = Str::random(20);
            $generated = true;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'phone_number' => $phone,
            'department' => 'Administration',
            'hire_date' => now(),
        ]);
        $user->assignRole(Role::where('name', 'admin')->where('guard_name', 'web')->firstOrFail());

        $this->info("First admin created: {$email}");
        if ($generated) {
            $this->warn('Generated password (shown once, not stored anywhere else): '.$password);
        }

        return self::SUCCESS;
    }

    private function usersExist(): bool
    {
        try {
            return Schema::hasTable('users') && User::withTrashed()->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
