<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UI-16: moving between firms without signing in again. A token is issued by the firm the person is in, for one
 * other firm of theirs, and redeemed once on that firm's host within a minute. Only its hash is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('control')->create('firm_switch_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('identity_id')->constrained('identities')->cascadeOnDelete();
            $table->foreignId('from_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('to_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('firm_switch_tokens');
    }
};
