<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-8: trust-ledger entries get a gap-free number from the locked sequence service (TRT-000123), like invoices and
 * receipts. `reference_number` stays what staff type (cheque or bank reference). Expand step: nullable, older rows keep
 * none (the ledger is append-only, so they are not rewritten).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trust_transactions', function (Blueprint $table) {
            $table->string('ledger_number', 32)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('trust_transactions', function (Blueprint $table) {
            $table->dropUnique(['ledger_number']);
            $table->dropColumn('ledger_number');
        });
    }
};
