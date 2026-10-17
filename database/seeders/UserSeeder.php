<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Generate random 15 users
        $users = User::factory(15)->create();

        // Assign random roles only to the newly created users.
        // This prevents overwriting roles assigned to E2E test users.
        foreach ($users as $user) {
            $user->assignRole(
                Role::all()->pluck('id')->random(1)->first()
            );
        }
    }
}
