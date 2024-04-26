<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
        // generate random 15 users
        \App\Models\User::factory(15)->create();

        // assign the users roles
        $users = \App\Models\User::all();

        foreach($users as $user){
            $user->assignRole(Role::all()->pluck('id')->random(1)->first());
        }
    }
}
