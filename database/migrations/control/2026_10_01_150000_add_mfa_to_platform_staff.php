<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SEC-12: second factor for platform staff (control plane). Expand-only. */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->create('platform_staff_mfa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->unique()->constrained('platform_staff')->cascadeOnDelete();
            $table->text('secret');
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->text('recovery_codes')->nullable();
            $table->timestamps();
        });
        Schema::connection('control')->create('platform_mfa_challenges', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('staff_id')->constrained('platform_staff')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::connection('control')->table('platform_tokens', function (Blueprint $table) {
            $table->boolean('enroll_only')->default(false);   // a token that may only set up two-factor
        });
    }

    public function down(): void
    {
        Schema::connection('control')->table('platform_tokens', fn (Blueprint $t) => $t->dropColumn('enroll_only'));
        Schema::connection('control')->dropIfExists('platform_mfa_challenges');
        Schema::connection('control')->dropIfExists('platform_staff_mfa');
    }
};
