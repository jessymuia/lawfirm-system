<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FUN-11: clients paying the firm's invoices online (M-Pesa STK push, card via Stripe Checkout).
 *  - invoice_payment_links: an expiring secret link to one invoice's payment page (only the token's SHA-256 is kept).
 *  - online_payment_attempts: each try, its provider reference, and the payment it produced. The provider's callback
 *    records the payment once (the unique provider_reference and the attempt state make replays harmless).
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('online_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->foreignId('invoice_payment_link_id')->nullable()->constrained('invoice_payment_links');
            $table->string('provider', 16);                         // mpesa | stripe
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('state', 16)->default('pending');        // pending | succeeded | failed
            $table->string('provider_reference', 191)->nullable()->unique();   // CheckoutRequestID / Checkout Session id
            $table->string('callback_token_hash', 64)->nullable()->index();   // M-Pesa: the secret in the callback URL
            $table->string('receipt', 191)->nullable();              // MpesaReceiptNumber / PaymentIntent id
            $table->string('phone', 32)->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments');
            $table->timestamps();
            $table->index(['invoice_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payment_attempts');
        Schema::dropIfExists('invoice_payment_links');
    }
};
