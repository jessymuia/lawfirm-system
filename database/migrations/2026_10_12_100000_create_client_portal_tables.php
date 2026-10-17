<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FUN-10: the client portal. Clients are not users of the firm: they sign in with a one-time code sent to the phone on
 * their client record, and see only their own matters.
 *  - portal_accounts: which clients may use the portal (one row per client, switched on by the firm).
 *  - portal_login_codes: one-time sign-in codes (hashed, short-lived, limited tries).
 *  - portal_sessions: signed-in portal sessions (only the token's SHA-256 is kept).
 *  - portal_documents: documents the firm shared with the client, and documents the client sent (direction).
 *  - portal_messages: secure messages between the client and the firm on a matter.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained('clients');
            $table->boolean('enabled')->default(true);
            $table->foreignId('invited_by')->nullable()->constrained('users');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('portal_login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'used_at']);
        });

        Schema::create('portal_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('portal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->string('direction', 12);                      // to_client | from_client
            $table->foreignId('shared_by')->nullable()->constrained('users');   // null when the client sent it
            $table->timestamp('first_viewed_at')->nullable();      // when the client first opened a shared document
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['case_id', 'direction']);
        });

        Schema::create('portal_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->string('direction', 12);                      // to_client | from_client
            $table->foreignId('user_id')->nullable()->constrained('users');     // the firm's sender; null from the client
            $table->text('body');
            $table->timestamp('read_at')->nullable();              // read by the other side
            $table->timestamps();
            $table->index(['case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_messages');
        Schema::dropIfExists('portal_documents');
        Schema::dropIfExists('portal_sessions');
        Schema::dropIfExists('portal_login_codes');
        Schema::dropIfExists('portal_accounts');
    }
};
