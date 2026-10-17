<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEN-11: platform-owned reference rows (courts, court types, hearing types)
 * live next to firm-created rows in the same table, told apart by is_system.
 *
 *   catalog_key  stable identity of a system row across every firm and release
 *   is_system    true = owned by the platform, never edited or deleted by a firm
 *   alias        a firm's local display name for a system row (the overlay)
 *
 * Additive only. Existing rows are adopted into the catalog by
 * App\Catalog\SystemCatalog::sync(), which app:upgrade / tenants:migrate run
 * after migrating, not by this migration.
 */
return new class extends Migration
{
    private const TABLES = ['court_types', 'courts', 'hearing_types'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('catalog_key', 64)->nullable()->unique();
                $table->boolean('is_system')->default(false);
                $table->string('alias')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['catalog_key']);
                $table->dropColumn(['catalog_key', 'is_system', 'alias']);
            });
        }
    }
};
