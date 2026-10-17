<?php

namespace Database\Seeders;

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
                RoleSeeder::class,
                E2ETestSeeder::class,
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
        } catch (\Exception $exception) {
            DB::rollBack();
            Log::error('Database seeding failed');
            Log::error($exception->getMessage());

            // Bug fix: this previously only logged the failure and let
            // run() return normally, so `php artisan db:seed` reported
            // success even when seeding actually failed — silently
            // leaving CI (or any environment) with an incomplete
            // database and no clear signal why later steps fail.
            throw $exception;
        }
    }
}
