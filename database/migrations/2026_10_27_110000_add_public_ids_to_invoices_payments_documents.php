<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-9 (expand step, second batch): a ULID `public_id` on invoices, payments and documents, the other records people
 * copy links to or hand to other systems. Same rules as the first batch (2026_10_05_100000): nullable, unique, filled for
 * existing rows by App\Jobs\BackfillPublicIds (a queued job, never this migration), set on create by HasPublicId.
 * The ledger triggers (DATA-10) guard financial columns only, so a public id can be added to an issued invoice or a
 * recorded payment without touching what the books say.
 */
return new class extends Migration
{
    private const TABLES = ['invoices', 'payments', 'documents'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['public_id']);
                $table->dropColumn('public_id');
            });
        }
    }
};
