<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLAT-13: self-service signup and the trial funnel.
 *  - signups: one row per signup attempt, from "submitted" through email verification to a provisioned firm. The token is
 *    stored only as a hash; the chosen password only as a bcrypt hash, cleared once the firm exists.
 *  - tenants.trial_ends_at: when the firm's trial ends (null = not on a trial). Entitlements read it from the row the
 *    request already loaded, so an expired trial costs no extra query.
 *  - subscriptions.converted_at / expired_at: the trial -> paid funnel (when a trial firm chose a paid plan, or let the
 *    trial lapse).
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('control')->create('signups', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('firm_name');
            $table->string('slug', 63)->index();
            $table->string('name');
            $table->string('email')->index();
            $table->string('password')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('region', 32)->nullable();
            $table->string('currency', 3);
            $table->string('status', 16)->default('pending');   // pending | provisioning | ready | failed
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('error', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable();
        });

        Schema::connection('control')->table('subscriptions', function (Blueprint $table) {
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('expired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('signups');
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->dropColumn('trial_ends_at');
        });
        Schema::connection('control')->table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['converted_at', 'expired_at']);
        });
    }
};
