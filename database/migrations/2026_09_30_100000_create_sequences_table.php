<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-8: per-tenant locked sequence counters (invoice, receipt, trust
 * ledger numbers). Additive only, so safe under the expand/contract policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);           // e.g. 'invoice'
            $table->string('scope', 16)->default(''); // '' or a year like '2026'
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['name', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
