<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ENG-23: a cell's read replica. Reports and the dashboard of the firms on the cell read from it (their own database
 * there, the cell's credentials). Null = no replica: they read from the cell's primary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('control')->table('cells', function (Blueprint $table) {
            $table->string('replica_host')->nullable();
            $table->unsignedInteger('replica_port')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->table('cells', function (Blueprint $table) {
            $table->dropColumn(['replica_host', 'replica_port']);
        });
    }
};
