<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-9 (expand step): a ULID `public_id` on every record that leaves the system through the public API, webhooks or a
 * URL someone can copy (clients, matters, hearings). Integer ids stay the internal primary and foreign keys.
 *
 * Nullable on purpose: existing rows are filled by App\Jobs\BackfillPublicIds (queued, chunked; DATA-13 forbids
 * backfills in migrations), which app:upgrade / tenants:migrate dispatch. New rows get one on create (HasPublicId).
 * The contract step (NOT NULL) ships in a later release, once every install has run the backfill.
 */
return new class extends Migration
{
    private const TABLES = ['clients', 'cases', 'hearings'];

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
