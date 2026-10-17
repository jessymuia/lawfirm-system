<?php

use App\AppUtils\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FUN-12: trust three-way reconciliation and retainer replenishment.
 *
 * The trust ledger (trust_transactions) is append-only, enforced by triggers (DATA-10), so nothing here updates it:
 * which ledger entries the bank has cleared lives in trust_transaction_clearances, and a reconciliation is its own
 * record, frozen at sign-off with a per-client snapshot. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trust_reconciliations', function (Blueprint $table) {
            Utils::createDefaultTableColumns($table);
            $table->date('period_end');
            $table->string('statement_reference', 120)->nullable();
            $table->decimal('bank_balance', 14, 2);
            $table->decimal('deposits_in_transit', 14, 2)->default(0);
            $table->decimal('outstanding_disbursements', 14, 2)->default(0);
            $table->decimal('adjustments', 14, 2)->default(0);
            $table->decimal('adjusted_bank_balance', 14, 2)->default(0);
            $table->decimal('ledger_balance', 14, 2)->default(0);
            $table->decimal('client_ledgers_total', 14, 2)->default(0);
            $table->boolean('balanced')->default(false);
            $table->string('state', 16)->default('draft');      // draft | signed_off
            $table->boolean('has_exceptions')->default(false);
            $table->string('exception_note', 2000)->nullable();
            $table->json('client_snapshot')->nullable();          // frozen at sign-off: [{client_id, name, balance}]
            $table->json('outstanding_snapshot')->nullable();     // frozen at sign-off: the entries still outstanding
            $table->foreignId('prepared_by')->nullable()->constrained('users');
            $table->foreignId('signed_off_by')->nullable()->constrained('users');
            $table->timestamp('signed_off_at')->nullable();
            $table->index('period_end');
        });

        Schema::create('trust_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trust_reconciliation_id')->constrained('trust_reconciliations')->cascadeOnDelete();
            $table->string('kind', 32);                            // bank_adjustment (bank charges, interest not yet booked…)
            $table->decimal('amount', 14, 2);                      // signed: + adds to the bank side, - subtracts
            $table->string('description', 500);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('trust_transaction_clearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trust_transaction_id')->unique()->constrained('trust_transactions');
            $table->foreignId('trust_reconciliation_id')->constrained('trust_reconciliations')->cascadeOnDelete();
            $table->date('cleared_on');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('trust_retainer_policies', function (Blueprint $table) {
            Utils::createDefaultTableColumns($table);
            $table->foreignId('client_id')->unique()->constrained('clients');
            $table->decimal('minimum_balance', 14, 2);
            $table->decimal('target_balance', 14, 2);
        });

        Schema::create('trust_replenishment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->decimal('balance', 14, 2);
            $table->decimal('amount_requested', 14, 2);
            $table->string('channel', 16);                          // sms
            $table->string('message', 500);
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_replenishment_requests');
        Schema::dropIfExists('trust_retainer_policies');
        Schema::dropIfExists('trust_transaction_clearances');
        Schema::dropIfExists('trust_reconciliation_items');
        Schema::dropIfExists('trust_reconciliations');
    }
};
