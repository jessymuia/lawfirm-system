<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEN-2: in multi-tenant mode the `database` queue is the platform's, not a firm's: jobs from every firm, and fleet
 * jobs from the platform console, wait here, in their firm's lane (`queue` = tenants.queue), and failed jobs are kept
 * here too. A firm's database never holds another firm's work. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('control')->create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::connection('control')->create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('failed_jobs');
        Schema::connection('control')->dropIfExists('jobs');
    }
};
