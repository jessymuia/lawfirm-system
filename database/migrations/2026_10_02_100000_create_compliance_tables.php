<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SEC-13: legal holds, retention policies, disposal records and data-subject requests. All new tables (expand-only). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_holds', function (Blueprint $t) {
            $t->id();
            $t->string('scope_type', 16);            // client | case
            $t->unsignedBigInteger('scope_id');
            $t->text('reason');
            $t->unsignedBigInteger('placed_by')->nullable();
            $t->timestamp('placed_at')->nullable();
            $t->unsignedBigInteger('released_by')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->text('release_reason')->nullable();
            $t->timestamps();
            $t->index(['scope_type', 'scope_id', 'released_at']);
        });

        Schema::create('retention_policies', function (Blueprint $t) {
            $t->id();
            $t->string('record_class', 40)->unique();
            $t->unsignedSmallInteger('retain_years');
            $t->boolean('auto_purge')->default(false);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('retention_disposals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('document_id')->index();
            $t->unsignedBigInteger('case_id')->nullable();
            $t->string('record_class', 40);
            $t->unsignedSmallInteger('retain_years');
            $t->boolean('file_deleted');
            $t->unsignedBigInteger('disposed_by')->nullable();
            $t->timestamp('disposed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('dsar_requests', function (Blueprint $t) {
            $t->id();
            $t->string('type', 16);                   // access | erasure
            $t->unsignedBigInteger('client_id')->index();
            $t->string('requester_name');
            $t->string('requester_email')->nullable();
            $t->string('status', 24)->default('pending_verification');   // pending_verification | verified | completed | blocked | rejected
            $t->date('received_at');
            $t->date('due_at');
            $t->string('verification_method', 24)->nullable();
            $t->text('verification_note')->nullable();
            $t->unsignedBigInteger('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->unsignedBigInteger('handled_by')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->text('outcome')->nullable();          // JSON: what was done / retained and why
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['dsar_requests', 'retention_disposals', 'retention_policies', 'legal_holds'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
