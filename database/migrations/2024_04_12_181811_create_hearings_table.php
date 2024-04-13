<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('hearings', function (Blueprint $table) {
            $table = \App\AppUtils\Utils::createDefaultTableColumns($table);
            $table->foreignId('case_id');
            $table->foreignId('court_id');
            $table->date('hearing_date');
            $table->string('hearing_type');
            $table->string('notes');
            $table->string('outcome');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('hearings');
    }
};
