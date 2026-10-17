<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-12: SAML 2.0 sign-in next to OpenID Connect, and SCIM 2.0 provisioning tokens. Expand-only.
 * A SAML provider reuses `issuer` for the IdP's entity id; `client_id`/`client_secret` hold placeholders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sso_providers', function (Blueprint $table) {
            $table->string('protocol', 8)->default('oidc')->after('id');
            $table->string('saml_sso_url', 500)->nullable();
            $table->text('saml_certificate')->nullable();            // one or more PEM certificates (a key roll-over has two)
        });

        Schema::create('scim_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->char('prefix', 8)->unique();
            $table->char('token_hash', 64);
            $table->unsignedBigInteger('default_role_id')->nullable();   // role given to people the IdP creates
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scim_tokens');
        Schema::table('sso_providers', function (Blueprint $table) {
            $table->dropColumn(['protocol', 'saml_sso_url', 'saml_certificate']);
        });
    }
};
