<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class E2ETestSeeder extends Seeder
{
    /**
     * Playwright needs deterministic, known-credential users —
     * factory-random users/roles (as UserSeeder produces) can't be
     * targeted reliably from a test spec. This seeder is idempotent
     * (firstOrCreate) so it's safe to run against an already-seeded
     * database in CI without creating duplicates on repeated runs.
     *
     * Run this AFTER RoleSeeder (roles/permissions must already exist).
     */
    public function run()
    {
        $this->makeUser('e2e-admin@lawfirm.test', 'admin');
        $this->makeUser('e2e-advocate@lawfirm.test', 'advocate');
        // 'clerk' deliberately has no case/hearing/invoice/expense/document
        // access — this is the negative-path user for the RBAC E2E suite.
        $this->makeUser('e2e-clerk@lawfirm.test', 'clerk');
        // SEC-12: enrols in two-factor during its spec, so it is nobody else's login.
        $this->makeUser('e2e-mfa@lawfirm.test', 'clerk');
    }

    private function makeUser(string $email, string $roleName): User
    {
        $user = User::withTrashed()->firstOrCreate(
            ['email' => $email],
            [
                'name' => ucfirst($roleName).' E2E Test User',
                'password' => Hash::make('E2ePassword!123'),
                'phone_number' => '0700000000',
                'department' => 'QA',
                'hire_date' => now(),
            ]
        );

        // firstOrCreate against a soft-deleted row returns the trashed
        // record without restoring it — make sure a re-run of this seeder
        // always leaves the E2E user active and usable.
        if ($user->trashed()) {
            $user->restore();
        }

        $role = Role::findOrCreate($roleName, 'web');
        $user->syncRoles([$role->name]);

        return $user;
    }
}
