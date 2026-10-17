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
        Schema::create('cases', function (Blueprint $table) {
            $table = Utils::createDefaultTableColumns($table);
            $table->string('case_number');
            $table->string('description');
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('assigned_to')->constrained('users');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('case_type');
            $table->string('police_station');
            $table->foreignId('court_id')->constrained('courts');
            $table->string('opposing_party');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('cases');
    }
};
