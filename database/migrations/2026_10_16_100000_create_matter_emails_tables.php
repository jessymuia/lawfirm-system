<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FUN-15: email filed to matters, by forwarding or copying it to the matter's own address, or by uploading an .eml file.
 *  - cases.email_token: the secret part of the matter's filing address (made on first use).
 *  - matter_emails: one row per message per matter (a message filed twice is kept once); the original .eml is kept
 *    on the firm's files disk as evidence, with its checksum.
 *  - matter_email_attachments: attachments become ordinary matter documents (searchable, FUN-8).
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->string('email_token', 32)->nullable()->unique();
        });

        Schema::create('matter_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->string('message_id', 255)->nullable();
            $table->string('thread_key', 64)->index();
            $table->string('from_email', 191)->nullable();
            $table->string('from_name', 191)->nullable();
            $table->text('to_list')->nullable();      // JSON [{email, name}]
            $table->text('cc_list')->nullable();
            $table->string('subject', 500)->default('');
            $table->timestamp('sent_at')->nullable();
            $table->longText('body_text')->nullable();
            $table->boolean('had_html')->default(false);
            $table->text('skipped_attachments')->nullable();   // JSON: attachments not filed (type or size), with why
            $table->string('raw_disk', 32);
            $table->string('raw_path', 500);
            $table->string('raw_sha256', 64);
            $table->unsignedInteger('raw_size');
            $table->string('source', 12);                       // inbound | upload
            $table->foreignId('filed_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['case_id', 'message_id']);
            $table->index(['case_id', 'sent_at']);
        });

        Schema::create('matter_email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_email_id')->constrained('matter_emails')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_email_attachments');
        Schema::dropIfExists('matter_emails');
        Schema::table('cases', function (Blueprint $t) {
            $t->dropUnique(['email_token']);   // first: SQLite cannot drop a column an index still names
            $t->dropColumn('email_token');
        });
    }
};
