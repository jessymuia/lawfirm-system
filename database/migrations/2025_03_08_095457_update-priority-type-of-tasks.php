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
        //
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('priority')->change()->default('medium');
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_enum CHECK (priority IN ('high', 'medium', 'low')) NOT VALID");
            $table->enum('task_status', ['pending', 'in_progress', 'completed', 'overdue'])->default('pending');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
        Schema::table('tasks', function (Blueprint $table) {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE tasks DROP CONSTRAINT tasks_priority_enum");
            $table->dropColumn('task_status');
        });
    }
};
