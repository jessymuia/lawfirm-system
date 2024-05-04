<?php

namespace Database\Seeders;

use App\Models\CourtType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CourtTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        // create the various court types
        CourtType::create(['name' => 'Supreme Court']);
        CourtType::create(['name' => 'Court of Appeal']);
        CourtType::create(['name' => 'High Court']);
        CourtType::create(['name' => 'Magistrate Court']);
        CourtType::create(['name' => 'Family Court']);
        CourtType::create(['name' => 'Small Claims Court']);
        CourtType::create(['name' => 'Juvenile Court']);
    }
}
