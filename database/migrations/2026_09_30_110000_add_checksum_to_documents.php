<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEN-12: SHA-256 recorded at write time so integrity checking is a permanent
 * feature, not migration-only tooling. Nullable: legacy rows are backfilled
 * by a queued job / migration:document-checksums, never by this migration
 * (DATA-13: backfills are not migrations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->char('checksum', 64)->nullable()->after('filesize');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('checksum');
        });
    }
};
