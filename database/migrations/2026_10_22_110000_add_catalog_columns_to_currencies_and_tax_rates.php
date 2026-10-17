<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEN-11: currencies and tax rates join the platform's system catalog (docs/SYSTEM_CATALOG.md). A currency's identity
 * is its ISO code; a tax rate's is its catalog_key. System rows are kept current by SystemCatalog::sync(); firm rows
 * have is_system false and a null key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $t) {
            $t->boolean('is_system')->default(false);
        });
        Schema::table('tax_rates', function (Blueprint $t) {
            $t->string('catalog_key', 64)->nullable()->unique();
            $t->boolean('is_system')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tax_rates', function (Blueprint $t) {
            $t->dropUnique(['catalog_key']);
            $t->dropColumn(['catalog_key', 'is_system']);
        });
        Schema::table('currencies', function (Blueprint $t) {
            $t->dropColumn('is_system');
        });
    }
};
