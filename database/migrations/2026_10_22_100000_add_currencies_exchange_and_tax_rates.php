<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-14: money in more than one currency, and tax as rules rather than typed-in amounts.
 *
 *  - currencies: the currencies the firm invoices in (ISO 4217), with their minor units.
 *  - exchange_rates: how many units of the firm's base currency one unit of a currency was worth from a date.
 *  - tax_rates: the firm's tax rules (VAT 16%, …) per jurisdiction; archived, never deleted, since lines point at them.
 *  - invoices: the exchange rate fixed when the invoice is issued, and its totals in the base currency at that rate.
 *  - invoice_items: the tax rate a line was charged under, and its percentage at the time.
 *
 * Expand only: every new column is nullable, and issued invoices are filled by the queued BackfillInvoiceFx.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $t) {
            $t->id();
            $t->char('code', 3)->unique();
            $t->string('name', 100);
            $t->unsignedTinyInteger('minor_units')->default(2);
            $t->string('symbol', 10)->nullable();
            $t->boolean('enabled')->default(true);
            $t->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $t) {
            $t->id();
            $t->char('currency', 3);
            $t->char('base_currency', 3);
            $t->decimal('rate', 18, 8);
            $t->date('effective_on');
            $t->string('source', 30)->default('manual');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['currency', 'base_currency', 'effective_on']);
        });

        Schema::create('tax_rates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->decimal('rate', 7, 4);
            $t->string('jurisdiction', 60)->nullable();
            $t->boolean('is_default')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::table('invoices', function (Blueprint $t) {
            $t->char('base_currency', 3)->nullable();
            $t->decimal('fx_rate', 18, 8)->nullable();
            $t->date('fx_rate_on')->nullable();
            $t->decimal('base_subtotal', 14, 2)->nullable();
            $t->decimal('base_total_amount', 14, 2)->nullable();
        });

        Schema::table('invoice_items', function (Blueprint $t) {
            // no foreign key: on SQLite adding one rebuilds the table and drops its DATA-10 ledger triggers; tax rates are
            // never deleted (only archived), and the rate is checked when the line is written
            $t->unsignedBigInteger('tax_rate_id')->nullable()->index();
            $t->decimal('tax_rate_percent', 7, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $t) {
            $t->dropIndex(['tax_rate_id']);
            $t->dropColumn(['tax_rate_id', 'tax_rate_percent']);
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn(['base_currency', 'fx_rate', 'fx_rate_on', 'base_subtotal', 'base_total_amount']);
        });
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
