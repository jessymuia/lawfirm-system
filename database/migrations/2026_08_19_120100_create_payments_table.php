<?php

use App\AppUtils\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FUN-2: no payments/receipts existed — invoices had no way to record
     * that money was actually received against them.
     */
    public function up()
    {
        Schema::create('payments', function (Blueprint $table) {
            $table = Utils::createDefaultTableColumns($table);
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->string('receipt_number')->unique();
            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->enum('method', ['cash', 'bank_transfer', 'mobile_money', 'cheque', 'card', 'other']);
            $table->string('reference_number')->nullable();
            $table->foreignId('received_by')->constrained('users');
            $table->text('notes')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('payments');
    }
};
