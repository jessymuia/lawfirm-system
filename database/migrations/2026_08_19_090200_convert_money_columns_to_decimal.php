<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DATA-4: money stored as `double` causes floating-point rounding on
     * financial data. Convert to DECIMAL(12,2), which stores exact values.
     *
     * Existing double values are cast to decimal in place — MySQL/MariaDB
     * handles this conversion safely for values already representing money
     * (at most a few decimal places), so no data migration/backfill step
     * is required beyond the column type change itself.
     */
    public function up()
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->change();
            $table->decimal('rate', 12, 2)->change();
            $table->decimal('tax', 12, 2)->change();
            $table->decimal('total_amount', 12, 2)->change();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->change();
        });
    }

    public function down()
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->double('quantity')->change();
            $table->double('rate')->change();
            $table->double('tax')->change();
            $table->double('total_amount')->change();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->double('amount')->change();
        });
    }
};
