<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ENG-21: queued firm-wide exports and their expiring download. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('status', 16)->default('queued');   // queued | running | ready | failed | expired
            $table->string('path')->nullable();                  // on the local disk, under the firm's prefix
            $table->unsignedBigInteger('size')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_exports');
    }
};
