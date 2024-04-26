<?php

namespace Database\Seeders;

use App\Models\Court;
use Database\Factories\CourtFactory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CourtSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        // create 100 courts
        Court::factory(100)->create();
    }
}
