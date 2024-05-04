<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        DB::beginTransaction();

        try {
            // initialize the database for usage
//        $this->call([
//            CourtTypeSeeder::class,
//            ExpenseCategorySeeder::class,
//            // TODO: Add Court Seeder equivalent
//            HearingTypeSeeder::class,
//        ]);

            // initialize the database for demo purposes
            $this->call([
                UserSeeder::class,
                ClientSeeder::class,
                ExpenseCategorySeeder::class,
                CourtTypeSeeder::class,
                CourtSeeder::class,
                HearingTypeSeeder::class,
                CasesSeeder::class,
                HearingSeeder::class,
                TaskSeeder::class,
            ]);
            DB::commit();
        }catch (\Exception $exception){
            DB::rollBack();
            Log::error("Database seeding failed");
            Log::error($exception->getMessage());
        }
    }
}
