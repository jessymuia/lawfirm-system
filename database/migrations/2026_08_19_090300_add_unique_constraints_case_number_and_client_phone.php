<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DATA-5: no DB-level unique constraint on cases.case_number or client
     * phone_number; only checked at the app level on create, not update.
     *
     * The unique index is composite with `deleted_at` (Laravel's standard
     * soft-delete-safe unique pattern) so a soft-deleted record doesn't
     * permanently block reuse of its case number / phone number.
     *
     * Before adding the constraints we disambiguate any existing duplicate
     * *active* rows by suffixing all but the first occurrence, so the
     * migration never fails against existing production data. Disambiguated
     * rows are logged to storage/logs so they can be manually reviewed and
     * corrected — this indicates a real data-quality issue that predates
     * the constraint, not something the migration can resolve on its own.
     */
    public function up()
    {
        $this->deduplicate('cases', 'case_number');
        $this->deduplicate('clients', 'phone_number');

        Schema::table('cases', function (Blueprint $table) {
            $table->unique(['case_number', 'deleted_at']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->unique(['phone_number', 'deleted_at']);
        });
    }

    public function down()
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropUnique(['case_number', 'deleted_at']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['phone_number', 'deleted_at']);
        });
    }

    private function deduplicate(string $table, string $column): void
    {
        $duplicateValues = DB::table($table)
            ->whereNull('deleted_at')
            ->select($column)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($column);

        foreach ($duplicateValues as $value) {
            $rows = DB::table($table)
                ->whereNull('deleted_at')
                ->where($column, $value)
                ->orderBy('id')
                ->get(['id']);

            // Keep the first (oldest) row untouched; suffix the rest.
            foreach ($rows->skip(1) as $index => $row) {
                $newValue = $value.'-DUP'.($index + 1);

                DB::table($table)->where('id', $row->id)->update([$column => $newValue]);

                logger()->warning("Data-integrity migration: disambiguated duplicate {$table}.{$column}", [
                    'table' => $table,
                    'column' => $column,
                    'row_id' => $row->id,
                    'original_value' => $value,
                    'new_value' => $newValue,
                ]);
            }
        }
    }
};
