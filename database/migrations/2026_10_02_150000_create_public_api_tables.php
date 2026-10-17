<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PLAT-11: scoped API keys, webhook endpoints and the delivery log (new tables only). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('prefix', 16)->unique();
            $t->string('key_hash', 64);
            $t->text('scopes');                              // JSON list
            $t->unsignedBigInteger('user_id');               // the identity the key acts as (matter access and walls apply to them)
            $t->unsignedInteger('rate_limit')->nullable();   // per minute; null = default
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->unsignedBigInteger('revoked_by')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('webhook_endpoints', function (Blueprint $t) {
            $t->id();
            $t->string('url', 2000);
            $t->string('description')->nullable();
            $t->text('secret');                              // encrypted with the app key
            $t->text('events');                              // JSON list of event names, or ["*"]
            $t->boolean('active')->default(true);
            $t->unsignedInteger('consecutive_dead')->default(0);
            $t->string('disabled_reason')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('endpoint_id')->index();
            $t->string('event_id', 40);
            $t->string('event', 40);
            $t->longText('payload');
            $t->string('status', 12)->default('pending');    // pending | sending | retry | delivered | dead
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('locked_until')->nullable();
            $t->unsignedSmallInteger('last_status_code')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'next_attempt_at']);
            $t->index(['endpoint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['webhook_deliveries', 'webhook_endpoints', 'api_keys'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
