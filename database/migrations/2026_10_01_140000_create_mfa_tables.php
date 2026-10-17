<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SEC-12: per-user TOTP enrolment, short-lived login challenges, and the firm's security policy. Expand-only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_mfa', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->text('secret');                                  // encrypted with the app key
            $table->timestamp('confirmed_at')->nullable();           // null = set up but not yet proven; does not protect the account
            $table->unsignedBigInteger('last_used_step')->nullable(); // replay guard
            $table->text('recovery_codes')->nullable();               // JSON list of keyed hashes
            $table->timestamps();
        });

        Schema::create('mfa_challenges', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('firm_security_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('require_mfa')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_security_settings');
        Schema::dropIfExists('mfa_challenges');
        Schema::dropIfExists('user_mfa');
    }
};
