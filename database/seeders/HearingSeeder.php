<?php

namespace Database\Seeders;

use App\Models\Hearing;
use Illuminate\Database\Seeder;

class HearingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        // create init hearings
        Hearing::factory(100)->create();
    }
}
