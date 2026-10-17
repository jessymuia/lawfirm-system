<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FUN-8: the text inside each document (from the file itself, or OCR for scans and photos), for full-text search.
 * One row per document version; a full-text index on MySQL/MariaDB (other databases fall back to a slower scan).
 * Additive only. Existing documents are filled by the queued BackfillDocumentText job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_texts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('documents')->cascadeOnDelete();
            $table->string('state', 16)->default('pending');   // pending | done | empty | unsupported | failed
            $table->string('method', 16)->nullable();           // text | pdf | docx | xlsx | ocr
            $table->longText('content')->nullable();
            $table->unsignedInteger('chars')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();
            $table->index('state');
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('document_texts', fn (Blueprint $table) => $table->fullText('content'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_texts');
    }
};
