<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ENG-22: direct uploads in flight. A row reserves an object key on the firm's disk for one person and one matter
 * until the browser has sent the file and the app has checked it (or the reservation expires and is pruned).
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('case_id')->constrained('cases');
            $table->foreignId('user_id')->constrained('users');
            $table->string('title', 255);
            $table->string('filename', 255);
            $table->string('mimetype', 191);
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('document_group_id')->nullable();
            $table->string('disk', 32);
            $table->string('path', 500);
            $table->timestamp('expires_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('documents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_uploads');
    }
};
