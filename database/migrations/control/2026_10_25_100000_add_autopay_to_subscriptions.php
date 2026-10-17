<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLAT-4: a firm's saved card (a Stripe customer and payment method, never the card itself) and automatic charging of
 * its subscription invoices, with the attempts made per invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('control')->table('subscriptions', function (Blueprint $t) {
            $t->string('stripe_customer_id', 64)->nullable();
            $t->string('stripe_payment_method_id', 64)->nullable();
            $t->string('card_label', 64)->nullable();          // e.g. "visa •••• 4242, expires 12/2030": shown, never the number
            $t->boolean('autopay')->default(false);
        });
        Schema::connection('control')->table('billing_invoices', function (Blueprint $t) {
            $t->unsignedSmallInteger('autopay_attempts')->default(0);
            $t->timestamp('autopay_last_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->table('billing_invoices', function (Blueprint $t) {
            $t->dropColumn(['autopay_attempts', 'autopay_last_at']);
        });
        Schema::connection('control')->table('subscriptions', function (Blueprint $t) {
            $t->dropColumn(['stripe_customer_id', 'stripe_payment_method_id', 'card_label', 'autopay']);
        });
    }
};
