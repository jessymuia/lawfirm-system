<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-13: retention covers more record classes than documents (docs/COMPLIANCE.md), so a disposal record names the kind
 * of record and its id. Document disposals keep filling document_id as before; existing rows are read as documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retention_disposals', function (Blueprint $t) {
            $t->string('record_type', 40)->nullable();
            $t->unsignedBigInteger('record_id')->nullable();
            // migration-lint: allow change-type -- only relaxes NOT NULL to NULL (the previous release always writes document_id, so it keeps working); retention_disposals is a small append-only log, so the rewrite is brief
            $t->unsignedBigInteger('document_id')->nullable()->change();
            $t->index(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::table('retention_disposals', function (Blueprint $t) {
            $t->dropIndex(['record_type', 'record_id']);
            $t->dropColumn(['record_type', 'record_id']);
            // migration-lint: allow change-type -- the rollback of up()'s relaxation; only run when every disposal row is a document
            $t->unsignedBigInteger('document_id')->nullable(false)->change();
        });
    }
};
