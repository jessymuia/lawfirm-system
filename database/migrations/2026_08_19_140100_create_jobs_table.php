<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENG-3: QUEUE_CONNECTION was 'sync', meaning every queued job (SMS,
     * mail — see SEC-9's SendSmsJob and ENG-5's re-enabled task-assignment
     * mail) actually ran synchronously inline, blocking the HTTP request
     * on a third-party API/SMTP call. The 'database' driver needs this
     * table; config/queue.php already expects it (table => 'jobs') but it
     * was never created.
     */
    public function up()
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('jobs');
    }
};
