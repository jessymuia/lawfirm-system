<?php

use App\AppUtils\Utils;
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
            $table = Utils::createDefaultTableColumns($table);
            $table->foreignId('case_id')->constrained('cases');
            $table->foreignId('court_id')->constrained('courts');
            $table->date('hearing_date');
            $table->integer('hearing_type');
            $table->text('notes');
            $table->text('outcome');
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
