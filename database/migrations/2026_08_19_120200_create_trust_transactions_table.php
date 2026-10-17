<?php

use App\AppUtils\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FUN-2: no trust (client) accounting existed. Client funds held in
     * trust must be tracked separately from firm operating funds and must
     * never be commingled or allowed to go negative for a given client —
     * this is a standard legal/ethical requirement (IOLTA-style trust
     * accounting), not just a bookkeeping nicety.
     *
     * This ledger is append-only by design: transactions are never edited
     * or hard-deleted after posting (only voided via a reversing entry),
     * so `balance_after` recorded at write time is always a trustworthy
     * historical snapshot, and the audit trail is never rewritten.
     */
    public function up()
    {
        Schema::create('trust_transactions', function (Blueprint $table) {
            $table = Utils::createDefaultTableColumns($table);
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('case_id')->nullable()->constrained('cases');
            $table->enum('type', ['deposit', 'disbursement']);
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('description');
            $table->string('reference_number')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('trust_transactions');
    }
};
