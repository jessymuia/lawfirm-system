<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * A new firm's starter expense categories. These are the firm's own rows (TEN-11:
 * tenant overlay, not system catalog): firms rename and delete them freely, and
 * upgrades never touch them.
 */
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
            'Operational Expenses',
            'Personnel Expenses',
            'Marketing Expenses',
            'Travel & Entertainment Expenses',
            'Technology and Software Expenses',
            'Litigation Expenses',
        ];

        foreach ($expenseCategories as $category) {
            ExpenseCategory::firstOrCreate([
                'name' => $category,
            ]);
        }
    }
}
