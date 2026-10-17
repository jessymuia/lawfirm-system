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
        Schema::create('tasks', function (Blueprint $table) {
            $table = Utils::createDefaultTableColumns($table);
            $table->string('description');
            $table->string('title');
            $table->foreignId('assigned_to')->constrained('users');
            $table->date('due_date');
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');
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
        Schema::dropIfExists('tasks');
    }
};
