<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SEC-12: the firm's OpenID Connect provider, in-flight sign-ins, and which IdP subject belongs to which user. Expand-only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_providers', function (Blueprint $table) {
            $table->id();
            $table->string('issuer', 500);
            $table->string('client_id', 255);
            $table->text('client_secret');                           // encrypted with the app key; never returned by the API
            $table->boolean('enabled')->default(false);
            $table->boolean('enforce')->default(false);              // password sign-in refused except for security administrators (break-glass)
            $table->boolean('idp_enforces_mfa')->default(false);     // the firm attests its IdP requires a second factor
            $table->text('allowed_email_domains')->nullable();       // JSON list; empty = any verified email that matches a user
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('sso_states', function (Blueprint $table) {
            $table->id();
            $table->char('state_hash', 64)->unique();
            $table->string('nonce', 64);
            $table->text('verifier');                                // PKCE, encrypted
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('sso_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('issuer', 500);
            $table->string('subject', 255);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['issuer', 'subject']);
            $table->unique(['user_id', 'issuer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_links');
        Schema::dropIfExists('sso_states');
        Schema::dropIfExists('sso_providers');
    }
};
