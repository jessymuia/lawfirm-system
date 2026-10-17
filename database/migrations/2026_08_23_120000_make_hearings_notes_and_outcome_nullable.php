<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HearingController validates notes/outcome as `nullable` and inserts
     * whatever the request sent (possibly null), but the columns were
     * created NOT NULL — so creating a hearing without notes failed at the
     * database layer with a 500. Outcome in particular cannot be known at
     * scheduling time; both must be nullable.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('hearings', function (Blueprint $table) {
            $table->text('notes')->nullable()->change();
            $table->text('outcome')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('hearings', function (Blueprint $table) {
            $table->text('notes')->nullable(false)->change();
            $table->text('outcome')->nullable(false)->change();
        });
    }
};
