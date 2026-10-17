<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FUN-14: document assembly and e-signature.
 *  - document_templates: the firm's Word templates and the fields they use.
 *  - signature_requests: a document sent for signature, with the SHA-256 of the exact file that is being signed.
 *  - signature_signers: each signer's secret link (hashed), what they did, and the evidence of it.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('category', 64)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('disk', 32);
            $table->string('path', 500);
            $table->string('filename', 255);
            $table->text('fields');                          // JSON list of the fields it uses
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('signature_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents');
            $table->string('title', 255);
            $table->string('message', 1000)->nullable();
            $table->string('document_sha256', 64);
            $table->string('state', 16)->default('pending');      // pending | completed | declined | cancelled | expired
            $table->boolean('in_order')->default(false);          // signers sign one after another
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('certificate_document_id')->nullable()->constrained('documents');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['case_id', 'state']);
        });

        Schema::create('signature_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signature_request_id')->constrained('signature_requests')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('name', 191);
            $table->string('email', 191)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('state', 16)->default('pending');      // pending | viewed | signed | declined
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_name', 191)->nullable();
            $table->longText('signature_image')->nullable();       // PNG data URL of the drawn signature
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_signers');
        Schema::dropIfExists('signature_requests');
        Schema::dropIfExists('document_templates');
    }
};
