<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        $expenseCategories = [
            "Operational Expenses",
            "Personnel Expenses",
            "Marketing Expenses",
            "Travel & Entertainment Expenses",
            "Technology and Software Expenses",
            "Litigation Expenses",
        ];

        foreach ($expenseCategories as $category) {
            ExpenseCategory::create([
                'name' => $category
            ]);
        }
    }
}
