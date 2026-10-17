<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATA-11: one row per archived month of the audit trail (the file lives in object storage). Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_archives', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7)->unique();          // YYYY-MM
            $table->unsignedInteger('rows');
            $table->unsignedBigInteger('first_id')->nullable();
            $table->unsignedBigInteger('last_id')->nullable();
            $table->string('disk', 32);
            $table->string('path');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->timestamp('archived_at');
            $table->timestamp('pruned_at')->nullable();   // the file was deleted at the end of retention
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_archives');
    }
};
