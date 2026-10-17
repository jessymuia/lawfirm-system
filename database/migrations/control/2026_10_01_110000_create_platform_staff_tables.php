<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLAT-7: platform staff are NOT tenant users. They live in the control plane, authenticate with their own bearer tokens
 * and every action they take is written to platform_audit_log.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->create('platform_staff', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 16)->default('viewer');         // viewer | operator | admin
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('failed_logins')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('control')->create('platform_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('platform_staff')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();               // sha256; the token itself is shown once and never stored
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::connection('control')->create('platform_audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('staff_email')->nullable();
            $table->string('action', 64);
            $table->string('tenant_slug', 63)->nullable();
            $table->text('params')->nullable();                     // JSON; never contains secrets
            $table->string('result', 16);                           // ok | denied | failed
            $table->string('detail', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_slug', 'created_at']);
            $table->index(['staff_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('platform_audit_log');
        Schema::connection('control')->dropIfExists('platform_tokens');
        Schema::connection('control')->dropIfExists('platform_staff');
    }
};
