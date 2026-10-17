<?php

namespace App\Console\Commands;

use App\Platform\PlatformAudit;
use App\Platform\PlatformAuth;
use App\Platform\PlatformStaff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** PLAT-7: platform staff exist only in the control plane and are managed from the CLI, never through the web console. */
class PlatformStaffCreate extends Command
{
    protected $signature = 'platform:staff {action : create|role|disable|enable|mfa-reset} {email} {--name=} {--role=viewer : viewer|support|operator|admin} {--password= : 12+ chars; generated and shown once if omitted}';

    protected $description = 'Create or manage platform-staff accounts (control plane)';

    public function handle(PlatformAuth $auth): int
    {
        $email = mb_strtolower($this->argument('email'));
        $role = $this->option('role');
        if (! isset(PlatformStaff::ABILITIES[$role])) {
            $this->error('Role must be viewer, support, operator or admin.');

            return self::FAILURE;
        }

        switch ($this->argument('action')) {
            case 'create':
                if (PlatformStaff::where('email', $email)->exists()) {
                    $this->error('That email already has a platform account.');

                    return self::FAILURE;
                }
                $password = $this->option('password') ?: null;
                if ($password !== null && strlen($password) < 12) {
                    $this->error('Password must be at least 12 characters.');

                    return self::FAILURE;
                }
                $generated = $password === null;
                $password ??= Str::random(20);
                $staff = PlatformStaff::create(['name' => $this->option('name') ?: $email, 'email' => $email, 'password' => Hash::make($password), 'role' => $role]);
                PlatformAudit::record(null, 'staff.create', null, ['email' => $email, 'role' => $role], 'ok', 'via CLI', 'cli');
                $this->info("Created {$email} ({$role}).");
                if ($generated) {
                    $this->warn("Generated password (shown once): {$password}");
                }

                return self::SUCCESS;

            case 'mfa-reset':
                $staff = PlatformStaff::where('email', $email)->first();
                if (! $staff) {
                    $this->error('No such platform account.');

                    return self::FAILURE;
                }
                $auth->mfa()->disable($staff->id);
                $auth->revokeAll($staff);
                PlatformAudit::record(null, 'staff.mfa-reset', null, ['email' => $email], 'ok', 'via CLI', 'cli');
                $this->info("{$email}: two-factor reset; live sessions ended. They enrol again at next sign-in.");

                return self::SUCCESS;

            case 'role':
            case 'disable':
            case 'enable':
                $staff = PlatformStaff::where('email', $email)->first();
                if (! $staff) {
                    $this->error('No such platform account.');

                    return self::FAILURE;
                }
                if ($this->argument('action') === 'role') {
                    $staff->update(['role' => $role]);
                } else {
                    $staff->update(['active' => $this->argument('action') === 'enable']);
                    if (! $staff->active) {
                        $auth->revokeAll($staff);   // disabling ends live sessions immediately
                    }
                }
                PlatformAudit::record(null, 'staff.'.$this->argument('action'), null, ['email' => $email, 'role' => $staff->role], 'ok', 'via CLI', 'cli');
                $this->info("{$email}: {$this->argument('action')} done.");

                return self::SUCCESS;
        }

        $this->error('Unknown action.');

        return self::FAILURE;
    }
}
