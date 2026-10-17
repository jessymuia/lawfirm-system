<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        Schema::table('tasks', function (Blueprint $table) {
            // Change priority column type to string with default
            $table->string('priority')->default('medium')->change();

            // Add CHECK constraint (MySQL/MariaDB only — SQLite cannot ALTER TABLE ADD CONSTRAINT)
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $constraints = DB::select("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_NAME = 'tasks' AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'tasks_priority_enum'");
                if (empty($constraints)) {
                    DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_enum CHECK (priority IN ('high','medium','low'))");
                }
            }

            // Add task_status column if it doesn't exist
            if (! Schema::hasColumn('tasks', 'task_status')) {
                $table->enum('task_status', ['pending', 'in_progress', 'completed', 'overdue'])->default('pending');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Drop the CHECK constraint if it exists (MySQL/MariaDB only)
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $constraints = DB::select("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_NAME = 'tasks' AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'tasks_priority_enum'");
                if (! empty($constraints)) {
                    DB::statement('ALTER TABLE tasks DROP CHECK tasks_priority_enum');
                }
            }

            // Drop task_status column if it exists
            if (Schema::hasColumn('tasks', 'task_status')) {
                $table->dropColumn('task_status');
            }
        });
    }
};
