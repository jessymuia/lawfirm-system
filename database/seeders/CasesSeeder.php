<?php

namespace Database\Seeders;

use App\Models\Cases;
use Illuminate\Database\Seeder;

class CasesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        // create 200 cases
        Cases::factory(200)->create();
    }
}
